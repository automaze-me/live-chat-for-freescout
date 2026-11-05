/* Live Chat for FreeScout - chat panel. Plain JS, no dependencies. */
(function () {
	'use strict';

	var cfg = window.LCFF_CONFIG;
	if (!cfg || window.LCFF) {
		return;
	}
	var strings = cfg.strings || {};
	/* English text of every string the panel reads; a cached page's LCFF_CONFIG.strings may lack keys. */
	var DEFAULT_STRINGS = {
		title: 'Chat with us',
		placeholder: 'Type a message…',
		send: 'Send',
		close: 'Close chat',
		retry: 'Couldn\'t send. Tap to retry.',
		unsent: 'This message couldn\'t be sent.',
		attach: 'Attach a file',
		fileTooLarge: 'This file is too large (max {mb} MB).',
		fileTooLargeNoLimit: 'This file is too large.',
		fileType: 'Only images (JPEG, PNG, GIF, WebP) and PDF files can be sent.',
		fileFailed: 'Couldn\'t send the file.',
		fileLoadFailed: 'Couldn\'t load the file.',
		askIntro: 'We usually reply within a few minutes. If you leave the page, we\'ll send the answer by email.',
		askLabel: 'Email or order no.',
		askLabelEmail: 'Email',
		askOk: 'OK',
		askLater: 'Not now',
		askAway: 'Where should we send the answer?',
		askThanks: 'Thanks!',
		askEmailOnly: 'Could you leave your email address? Then we can send you the answer.',
		askInvalid: 'Please enter a valid email address.',
		privacy: 'Privacy policy',
		noticeEmail: 'This conversation continues by email. The link in our email brings you back here.',
		noticeElsewhere: 'This conversation now continues in another browser.',
		continuePrompt: 'Continue your conversation here?',
		continueButton: 'Continue chat',
		continueFailed: 'This link has expired or was already used.',
		online: 'Online · usually replies in a few minutes',
		backToday: 'Back today {time} · we\'ll answer by email',
		backDay: 'Back {day} {time} · we\'ll answer by email',
		viaEmail: 'via email',
		rateLimited: 'Too many messages in a short time. Please wait a moment.',
		welcome: ''
	};
	function str(key) {
		return strings[key] || DEFAULT_STRINGS[key] || '';
	}
	var API = cfg.apiBase;
	var POLL_FAST = 3000;
	var POLL_SLOW = 30000;
	var POLL_MAX = 600000;       // backoff ceiling: 10 min
	var REQUEST_TIMEOUT = 20000; // every JSON request is aborted after 20 s
	var UPLOAD_TIMEOUT = 120000; // a multipart upload gets 2 min (a 10 MB file on a mobile link)
	var DOWNLOAD_RATE = 50000;   // an attachment download gets UPLOAD_TIMEOUT, or 1 s per 50 KB of it when that is longer
	var RECHECK_EVERY = 1800000; // identity re-check while the panel is open: 30 min
	var LOGIN_FRESH = 3600000;   // a send with a token fetches a login token once the last one is older: 60 min

	/* ---- storage with in-memory fallback ---- */
	var mem = {};
	function store(key, val) {
		if (val === undefined) {
			try {
				var v = window.localStorage.getItem(key);
				return v === null ? (mem[key] || null) : v;
			} catch (e) {
				return mem[key] || null;
			}
		}
		if (val === null) {
			delete mem[key];
			try { window.localStorage.removeItem(key); } catch (e2) {}
			return null;
		}
		mem[key] = val;
		try { window.localStorage.setItem(key, val); } catch (e3) {}
		return val;
	}
	function getToken() { return store('lcff_token'); }

	/*
	 * The token for this browser's first message is generated here, not by the
	 * server, so a retry of a first message whose response was lost lands in
	 * the same session. It stays in memory only (never in storage) until a 2xx
	 * response confirms it, and every tokenless send of this page reuses it.
	 */
	var pendingToken = null;

	/* ---- state ---- */
	var isOpen = false;
	var built = false;
	var started = false;
	var lastId = 0;
	var rendered = {};      // server id -> element
	var unseenAgentIds = [];  // agent ids rendered while active, not yet queued
	var pendingSeen = {};     // id -> true: queued for /seen until the server accepts or refuses them
	var confirmedSeen = {};   // id -> true: the server answered 204 for it (this page)
	var seenInFlight = false; // a /seen request is running
	var seenSeq = 0;          // bumped per /seen request and when receipts are discarded; older answers are ignored
	var unread = 0;
	var pollTimer = null;
	var polling = false;
	var pollStale = false;  // the poll in flight answers from before a send or login
	var failures = 0;       // consecutive failed polls, for the backoff
	var initialDone = false;
	var el = {};
	var ATTACH_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
	var attachMb = 0;          // limits.attachment_mb from the latest answer; 0: not known yet (the server decides)
	var blobUrls = [];         // every blob URL this widget made; revoked in clearView()
	var fileErrorTimer = null;
	var viewEpoch = 0;         // bumped by clearView(): a blob fetched for an older view is dropped
	var thumbQueue = [];       // thumbnail loads waiting for the panel to open (see whenShown())

	/* ---- identity state ---- */
	var lastIdentity = null;   // {level, account} from the latest send, poll or identity answer
	var loggedIn = false;      // WordPress said "logged in" on its latest answer
	var confirmLogout = false; // logged out, and the session's first answer decides (see checkIdentity)
	var checking = false;      // a panel-open identity check is running
	var held = false;          // the message list is hidden until that check settles
	var gen = 0;               // bumped by forget(); answers to older requests are dropped
	var lastLoginAt = 0;       // Date.now() when FreeScout last accepted a login token; 0: not yet
	var rechecking = false;    // a re-check (see recheckIdentity()) is running
	var recheckTimer = null;
	var askCard = null;        // the email / order-number card on screen, if any
	var askSeq = 0;
	var noticeEl = null;       // the one .lcff-notice at the end of the list, if any
	var pendingContinue = null; // the link the visitor opened; forget() keeps it
	var continueCard = null;   // the continue-link prompt on screen, if any

	function visible() { return document.visibilityState === 'visible'; }
	function active() { return isOpen && visible(); }

	/* ---- helpers ---- */
	function uuid() {
		try {
			if (window.crypto && typeof window.crypto.randomUUID === 'function') {
				return window.crypto.randomUUID();
			}
		} catch (e) {}
		var hex = '';
		try {
			var a = new Uint8Array(16);
			window.crypto.getRandomValues(a);
			for (var i = 0; i < a.length; i++) { hex += (a[i] + 256).toString(16).slice(1); }
		} catch (e2) {
			for (var j = 0; j < 32; j++) { hex += Math.floor(Math.random() * 16).toString(16); }
		}
		return hex;
	}

	/* 32 random bytes, base64url without padding: 43 characters of [A-Za-z0-9_-]. */
	function newToken() {
		var a = new Uint8Array(32);
		window.crypto.getRandomValues(a);
		var bin = '';
		for (var i = 0; i < a.length; i++) { bin += String.fromCharCode(a[i]); }
		return window.btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	/*
	 * Resolves to { status, data, hadToken, token }, where `token` is the chat
	 * token the request carried (null for none); rejects on a network error or
	 * after REQUEST_TIMEOUT (where AbortController exists). Never throws.
	 * `token` defaults to the stored token; null sends none.
	 */
	function request(method, path, body, token) {
		return rawRequest(method, path, body, token, false);
	}

	/* Like request(), with a FormData body and no Content-Type (the browser adds the boundary). */
	function requestForm(method, path, form, token) {
		return rawRequest(method, path, form, token, true);
	}

	/* Remembers the size limit the server last announced. */
	function noteLimits(data) {
		if (data && data.limits && typeof data.limits.attachment_mb === 'number') {
			attachMb = data.limits.attachment_mb;
		}
	}

	/* True once the server's latest answer said it takes X-Chat-Page (features: ["page"]). */
	var serverTakesPage = false;

	function noteFeatures(data) {
		if (!data || typeof data !== 'object') { return; }
		if (Array.isArray(data.features)) {
			serverTakesPage = data.features.indexOf('page') !== -1;
		} else if (data.identity !== undefined) {
			serverTakesPage = false;
		}
	}

	function rawRequest(method, path, body, token, isForm) {
		var limit = isForm ? UPLOAD_TIMEOUT : REQUEST_TIMEOUT;
		var timer = null;
		function clear() {
			if (timer) { clearTimeout(timer); timer = null; }
		}
		try {
			var headers = {};
			if (token === undefined) { token = getToken(); }
			if (token) { headers['X-Chat-Token'] = token; }
			// Polls (only polls) tell the agents which page the visitor is on:
			// the path alone, never the query or the fragment.
			if (serverTakesPage && method === 'GET' && path.indexOf('/messages?') === 0) {
				headers['X-Chat-Page'] = String(window.location.pathname || '/').slice(0, 255);
			}
			var init = { method: method, headers: headers, mode: 'cors', credentials: 'omit' };
			if (body !== undefined) {
				if (isForm) {
					init.body = body;
				} else {
					headers['Content-Type'] = 'application/json';
					init.body = JSON.stringify(body);
				}
			}
			try {
				if (typeof window.AbortController === 'function') {
					var ctrl = new window.AbortController();
					init.signal = ctrl.signal;
					timer = setTimeout(function () { ctrl.abort(); }, limit);
				}
			} catch (e) {}
			return fetch(API + path, init).then(function (res) {
				return res.text().then(function (t) {
					clear();
					var data = null;
					try { data = t ? JSON.parse(t) : null; } catch (e2) {}
					noteLimits(data);
					noteFeatures(data);
					return { status: res.status, data: data, hadToken: !!token, token: token || null };
				});
			}).then(null, function (err) {
				clear();
				throw err;
			});
		} catch (e3) {
			clear();
			return Promise.reject(e3);
		}
	}

	function mk(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined) { n.textContent = text; }
		return n;
	}

	function setUnread(n) {
		unread = n;
		document.dispatchEvent(new CustomEvent('lcff:unread', { detail: { count: n } }));
	}

	function scrollDown() { el.list.scrollTop = el.list.scrollHeight; }

	/* ---- working hours ---- */
	var hoursTimer = null;

	/* Day and time names in the WordPress locale, the language of the texts around them; <html lang> only for a config without it (a page cached before the update). */
	function fmt(opts, ms) {
		var lang = cfg.locale || document.documentElement.lang || undefined;
		try {
			return new Intl.DateTimeFormat(lang, opts).format(new Date(ms));
		} catch (e) {
			return new Intl.DateTimeFormat(undefined, opts).format(new Date(ms));
		}
	}

	function dayKey(ms) {
		var d = new Date(ms);
		return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate();
	}

	/* Status line and welcome text for the current moment, in the visitor's own clock. */
	function applyHours() {
		if (!built || !cfg.hours || !window.LCFF_HOURS) { return; }
		var now = Date.now();
		var st = null;
		try { st = window.LCFF_HOURS.state(cfg.hours, now); } catch (e) { st = null; }
		var line = '';
		if (st === null) {
			// Unknown: no status line, normal welcome.
			el.status.hidden = true;
			el.status.textContent = '';
			el.welcome.textContent = cfg.welcome || str('welcome');
			return;
		}
		if (st.open) {
			line = str('online');
		} else if (st.next !== null) {
			var tpl = dayKey(st.next) === dayKey(now) ? (str('backToday')) : (str('backDay'));
			line = tpl.replace('{day}', fmt({ weekday: 'long' }, st.next))
				.replace('{time}', fmt({ hour: 'numeric', minute: '2-digit' }, st.next));
		}
		el.status.textContent = line;
		el.status.hidden = line === '';
		var text = st.open ? (cfg.welcome || str('welcome')) : (cfg.welcomeAway || cfg.welcome || str('welcome'));
		if (el.welcome.textContent !== text) { el.welcome.textContent = text; }
	}

	function armHours() {
		if (hoursTimer) { window.clearInterval(hoursTimer); hoursTimer = null; }
		if (isOpen && cfg.hours && window.LCFF_HOURS) {
			try { applyHours(); } catch (e) {} // the status line is optional, open() must finish

			hoursTimer = window.setInterval(applyHours, 60000);
		}
	}

	/* ---- build ---- */
	function build() {
		if (built) { return; }
		built = true;

		el.panel = mk('div', 'lcff-panel');
		el.panel.setAttribute('role', 'dialog');
		var teamName = cfg.teamName || str('title');
		el.panel.setAttribute('aria-label', teamName || 'Chat');
		el.panel.hidden = true;
		if (/^#[0-9a-f]{6}$/i.test(cfg.accent || '')) {
			el.panel.style.setProperty('--lcff-accent', cfg.accent);
		}
		if (cfg.position === 'left') {
			el.panel.classList.add('lcff-left');
		}

		var header = mk('div', 'lcff-header');
		var who = mk('div', 'lcff-who');
		if (/^https?:\/\//i.test(cfg.avatarUrl || '')) {
			var avatar = mk('img', 'lcff-header-avatar');
			avatar.src = cfg.avatarUrl;
			avatar.alt = '';
			who.appendChild(avatar);
		}
		var names = mk('div', 'lcff-names');
		names.appendChild(mk('div', 'lcff-title', teamName));
		el.status = mk('div', 'lcff-status');
		el.status.hidden = true;
		names.appendChild(el.status);
		who.appendChild(names);
		header.appendChild(who);
		el.close = mk('button', 'lcff-close', '×');
		el.close.type = 'button';
		el.close.setAttribute('aria-label', str('close'));
		el.close.addEventListener('click', close);
		header.appendChild(el.close);
		el.panel.appendChild(header);

		el.list = mk('div', 'lcff-messages');
		el.list.setAttribute('aria-live', 'polite');
		el.welcome = mk('div', 'lcff-welcome', cfg.welcome || str('welcome'));
		el.list.appendChild(el.welcome);
		el.panel.appendChild(el.list);

		el.form = mk('form', 'lcff-form');
		el.input = mk('textarea', 'lcff-input');
		el.input.rows = 1;
		el.input.maxLength = 5000;
		el.input.placeholder = str('placeholder');
		el.input.setAttribute('aria-label', str('placeholder'));
		el.input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
				e.preventDefault();
				submit();
			}
		});
		el.send = mk('button', 'lcff-send', str('send'));
		el.send.type = 'submit';
		el.attach = mk('button', 'lcff-attach');
		el.attach.type = 'button';
		el.attach.setAttribute('aria-label', str('attach'));
		el.attach.title = str('attach');
		el.attach.appendChild(clipIcon());
		el.file = document.createElement('input');
		el.file.type = 'file';
		el.file.accept = ATTACH_TYPES.join(',');
		el.file.hidden = true;
		el.file.tabIndex = -1;
		el.file.setAttribute('aria-hidden', 'true');
		el.attach.addEventListener('click', function () {
			if (!held) { el.file.click(); }
		});
		el.file.addEventListener('change', function () {
			var f = el.file.files && el.file.files[0];
			el.file.value = '';
			if (f) { submitFile(f); }
		});
		el.fileError = mk('div', 'lcff-file-error');
		el.fileError.setAttribute('role', 'alert');
		el.fileError.hidden = true;
		// Honeypot: invisible to people, tempting to form-filling bots.
		el.hpWrap = mk('div', 'lcff-hp');
		el.hpWrap.setAttribute('aria-hidden', 'true');
		el.hp = document.createElement('input');
		el.hp.type = 'text';
		el.hp.name = 'lcff_hp';
		el.hp.tabIndex = -1;
		el.hp.autocomplete = 'off';
		el.hpWrap.appendChild(el.hp);
		el.form.appendChild(el.hpWrap);
		el.form.appendChild(el.attach);
		el.form.appendChild(el.file);
		el.form.appendChild(el.input);
		el.form.appendChild(el.send);
		el.form.addEventListener('submit', function (e) {
			e.preventDefault();
			submit();
		});
		el.panel.appendChild(el.fileError);
		el.panel.appendChild(el.form);

		el.panel.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { close(); }
		});

		document.body.appendChild(el.panel);
		document.addEventListener('visibilitychange', onVisibility);
	}

	/* ---- rendering ---- */
	function renderMessage(m) {
		if (rendered[m.id]) { return null; }
		var role = m.role === 'agent' ? 'agent' : 'visitor';
		var n = mk('div', 'lcff-message lcff-message--' + role);
		n.setAttribute('data-id', String(m.id));
		n.setAttribute('data-role', role);
		if (role === 'agent' && (m.author || m.avatar)) {
			var head = mk('div', 'lcff-author');
			if (m.avatar) {
				var img = document.createElement('img');
				img.className = 'lcff-avatar';
				img.alt = '';
				img.width = 28;
				img.height = 28;
				img.setAttribute('referrerpolicy', 'no-referrer');
				img.addEventListener('error', function () {
					if (img.parentNode) { img.parentNode.removeChild(img); }
				});
				img.src = String(m.avatar);
				head.appendChild(img);
			}
			if (m.author) {
				head.appendChild(mk('span', 'lcff-author-name', String(m.author)));
			}
			n.appendChild(head);
		}
		var atts = m.attachments && m.attachments.length ? m.attachments : [];
		if (String(m.text) !== '' || !atts.length) {
			n.appendChild(mk('div', 'lcff-text', String(m.text)));
		}
		renderAttachments(n, atts);
		if (m.via === 'email') {
			n.appendChild(mk('div', 'lcff-via', str('viaEmail')));
		}
		rendered[m.id] = n;
		el.list.appendChild(n);
		return n;
	}

	/* ---- attachments ---- */
	function clipIcon() {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS(ns, 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('width', '20');
		svg.setAttribute('height', '20');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		var path = document.createElementNS(ns, 'path');
		path.setAttribute('d', 'M21.4 11.1l-8.5 8.5a5.5 5.5 0 0 1-7.8-7.8l8.5-8.5a3.7 3.7 0 0 1 5.2 5.2l-8.5 8.5a1.8 1.8 0 0 1-2.6-2.6l7.8-7.8');
		path.setAttribute('fill', 'none');
		path.setAttribute('stroke', 'currentColor');
		path.setAttribute('stroke-width', '2');
		path.setAttribute('stroke-linecap', 'round');
		path.setAttribute('stroke-linejoin', 'round');
		svg.appendChild(path);
		return svg;
	}

	function isImage(mime) { return /^image\/(jpeg|png|gif|webp)$/.test(String(mime || '')); }

	/* The whole download, body included: a large file on a slow link needs its time. */
	function downloadTimeout(size) {
		var bySize = typeof size === 'number' && size > 0 ? Math.ceil(size / DOWNLOAD_RATE) * 1000 : 0;
		return Math.max(UPLOAD_TIMEOUT, bySize);
	}

	/*
	 * Thumbnails load only while the panel is open and its list shown: a
	 * returning visitor's page view polls the history with the panel closed,
	 * and must not download every image in it (spec 4: the shop must not get
	 * slower). Rows rendered meanwhile wait here; open() and releaseList()
	 * run them, clearView() drops them.
	 */
	function whenShown(fn) {
		if (isOpen && !held) { fn(); } else { thumbQueue.push(fn); }
	}

	function flushThumbs() {
		var waiting = thumbQueue;
		thumbQueue = [];
		for (var i = 0; i < waiting.length; i++) {
			try { waiting[i](); } catch (e) {}
		}
	}

	/* Fetches an attachment with the chat token; resolves to a blob URL, or rejects. */
	function loadAttachment(a) {
		var myEpoch = viewEpoch;
		var t = getToken();
		var headers = {};
		if (t) { headers['X-Chat-Token'] = t; }
		var init = { method: 'GET', headers: headers, mode: 'cors', credentials: 'omit' };
		var timer = null;
		try {
			if (typeof window.AbortController === 'function') {
				var ctrl = new window.AbortController();
				init.signal = ctrl.signal;
				timer = setTimeout(function () { ctrl.abort(); }, downloadTimeout(a.size));
			}
		} catch (e) {}
		return fetch(API + '/attachments/' + encodeURIComponent(a.id), init).then(function (res) {
			if (!res.ok) {
				var bad = new Error('lcff: attachment ' + res.status);
				bad.status = res.status;
				bad.token = t || null;
				throw bad;
			}
			return res.blob();
		}).then(function (blob) {
			if (timer) { clearTimeout(timer); }
			if (myEpoch !== viewEpoch) { throw new Error('lcff: view cleared'); }
			var url = window.URL.createObjectURL(blob);
			blobUrls.push(url);
			return url;
		}, function (err) {
			if (timer) { clearTimeout(timer); }
			if (err && err.status === 401) { unauthorized(err.token); }
			throw err;
		});
	}

	function revokeBlobUrls() {
		for (var i = 0; i < blobUrls.length; i++) {
			try { window.URL.revokeObjectURL(blobUrls[i]); } catch (e) {}
		}
		blobUrls = [];
	}

	function revokeNow(url) {
		var i = blobUrls.indexOf(url);
		if (i !== -1) { blobUrls.splice(i, 1); }
		try { window.URL.revokeObjectURL(url); } catch (e) {}
	}

	/* A saved file: a PDF or any other non-image type. Never opened in a tab, since a blob URL has the shop's origin. */
	function downloadAttachment(a, link) {
		var epoch = viewEpoch;
		if (link.classList.contains('lcff-att--busy')) { return; }
		link.classList.remove('lcff-att--failed');
		link.classList.add('lcff-att--busy');
		loadAttachment(a).then(function (url) {
			link.classList.remove('lcff-att--busy');
			var dl = document.createElement('a');
			dl.href = url;
			dl.download = String(a.name || 'file');
			dl.rel = 'noopener';
			dl.style.display = 'none';
			document.body.appendChild(dl);
			dl.click();
			document.body.removeChild(dl);
			setTimeout(function () { revokeNow(url); }, 10000);
		}, function () {
			if (epoch !== viewEpoch) { return; } // the view was cleared meanwhile: nothing to report to whoever is here now
			link.classList.remove('lcff-att--busy');
			link.classList.add('lcff-att--failed');
			showFileError(str('fileLoadFailed'));
		});
	}

	/* A link that downloads the attachment when clicked. */
	function fileLink(a, name) {
		var link = mk('a', 'lcff-att-file', name);
		link.href = '#';
		link.setAttribute('role', 'button');
		link.addEventListener('click', function (e) {
			e.preventDefault();
			downloadAttachment(a, link);
		});
		return link;
	}

	function renderAttachments(node, atts) {
		for (var i = 0; i < atts.length; i++) {
			(function (a) {
				if (!a || typeof a.id !== 'number') { return; }
				var name = String(a.name || '');
				var wrap = mk('div', 'lcff-att');
				if (isImage(a.mime)) {
					var open = mk('a', 'lcff-att-img');
					open.href = '#';
					open.setAttribute('role', 'button');
					open.setAttribute('aria-label', name);
					var img = document.createElement('img');
					img.className = 'lcff-att-thumb';
					img.alt = name;
					open.appendChild(img);
					var shown = null;
					open.addEventListener('click', function (e) {
						e.preventDefault();
						if (shown) { window.open(shown, '_blank', 'noopener'); }
					});
					wrap.appendChild(open);
					var epoch = viewEpoch;
					whenShown(function () {
						if (epoch !== viewEpoch) { return; }
						loadAttachment(a).then(function (url) {
							shown = url;
							img.src = url;
						}, function () {
							if (epoch !== viewEpoch) { return; }
							// No preview, but the file can still be fetched (and retried) by a click.
							if (open.parentNode === wrap) { wrap.replaceChild(fileLink(a, name), open); }
						});
					});
				} else {
					wrap.appendChild(fileLink(a, name));
				}
				node.appendChild(wrap);
			})(atts[i]);
		}
	}

	/*
	 * `conversation.notice` (null, 'continues_by_email' or 'continues_elsewhere')
	 * is one element at the end of the list, updated in place and removed when
	 * the notice goes. A response without a `notice` key changes nothing.
	 */
	function updateNotice(conv) {
		if (!built || !conv || typeof conv !== 'object' || conv.notice === undefined) { return; }
		var text = conv.notice === 'continues_by_email' ? str('noticeEmail')
			: conv.notice === 'continues_elsewhere' ? str('noticeElsewhere') : '';
		if (!text) {
			if (noticeEl && noticeEl.parentNode) { noticeEl.parentNode.removeChild(noticeEl); }
			noticeEl = null;
			return;
		}
		if (!noticeEl) { noticeEl = mk('div', 'lcff-notice'); }
		noticeEl.setAttribute('role', 'status');
		if (noticeEl.textContent !== text) { noticeEl.textContent = text; }
		if (el.list.lastChild !== noticeEl) { el.list.appendChild(noticeEl); scrollDown(); }
	}

	/* Keeps the notice last after something was appended behind it. */
	function keepNoticeLast() {
		if (noticeEl && noticeEl.parentNode === el.list && el.list.lastChild !== noticeEl) {
			el.list.appendChild(noticeEl);
		}
	}

	function addPending(text, clientId, file) {
		var n = mk('div', 'lcff-message lcff-message--visitor lcff-message--sending');
		n.setAttribute('data-role', 'visitor');
		n.setAttribute('data-client-id', clientId);
		if (text) { n.appendChild(mk('div', 'lcff-text', text)); }
		if (file) { n.appendChild(mk('div', 'lcff-att-pending', String(file.name || ''))); }
		el.list.appendChild(n);
		keepNoticeLast();
		scrollDown();
		return n;
	}

	/* ---- sending ---- */
	function submit() {
		// The composer is disabled while the list is held (see holdList()).
		if (held) { return; }
		var text = el.input.value.trim();
		if (!text) { return; }
		if (text.length > 5000) { text = text.slice(0, 5000); }
		el.input.value = '';
		var clientId = uuid();
		var node = addPending(text, clientId);
		enqueue({ node: node, text: text, clientId: clientId });
		el.input.focus();
	}

	function showFileError(text) {
		if (!el.fileError) { return; }
		el.fileError.textContent = text;
		el.fileError.hidden = !text;
		if (fileErrorTimer) { clearTimeout(fileErrorTimer); fileErrorTimer = null; }
		if (text) { fileErrorTimer = setTimeout(function () { showFileError(''); }, 8000); }
	}

	function tooLargeText() {
		return attachMb > 0 ? (str('fileTooLarge')).replace('{mb}', String(attachMb)) : (str('fileTooLargeNoLimit'));
	}

	/* A chosen file goes out at once, as its own message, with the text typed so far. */
	function submitFile(file) {
		if (held) { return; }
		showFileError('');
		// The checks here refuse before the typed text is taken: it stays in the box.
		// An empty type (the OS has no mapping) goes to the server, which sniffs the content.
		var ft = String(file.type || '').toLowerCase();
		if (ft === 'image/jpg') { ft = 'image/jpeg'; }
		if (ft !== '' && ATTACH_TYPES.indexOf(ft) === -1) {
			showFileError(str('fileType'));
			return;
		}
		if (attachMb > 0 && file.size > attachMb * 1024 * 1024) {
			showFileError(tooLargeText());
			return;
		}
		var text = el.input.value.trim();
		if (text.length > 5000) { text = text.slice(0, 5000); }
		el.input.value = '';
		var clientId = uuid();
		var node = addPending(text, clientId, file);
		enqueue({ node: node, text: text, clientId: clientId, file: file });
		el.input.focus();
	}

	/*
	 * FIFO send queue: exactly one POST is in flight at any time, in submit order.
	 * The token is read when a POST is prepared (see prepareSend()). Before the
	 * first 2xx, every POST carries the same pending token as `new_token`, so
	 * messages queued behind the first one, and retries of it, all land in the
	 * same session and conversation.
	 */
	var queue = [];
	var inFlight = false;

	function enqueue(job) {
		if (job.node.getAttribute('data-queued') === '1') { return; }
		job.node.setAttribute('data-queued', '1');
		job.node.classList.remove('lcff-message--failed');
		job.node.classList.remove('lcff-message--rejected');
		job.node.classList.add('lcff-message--sending');
		job.node.onclick = null;
		job.node.onkeydown = null;
		job.node.removeAttribute('role');
		job.node.removeAttribute('tabindex');
		var note = job.node.querySelector('.lcff-retry');
		if (note) { job.node.removeChild(note); }
		queue.push(job);
		pump();
	}

	/*
	 * Resolves to the body, chat token and account marker of a send. A
	 * tokenless send carries the pending token as `new_token`; when WordPress
	 * last said "logged in", it also carries a fresh login token bound to it
	 * (each one is single-use), so the conversation starts as an account
	 * conversation (Ruling P7), and `acct` is that account's marker. A send
	 * with a token carries one too once the last login FreeScout accepted is
	 * LOGIN_FRESH old, so a long chat keeps its account (FreeScout lets an
	 * account login lapse after 2 h). Never rejects.
	 */
	function prepareSend(job) {
		var body = { text: job.text, client_message_id: job.clientId };
		var token = getToken();
		var chat = token;
		if (!token) {
			if (!pendingToken) { pendingToken = newToken(); }
			chat = pendingToken;
			body.new_token = chat;
		}
		var plain = { body: body, token: token || null, acct: null };
		if (!loggedIn || !identityOn() || (token && Date.now() - lastLoginAt <= LOGIN_FRESH)) {
			return Promise.resolve(plain);
		}
		var myGen = gen;
		return wpIdentity(chat).then(function (who) {
			if (!who || myGen !== gen) { return plain; }
			loggedIn = who.logged_in;
			if (token) {
				// As on a panel-open check: never log this browser's session
				// in as whoever sits here now, if that is someone else.
				var mine = store('lcff_acct');
				if (!who.logged_in) {
					if ((lastIdentity && lastIdentity.account) || mine) { logout(); }
					return plain;
				}
				if (mine && mine !== who.acct) {
					forget();
					loggedIn = true;
					recheckIdentity();
					return plain;
				}
				// The login token is bound to `chat`; a newer token can't use it.
				if (getToken() !== chat) { return plain; }
			}
			if (who.logged_in) {
				body.login_token = who.token;
				plain.acct = who.acct;
			}
			return plain;
		});
	}

	/*
	 * Sends one job. Never while the list is held for the panel-open check:
	 * the token may belong to the account that check is about to forget.
	 * A job waits for the release and is prepared afterwards, so it uses the
	 * token (or pending token) of whoever is in front of the screen.
	 */
	function dispatch(job) {
		return whenReleased().then(function () {
			return prepareSend(job);
		}).then(function (p) {
			// The widget forgot everything meanwhile: the job is dropped.
			if (job.gen !== gen) { throw new Error('lcff: forgotten'); }
			// Held again while the login token was fetched: wait and prepare afresh.
			if (held) { return dispatch(job); }
			job.acct = p.acct;
			job.login = !!p.body.login_token;
			p.body.hp = el.hp ? el.hp.value : '';
			if (job.file) {
				var form = new window.FormData();
				for (var k in p.body) {
					if (Object.prototype.hasOwnProperty.call(p.body, k) && p.body[k] !== undefined && !(k === 'text' && !p.body[k])) { form.append(k, p.body[k]); }
				}
				form.append('file', job.file, job.file.name);
				return requestForm('POST', '/messages', form, p.token);
			}
			return request('POST', '/messages', p.body, p.token);
		});
	}

	function pump() {
		if (inFlight || !queue.length) { return; }
		inFlight = true;
		var job = queue.shift();
		job.gen = gen;
		var settled = false;
		// Runs exactly once per job, whatever throws, so inFlight never sticks.
		var done = function () {
			if (settled) { return; }
			settled = true;
			job.node.removeAttribute('data-queued');
			inFlight = false;
			pump();
		};
		var sent;
		try {
			sent = dispatch(job);
		} catch (e) {
			sent = Promise.reject(e);
		}
		sent.then(function (r) {
			try {
				onSendResponse(job, r);
			} finally {
				done();
			}
		}, function () {
			// Network error or timeout: retryable with the same client_message_id
			// and, before the first 2xx, the same pending token.
			try {
				fail(job, true);
			} finally {
				done();
			}
		}).then(null, function () {
			done();
		});
	}

	function onSendResponse(job, r) {
		// The widget forgot everything while this was in flight (Ruling P5).
		if (job.gen !== gen) { return; }
		var node = job.node;
		if ((r.status === 201 || r.status === 200) && r.data && r.data.message) {
			if (!r.hadToken) {
				// The server echoes the pending token; from now on it is the session token.
				var confirmed = r.data.token || pendingToken;
				if (confirmed) { store('lcff_token', confirmed); }
				pendingToken = null;
			}
			var dto = r.data.message;
			node.classList.remove('lcff-message--sending');
			if (rendered[dto.id] && rendered[dto.id] !== node) {
				// A poll already rendered this message; drop the pending copy.
				if (node.parentNode) { node.parentNode.removeChild(node); }
			} else {
				node.setAttribute('data-id', String(dto.id));
				rendered[dto.id] = node;
			}
			if (job.file && node.parentNode) {
				var ph = node.querySelector('.lcff-att-pending');
				if (ph) { node.removeChild(ph); }
				if (dto.attachments && dto.attachments.length) {
					renderAttachments(node, dto.attachments);
					keepNoticeLast();
					scrollDown();
				} else {
					// The server took the message but dropped the file (PHP file_uploads off): say so.
					node.classList.add('lcff-message--rejected');
					node.appendChild(mk('div', 'lcff-unsent', str('fileFailed')));
				}
			}
			noteLogin(job.login, r.data.identity);
			noteIdentity(r.data.identity);
			if (job.gen !== gen) { return; } // the answer showed a logged-out account
			updateNotice(r.data.conversation);
			// The login token was accepted: remember whose account this browser holds.
			if (job.acct && r.data.identity && r.data.identity.account === true) {
				store('lcff_acct', job.acct);
			}
			maybeAsk(node, r.data);
			// A message was just sent, so there is something to wait for again,
			// and the server is reachable: a backing-off poller is rescheduled.
			var wasBackingOff = failures > 0;
			failures = 0;
			// N1: a poll in flight answers from before this message; its
			// poll:false must not stop polling.
			if (polling) { pollStale = true; }
			if (!started) { startPolling(); } else if (wasBackingOff) { schedule(); }
			return;
		}
		if (r.status === 401 && r.hadToken) {
			unauthorized(r.token);
		}
		if (r.status === 429) {
			// Rate limited: the message stays, tapping it tries again later.
			fail(job, true, str('rateLimited'));
			return;
		}
		// 409 (same id, different text; or a token taken elsewhere) and 422
		// (invalid input) come back identically on every retry.
		if (job.file) {
			var why = null;
			if (r.status === 413) {
				why = tooLargeText();
			} else if (r.status === 422 && r.data && r.data.field === 'file') {
				why = r.data.reason === 'too_large' ? tooLargeText()
					: r.data.reason === 'type' ? (str('fileType')) : (str('fileFailed'));
			} else if (r.status === 422) {
				why = str('fileFailed');
			}
			if (why !== null) {
				fail(job, false, why);
				giveBackText(job);
				return;
			}
		}
		fail(job, r.status !== 409 && r.status !== 422);
	}

	/*
	 * The file was refused, so the text that rode with it was never sent: it
	 * goes back into the message box, if that is still empty, to be sent on
	 * its own. The refused row then shows only the file and the reason.
	 */
	function giveBackText(job) {
		if (!job.text || !built || el.input.value.trim() !== '') { return; }
		el.input.value = job.text;
		var t = job.node.querySelector('.lcff-text');
		if (t) { job.node.removeChild(t); }
	}

	/*
	 * A failed message never blocks the queue: done() moves on to the next one.
	 * Before the first 2xx the next one carries the same pending token, so it
	 * still joins the same session.
	 */
	function fail(job, retryable, message) {
		var node = job.node;
		node.removeAttribute('data-queued');
		node.classList.remove('lcff-message--sending');
		node.onclick = null;
		node.onkeydown = null;
		var note = node.querySelector('.lcff-retry, .lcff-unsent');
		if (note) { node.removeChild(note); }

		if (!retryable) {
			node.classList.add('lcff-message--rejected');
			node.removeAttribute('role');
			node.removeAttribute('tabindex');
			node.appendChild(mk('div', 'lcff-unsent', message || str('unsent')));
			return;
		}

		node.classList.add('lcff-message--failed');
		node.setAttribute('role', 'button');
		node.setAttribute('tabindex', '0');
		node.appendChild(mk('div', 'lcff-retry', message || (job.file ? str('fileFailed') : str('retry'))));
		var retry = function () { enqueue(job); };
		node.onclick = retry;
		node.onkeydown = function (e) {
			if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); retry(); }
		};
	}

	/* ---- polling ---- */
	function stopPolling() {
		started = false;
		if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
	}

	function startPolling() {
		if (!getToken()) { return; }
		started = true;
		schedule(0);
	}

	/*
	 * Without a delay: the cadence (3 s open and visible, 30 s otherwise),
	 * doubled for every consecutive failed poll, at most POLL_MAX.
	 */
	function schedule(delay) {
		if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
		if (!started) { return; }
		if (delay === undefined) {
			delay = Math.min((active() ? POLL_FAST : POLL_SLOW) * Math.pow(2, failures), POLL_MAX);
		}
		pollTimer = setTimeout(poll, delay);
	}

	/*
	 * After each 2xx the server says whether there is anything to wait for
	 * (`conversation.poll`); polling stops when it says no, or when there is
	 * no conversation. A send or opening the panel starts it again.
	 */
	function poll() {
		pollTimer = null;
		if (!started || polling) { return; }
		if (!getToken()) { started = false; return; }
		polling = true;
		pollStale = false;
		var myGen = gen;
		request('GET', '/messages?after=' + encodeURIComponent(lastId)).then(function (r) {
			var keep = true;
			try {
				// Forgotten while in flight (Ruling P5): the answer is dropped.
				if (myGen !== gen) { return; }
				if (r.status === 401) {
					// A newer token than the one this poll used stays, and keeps polling.
					if (unauthorized(r.token)) { keep = false; }
					return;
				}
				if (r.status < 200 || r.status > 299) {
					failures = Math.min(failures + 1, 30);
					return;
				}
				failures = 0;
				var data = r.data || {};
				keep = !!(data.conversation && data.conversation.poll !== false);
				noteIdentity(data.identity);
				if (myGen !== gen) { return; } // the answer showed a logged-out account
				if (Array.isArray(data.messages)) {
					handleMessages(data.messages);
				}
				updateNotice(data.conversation);
			} finally {
				polling = false;
				// N1: a send or login while this poll was in flight.
				if (pollStale) { pollStale = false; keep = true; }
				if (keep) { schedule(); } else { stopPolling(); }
			}
		}, function () {
			// Network error or timeout.
			polling = false;
			pollStale = false;
			if (myGen === gen) { failures = Math.min(failures + 1, 30); }
			schedule();
		}).then(null, function () {
			polling = false;
		});
	}

	function handleMessages(list) {
		var seenStored = parseInt(store('lcff_seen') || '0', 10) || 0;
		var newAgent = 0;
		var added = false;
		for (var i = 0; i < list.length; i++) {
			var m = list[i];
			if (typeof m.id !== 'number') { continue; }
			if (m.id > lastId) { lastId = m.id; }
			var isNew = !rendered[m.id];
			if (renderMessage(m)) { added = true; }
			if (isNew && m.role === 'agent') {
				if (active()) {
					unseenAgentIds.push(m.id);
				} else if (initialDone || m.id > seenStored) {
					newAgent++;
					unseenAgentIds.push(m.id);
				}
			}
		}
		initialDone = true;
		if (newAgent) { setUnread(unread + newAgent); }
		keepNoticeLast();
		if (added && el.list) { scrollDown(); }
		if (active() && !held) { markSeen(); }
	}

	/*
	 * Receipts survive errors: ids wait in pendingSeen until the server
	 * answers 204 (done) or 422 (never acceptable). 401 and every other
	 * failure leave them pending; the next trigger (poll answer, visibility
	 * change, panel open) retries, so there is no loop of its own. At most
	 * 100 ids go out per request, the server's cap.
	 *
	 * lcff_seen moves only after a 204 (storeSeen): a receipt lost to an
	 * error or to leaving the page is sent again by the next page.
	 */
	function markSeen() {
		// Seen means: panel open, tab visible, list released (spec 7).
		if (!active() || held) { return; }
		if (unread) { setUnread(0); }
		unseenAgentIds.forEach(function (id) { pendingSeen[id] = true; });
		unseenAgentIds = [];
		if (seenInFlight || !getToken()) { return; }
		var ids = Object.keys(pendingSeen).map(Number).sort(function (a, b) { return a - b; }).slice(0, 100);
		if (!ids.length) { return; }
		var mySeq = ++seenSeq;
		seenInFlight = true;
		request('POST', '/seen', { message_ids: ids }).then(function (r) {
			if (mySeq !== seenSeq) { return; } // discarded meanwhile (forget, logout, dropped token)
			seenInFlight = false;
			if (r.status === 204 || r.status === 422) {
				ids.forEach(function (id) {
					delete pendingSeen[id];
					if (r.status === 204) { confirmedSeen[id] = true; }
				});
				if (r.status === 204) { storeSeen(); }
				if (r.status === 204 && active() && !held) { markSeen(); }
			} else if (r.status === 401) {
				unauthorized(r.token);
			}
			// 429 and the rest: the ids stay pending for the next try.
		}, function () {
			if (mySeq === seenSeq) { seenInFlight = false; }
		});
	}

	/*
	 * lcff_seen lets the next page skip agent ids that need no receipt (and
	 * no unread count): the highest id the server confirmed, never past an id
	 * still waiting for its receipt (pending, or not yet queued). It never
	 * moves back.
	 */
	function storeSeen() {
		var floor = Infinity;
		Object.keys(pendingSeen).forEach(function (k) { if (+k < floor) { floor = +k; } });
		unseenAgentIds.forEach(function (id) { if (id < floor) { floor = id; } });
		var stored = parseInt(store('lcff_seen') || '0', 10) || 0;
		var best = stored;
		Object.keys(confirmedSeen).forEach(function (k) {
			if (+k > best && +k < floor) { best = +k; }
		});
		if (best > stored) { store('lcff_seen', String(best)); }
	}

	/* Never restarts a stopped poller; a running one polls at once when the tab
	 * becomes visible. While it is backing off, the armed timer keeps its
	 * delay (N3): every switch would otherwise restart the full wait. */
	function onVisibility() {
		if (active() && !held) { markSeen(); }
		// Back on this tab: someone may have logged out or in meanwhile, and
		// a long chat keeps its account login fresh.
		if (active()) { recheckIdentity(); }
		if (!started) { return; }
		if (failures && (pollTimer || polling)) { return; }
		schedule(visible() ? 0 : undefined);
	}

	/* ---- identity: WordPress login, discovery, logout ---- */

	/*
	 * Identity features need crypto.subtle (secure contexts only) for the
	 * bind hash. Without it they are skipped silently; the chat still works.
	 */
	function identityOn() {
		return !!(cfg.identity && cfg.ajaxUrl && window.crypto && window.crypto.subtle &&
			typeof window.TextEncoder === 'function');
	}

	/* Lowercase hex SHA-256 of a string; null when crypto.subtle is unavailable. */
	function sha256hex(str) {
		try {
			if (!window.crypto || !window.crypto.subtle || typeof window.TextEncoder !== 'function') {
				return Promise.resolve(null);
			}
			return window.crypto.subtle.digest('SHA-256', new window.TextEncoder().encode(str)).then(function (buf) {
				var a = new Uint8Array(buf);
				var hex = '';
				for (var i = 0; i < a.length; i++) { hex += (a[i] + 256).toString(16).slice(1); }
				return hex;
			}, function () { return null; });
		} catch (e) {
			return Promise.resolve(null);
		}
	}

	/*
	 * Asks WordPress (same origin, with its cookies) who is logged in. The
	 * login token in the answer is bound to `chat`, by default the stored
	 * token or else the pending one, lives 300 s and is single-use: fetch a
	 * fresh one for every use. Resolves to {logged_in, token, acct}, or null
	 * on any failure (Ruling P4: carry on). Never rejects. `acct` is an
	 * opaque per-site account marker (16 hex), null when logged out.
	 */
	function wpIdentity(chat) {
		if (chat === undefined) { chat = getToken() || pendingToken; }
		if (!cfg.identity || !cfg.ajaxUrl || !chat) { return Promise.resolve(null); }
		return sha256hex(chat).then(function (bind) {
			if (!bind) { return null; }
			var timer = null;
			var clear = function () {
				if (timer) { clearTimeout(timer); timer = null; }
			};
			var init = {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: 'action=lcff_identity&bind=' + bind,
				credentials: 'same-origin',
				cache: 'no-store'
			};
			try {
				if (typeof window.AbortController === 'function') {
					var ctrl = new window.AbortController();
					init.signal = ctrl.signal;
					timer = setTimeout(function () { ctrl.abort(); }, REQUEST_TIMEOUT);
				}
			} catch (e) {}
			return fetch(cfg.ajaxUrl, init).then(function (res) {
				return res.text().then(function (t) {
					clear();
					var d = null;
					try { d = JSON.parse(t); } catch (e2) {}
					if (!res.ok || !d || typeof d.logged_in !== 'boolean') { return null; }
					if (!d.logged_in) { return { logged_in: false, token: null, acct: null }; }
					return typeof d.token === 'string' && d.token.indexOf('v1.') === 0 &&
						typeof d.acct === 'string' && /^[a-f0-9]{16}$/.test(d.acct)
						? { logged_in: true, token: d.token, acct: d.acct }
						: null;
				});
			}).then(null, function () {
				clear();
				return null;
			});
		}).then(null, function () { return null; });
	}

	/*
	 * Every send, poll and identity answer carries the browser's identity.
	 * When WordPress said "logged out" before this session's first answer
	 * arrived, that answer decides: an account-bound session is forgotten.
	 * When FreeScout let the account login lapse while WordPress still says
	 * "logged in", a re-check refreshes it at once.
	 */
	function noteIdentity(identity) {
		if (!identity || typeof identity.level !== 'string') { return; }
		var had = !!(lastIdentity && lastIdentity.account);
		lastIdentity = { level: identity.level, account: identity.account === true };
		if (confirmLogout) {
			confirmLogout = false;
			if (lastIdentity.account) { logout(); return; }
		}
		if (had && !lastIdentity.account && loggedIn) { recheckIdentity(); }
	}

	/*
	 * The answer to a request that carried a login token: FreeScout accepted
	 * it (account: true), or rejected it, and then this browser counts as
	 * not logged in, so the ask can appear (spec 5.1).
	 */
	function noteLogin(carried, identity) {
		if (!carried || !identity || typeof identity.level !== 'string') { return; }
		if (identity.account === true) {
			lastLoginAt = Date.now();
		} else {
			loggedIn = false;
		}
	}

	/*
	 * A 401: the session behind `used` is gone. The stored token, with the
	 * seen marker, ask state and account marker that belong to it, is
	 * dropped only while it is still the token the request used; a newer one
	 * stays. Returns whether it dropped.
	 */
	function unauthorized(used) {
		if (!used || getToken() !== used) { return false; }
		store('lcff_token', null);
		store('lcff_seen', null);
		store('lcff_ask', null);
		store('lcff_acct', null);
		pendingToken = null;
		lastIdentity = null;
		// Receipts seen under a rejected token can never be accepted for another.
		discardSeen();
		stopPolling();
		return true;
	}

	function discardSeen() {
		pendingSeen = {};
		confirmedSeen = {};
		unseenAgentIds = [];
		seenInFlight = false;
		seenSeq++;
	}

	/* Empties the message list; the next session starts from nothing. */
	function clearView() {
		rendered = {};
		viewEpoch++;
		thumbQueue = [];
		revokeBlobUrls();
		discardSeen();
		lastId = 0;
		initialDone = false;
		if (unread) { setUnread(0); }
		if (!built) { return; }
		showFileError('');
		el.input.value = ''; // a draft belongs to whoever typed it
		var kids = Array.prototype.slice.call(el.list.childNodes);
		for (var i = 0; i < kids.length; i++) {
			if (kids[i] !== el.welcome) { el.list.removeChild(kids[i]); }
		}
		removeAsk(); // an away form lives outside the list
		noticeEl = null;
		continueCard = null;
	}

	/*
	 * Ruling P5: forget everything this browser holds and start fresh, so the
	 * next person at a shared computer sees nothing. Answers to requests
	 * already in flight are dropped (see gen), and unsent messages with them.
	 */
	function forget() {
		gen++;
		stopPolling();
		failures = 0;
		store('lcff_token', null);
		store('lcff_seen', null);
		store('lcff_ask', null);
		store('lcff_acct', null);
		pendingToken = null;
		lastIdentity = null;
		loggedIn = false;
		lastLoginAt = 0;
		confirmLogout = false;
		queue.length = 0;
		clearView();
		renderContinue(); // the link is not account content: the prompt comes back
	}

	/*
	 * Logout (Ruling P5): tells FreeScout, whose session forgets the account,
	 * then forgets everything locally right away, whatever the answer. The
	 * promise settles with the request and never rejects.
	 */
	function logout() {
		var t = getToken();
		var sent = t
			? request('POST', '/logout', undefined, t).then(function () {}, function () {})
			: Promise.resolve();
		forget();
		return sent;
	}

	/*
	 * The server-side state changed (a login, a discovered session): poll
	 * now. A poll in flight answers from before the change, so it must not
	 * stop polling (N1).
	 */
	function kick() {
		failures = 0;
		if (polling) { pollStale = true; }
		if (!started) { startPolling(); } else if (!polling) { schedule(0); }
	}

	var releaseWaiters = []; // dispatches waiting for releaseList()

	/*
	 * While this browser holds an account login (lcff_acct), open() hides the
	 * message list until the panel-open check settles: the person in front
	 * of it may not be that account's owner. Any outcome shows it again,
	 * failures included (Ruling P4: fail open).
	 */
	function holdList() {
		if (!built) { return; }
		held = true;
		el.list.style.visibility = 'hidden';
		el.list.setAttribute('aria-busy', 'true');
		// Nothing can be typed, queued or sent until the check settles.
		el.input.readOnly = true;
		el.send.disabled = true;
		el.attach.disabled = true;
	}

	function releaseList() {
		if (!held) { return; }
		held = false;
		el.list.style.visibility = '';
		el.list.removeAttribute('aria-busy');
		el.input.readOnly = false;
		el.send.disabled = false;
		el.attach.disabled = false;
		var waiters = releaseWaiters;
		releaseWaiters = [];
		for (var i = 0; i < waiters.length; i++) { waiters[i](); }
		if (active()) { markSeen(); }
		if (isOpen) {
			flushThumbs();
			el.input.focus();
		}
	}

	/* Resolves once the list is not held (at once when it isn't). */
	function whenReleased() {
		if (!held) { return Promise.resolve(); }
		return new Promise(function (resolve) { releaseWaiters.push(resolve); });
	}

	/*
	 * On every panel open, when identity is on: asks WordPress who is logged
	 * in, then
	 * - logged in as another account than the stored marker: forget() and
	 *   go on as a device without a token;
	 * - logged in, token stored: refreshes the account login (Ruling P4);
	 * - logged in, no token: looks for the account's chats with the pending
	 *   token, which is stored only when FreeScout created a session for it
	 *   (Ruling P6);
	 * - logged out while account-bound: logout() (Ruling P5);
	 * - failed: nothing changes (Ruling P4).
	 */
	function checkIdentity() {
		if (checking) { return; }
		if (!identityOn()) { releaseList(); return; }
		checking = true;
		confirmLogout = false;
		var finish = function () {
			checking = false;
			releaseList();
			renderContinue();
		};
		identityRound(true).then(finish, finish);
	}

	/*
	 * The identity round again, for a browser WordPress says is logged in,
	 * while the panel stays open: every RECHECK_EVERY, on returning to the
	 * tab, and when an answer shows FreeScout let the account login lapse.
	 * Unlike the panel-open check it never holds the list or the composer.
	 */
	function recheckIdentity() {
		if (!loggedIn || checking || rechecking || !identityOn()) { return; }
		rechecking = true;
		var done = function () { rechecking = false; };
		identityRound(true).then(done, done);
	}

	function armRecheck() {
		if (recheckTimer) { clearTimeout(recheckTimer); recheckTimer = null; }
		if (!isOpen) { return; }
		recheckTimer = setTimeout(function () {
			recheckTimer = null;
			recheckIdentity();
			armRecheck();
		}, RECHECK_EVERY);
	}

	function identityRound(mayRetry) {
		var stored = getToken();
		if (!stored && !pendingToken) { pendingToken = newToken(); }
		var chat = stored || pendingToken;
		var myGen = gen;
		return wpIdentity(chat).then(function (who) {
			if (!who || myGen !== gen) { return null; }
			if (!who.logged_in) {
				loggedIn = false;
				if ((lastIdentity && lastIdentity.account) || store('lcff_acct')) {
					logout();
				} else if (!lastIdentity && getToken()) {
					// The session's first answer is still outstanding; it decides.
					confirmLogout = true;
				}
				return null;
			}
			var mine = store('lcff_acct');
			if (mine && mine !== who.acct) {
				// Another account logged in on this browser (a shared computer
				// whose logout signal never reached the widget): nothing of the
				// previous account may show. The login token above is bound to
				// the old chat token, so the next round fetches a fresh one.
				forget();
				loggedIn = true;
				return mayRetry ? identityRound(false) : null;
			}
			loggedIn = true;
			var now = getToken();
			if (now) {
				// The login token is bound to `chat`. A first send that completed
				// meanwhile stored `chat` itself; any other token can't use it.
				if (now !== chat) { return null; }
				return request('POST', '/identity', { login_token: who.token }, chat).then(function (r) {
					onLogin(r, chat, who.acct, myGen);
				});
			}
			if (pendingToken !== chat) { return null; }
			return request('POST', '/identity', { login_token: who.token, new_token: chat }, null).then(function (r) {
				onDiscovery(r, chat, who.acct, myGen);
			});
		});
	}

	function onLogin(r, chat, acct, myGen) {
		if (myGen !== gen) { return; }
		if (r.status === 200 && r.data) {
			if (getToken() === chat) { store('lcff_acct', acct); }
			noteLogin(true, r.data.identity);
			noteIdentity(r.data.identity);
			if (myGen === gen) { kick(); }
		} else if (r.status === 401) {
			unauthorized(chat);
		} else if (r.status === 403) {
			// FreeScout rejected the login: this browser is not recognised,
			// so the ask can appear (spec 5.1). The chat goes on.
			loggedIn = false;
		}
		// Anything else: carry on (Ruling P4).
	}

	function onDiscovery(r, chat, acct, myGen) {
		if (myGen !== gen) { return; }
		var d = r.data || {};
		if (r.status === 200 && d.token === chat) {
			// The account has chats here; FreeScout created the session.
			if (!getToken()) { store('lcff_token', chat); }
			if (getToken() === chat) { store('lcff_acct', acct); }
			if (pendingToken === chat) { pendingToken = null; }
			noteLogin(true, d.identity);
			noteIdentity(d.identity);
			if (myGen === gen) { kick(); }
			return;
		}
		if (r.status === 403) {
			loggedIn = false; // rejected, as in onLogin()
			return;
		}
		if (r.status === 409 && !getToken() && pendingToken === chat) {
			// The token clashed with an existing session: never use it again.
			pendingToken = newToken();
		}
		// {"linked": false}, 409 and errors: nothing is stored. A first send
		// creates the session, with a login token (loggedIn).
	}

	/* ---- the email / order-number ask (spec 5.1) ---- */

	/*
	 * `lcff_ask` is {k: conversation key, n: times shown, m: visitor sends since
	 * the last ask, e: 1 when only an email may be asked for}. It is written
	 * only after a send (the token exists by then), reset when the key changes,
	 * and held in memory when storage is blocked (see store()).
	 */
	function readAsk(key) {
		try {
			var a = JSON.parse(store('lcff_ask') || 'null');
			if (a && typeof a === 'object' && a.k === key) {
				return { k: key, n: +a.n || 0, m: +a.m || 0, e: a.e ? 1 : 0 };
			}
		} catch (e) {}
		return { k: key, n: 0, m: 0, e: 0 };
	}

	function writeAsk(a) {
		store('lcff_ask', JSON.stringify({ k: a.k, n: a.n, m: a.m, e: a.e ? 1 : 0 }));
	}

	function removeAsk() {
		var wasAway = !!askCard && askCard.className.indexOf('lcff-ask-away') !== -1;
		if (askCard && askCard.parentNode) { askCard.parentNode.removeChild(askCard); }
		askCard = null;
		if (built && el.form) { el.form.hidden = false; } // the message box is back
		if (wasAway && isOpen && el.input) { el.input.focus(); } // the focused button is gone
	}

	/* Only a known "closed" turns the composer into the ask; unknown counts as open. */
	function shopClosed() {
		if (!cfg.hours || !window.LCFF_HOURS) { return false; }
		try {
			var st = window.LCFF_HOURS.state(cfg.hours, Date.now());
			return !!st && st.open === false;
		} catch (e) { return false; }
	}

	/*
	 * After a successful send: shows the card after the first message, and once
	 * more after two further sends if it was skipped or ignored. Never to a
	 * logged-in visitor, never a third time.
	 */
	function maybeAsk(node, data) {
		var key = data && data.conversation && data.conversation.key;
		if (typeof key !== 'string' || !key) { return; }
		if (loggedIn || !lastIdentity || lastIdentity.level !== 'anonymous') { return; }
		var a = readAsk(key);
		if (a.n === 1) { a.m++; }
		var show = a.n === 0 || (a.n === 1 && a.m >= 2);
		if (show) { a.n++; a.m = 0; }
		writeAsk(a);
		if (show) { showAsk(node, key, a.e === 1, shopClosed()); }
	}

	/*
	 * `away`: the shop is closed, so the form replaces the message box instead
	 * of joining the conversation. Both share this one submit and thanks path.
	 */
	function showAsk(after, key, emailOnly, away) {
		removeAsk();
		var myGen = gen;
		var canOrder = !!cfg.woo && !emailOnly && identityOn();
		var id = 'lcff-ask-' + (++askSeq);

		var card = mk('form', 'lcff-ask');
		card.noValidate = true;
		if (away) {
			card.className = 'lcff-ask lcff-ask-away';
		} else {
			card.appendChild(mk('div', 'lcff-ask-intro', canOrder ? (str('askIntro')) : (str('askEmailOnly'))));
		}
		var label = mk('label', 'lcff-ask-label', away ? (str('askAway')) : (canOrder ? (str('askLabel')) : (str('askLabelEmail'))));
		label.setAttribute('for', id);
		card.appendChild(label);
		var input = mk('input', 'lcff-ask-input');
		input.id = id;
		input.type = canOrder ? 'text' : 'email';
		input.maxLength = 254;
		input.autocomplete = canOrder ? 'off' : 'email';
		if (away) { input.placeholder = canOrder ? (str('askLabel')) : (str('askLabelEmail')); }
		card.appendChild(input);
		var error = mk('div', 'lcff-ask-error');
		error.setAttribute('role', 'alert');
		error.hidden = true;
		card.appendChild(error);

		var row = mk('div', 'lcff-ask-actions');
		var ok = mk('button', 'lcff-ask-ok', str('askOk'));
		ok.type = 'submit';
		var later = mk('button', 'lcff-ask-later', str('askLater'));
		later.type = 'button';
		row.appendChild(ok);
		row.appendChild(later);
		card.appendChild(row);
		var url = typeof cfg.privacyUrl === 'string' ? cfg.privacyUrl : '';
		if (/^https?:\/\//i.test(url)) {
			var link = mk('a', 'lcff-ask-privacy', str('privacy'));
			link.href = url;
			link.target = '_blank';
			link.rel = 'noopener';
			card.appendChild(link);
		}

		var busy = false;
		var thanks = function () {
			if (askCard !== card) { return; }
			if (away) {
				// The box comes back; the thanks stays in the conversation.
				removeAsk();
				var done = mk('div', 'lcff-ask lcff-ask--done', '');
				done.appendChild(mk('div', 'lcff-ask-thanks', str('askThanks')));
				el.list.appendChild(done);
				askCard = done;
				scrollDown();
				return;
			}
			while (card.firstChild) { card.removeChild(card.firstChild); }
			card.className = 'lcff-ask lcff-ask--done';
			card.appendChild(mk('div', 'lcff-ask-thanks', str('askThanks')));
		};
		var settle = function (resolved) {
			if (busy === false || myGen !== gen || askCard !== card) { return; }
			if (!resolved) {
				// The order did not resolve: the one re-ask wants an email (Ruling P8).
				var a = readAsk(key);
				a.e = 1;
				writeAsk(a);
			}
			thanks();
		};

		later.addEventListener('click', function () {
			if (askCard === card) { removeAsk(); }
		});
		card.addEventListener('submit', function (e) {
			e.preventDefault();
			var v = input.value.trim();
			if (busy || !v) { return; }
			var chat = getToken();
			if (!chat) { return; }
			busy = true;
			ok.disabled = true;
			error.hidden = true;
			var release = function () {
				busy = false;
				ok.disabled = false;
			};
			var failed = function () {
				// A network failure: keep the card so the visitor can try again.
				if (askCard === card && myGen === gen) { release(); }
			};
			var done = function (r) {
				if (r.status === 401) { unauthorized(r.token); }
			};
			if (v.indexOf('@') !== -1 || !canOrder) {
				request('POST', '/identity', { email: v }, chat).then(function (r) {
					if (myGen !== gen || askCard !== card) { return; }
					done(r);
					if (r.status === 200 && r.data) {
						noteIdentity(r.data.identity);
						settle(true);
					} else if (r.status === 409) {
						settle(true);
					} else if (r.status === 422 || r.status === 403) {
						error.textContent = str('askInvalid');
						error.hidden = false;
						release();
					} else if (r.status === 429) {
						// The typed value stays in the box.
						error.textContent = str('rateLimited');
						error.hidden = false;
						release();
					} else {
						failed();
					}
				}, failed);
				return;
			}
			orderToken(v, chat).then(function (token) {
				if (myGen !== gen || askCard !== card) { return null; }
				if (token === 'limited') { return { status: 429, data: null }; }
				if (!token) { return { status: 0, data: null }; }
				return request('POST', '/identity', { order_token: token }, chat).then(function (r) { return r; }, function () {
					return { status: 0, data: null };
				});
			}).then(function (r) {
				if (!r || myGen !== gen || askCard !== card) { return; }
				done(r);
				if (r.status === 429) {
					error.textContent = str('rateLimited');
					error.hidden = false;
					release();
					return;
				}
				// A 403 counts as "not resolved": the same "Thanks!" as any other miss.
				var resolved = r.status === 409;
				if (r.status === 200 && r.data) {
					noteIdentity(r.data.identity);
					resolved = !!r.data.identity && r.data.identity.level !== 'anonymous';
				}
				// "Thanks!" for every outcome: never a name, an email or "not found".
				settle(resolved);
			}).then(null, function () {});
		});

		if (away) {
			el.form.hidden = true;
			el.panel.appendChild(card);
			input.focus();
		} else if (after && after.parentNode === el.list) {
			el.list.insertBefore(card, after.nextSibling);
		} else {
			el.list.appendChild(card);
		}
		askCard = card;
		scrollDown();
	}

	/*
	 * Asks WordPress (same origin, with its cookies) to look the order up. The
	 * answer is an encrypted token bound to `chat`, whether or not the order
	 * exists. Resolves to the token, 'limited' when WordPress answered 429, or null on any failure. Never rejects.
	 */
	function orderToken(number, chat) {
		return sha256hex(chat).then(function (bind) {
			if (!bind) { return null; }
			var timer = null;
			var clear = function () {
				if (timer) { clearTimeout(timer); timer = null; }
			};
			var init = {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: 'action=lcff_order&number=' + encodeURIComponent(number) + '&bind=' + bind,
				credentials: 'same-origin',
				cache: 'no-store'
			};
			try {
				if (typeof window.AbortController === 'function') {
					var ctrl = new window.AbortController();
					init.signal = ctrl.signal;
					timer = setTimeout(function () { ctrl.abort(); }, REQUEST_TIMEOUT);
				}
			} catch (e) {}
			return fetch(cfg.ajaxUrl, init).then(function (res) {
				return res.text().then(function (t) {
					clear();
					var d = null;
					try { d = JSON.parse(t); } catch (e2) {}
					if (res.status === 429) { return 'limited'; }
					return res.ok && d && typeof d.token === 'string' && d.token.indexOf('v1.') === 0 ? d.token : null;
				});
			}).then(null, function () {
				clear();
				return null;
			});
		}).then(null, function () { return null; });
	}

	/* ---- continue-chat links (spec 5.3, Ruling P11) ---- */

	/* Redeems the link: 200 -> resolves 'ok'; 410/422 -> 'invalid'; else 'error'. */
	function redeem(link, retried) {
		var stored = getToken();
		var body = { link: link };
		if (!stored) {
			if (!pendingToken) { pendingToken = newToken(); }
			body.new_token = pendingToken;
		}
		var used = pendingToken;
		return request('POST', '/continue', body, stored || null).then(function (r) {
			if (r.status === 200 && r.data) {
				// The token is stored only after the 2xx (spec 10).
				var confirmed = r.hadToken ? r.token : (r.data.token || used);
				if (confirmed) { store('lcff_token', confirmed); }
				pendingToken = null;
				// The full history replaces whatever this browser showed.
				gen++;
				stopPolling();
				failures = 0;
				store('lcff_seen', null);
				store('lcff_ask', null);
				clearView();
				// A redemption never logs out: the token just stored must stay.
				confirmLogout = false;
				noteIdentity(r.data.identity);
				kick();
				return 'ok';
			}
			if (r.status === 410 || r.status === 422) {
				if (r.status === 422 && r.data && r.data.field === 'new_token' && !retried) {
					pendingToken = newToken();
					return redeem(link, true);
				}
				return 'invalid';
			}
			if (!retried && r.status === 409 && !r.hadToken) {
				pendingToken = newToken();
				return redeem(link, true);
			}
			if (!retried && r.status === 401 && r.hadToken) {
				unauthorized(r.token);
				return redeem(link, true);
			}
			if (r.status === 429) { return 'rate_limited'; }
			return 'error';
		}, function () { return 'error'; });
	}

	/*
	 * A visitor opened a continue link. Nothing is sent until the explicit
	 * click on the button (P11); the loader already took the secret out of the
	 * address bar.
	 */
	function continueChat(link) {
		if (typeof link !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(link)) { return; }
		pendingContinue = link;
		open();
		renderContinue();
	}

	/*
	 * Shows the prompt for `pendingContinue`, but not while the panel-open
	 * identity check runs (it may forget everything; finish() renders after).
	 */
	function renderContinue() {
		if (!pendingContinue || !built || checking) { return; }
		var link = pendingContinue;
		if (continueCard && continueCard.parentNode) { continueCard.parentNode.removeChild(continueCard); }
		var card = mk('div', 'lcff-ask lcff-continue');
		card.appendChild(mk('div', 'lcff-ask-intro', str('continuePrompt')));
		var failed = mk('div', 'lcff-ask-error', str('continueFailed'));
		failed.setAttribute('role', 'alert');
		failed.hidden = true;
		var row = mk('div', 'lcff-ask-actions');
		var go = mk('button', 'lcff-ask-ok lcff-continue-go', str('continueButton'));
		go.type = 'button';
		row.appendChild(go);
		card.appendChild(failed);
		card.appendChild(row);
		var busy = false;
		go.addEventListener('click', function () {
			if (busy) { return; }
			busy = true;
			go.disabled = true;
			failed.hidden = true;
			redeem(link, false).then(function (outcome) {
				if (outcome === 'ok' || outcome === 'invalid') { pendingContinue = null; }
				if (outcome === 'ok') { return; } // clearView() removed the card
				busy = false;
				failed.textContent = outcome === 'rate_limited' ? str('rateLimited') : str('continueFailed');
				failed.hidden = false;
				if (outcome === 'invalid') {
					row.style.display = 'none'; // a spent link stays spent
				} else {
					go.disabled = false;
				}
			});
		});
		continueCard = card;
		el.list.appendChild(card);
		keepNoticeLast();
		scrollDown();
		go.focus();
	}

	/* ---- public API ---- */
	function open() {
		build();
		isOpen = true;
		if (identityOn() && store('lcff_acct')) { holdList(); }
		el.panel.hidden = false;
		el.panel.classList.add('lcff-panel--open');
		if (!held) {
			markSeen();
			flushThumbs(); // else releaseList() runs them
		}
		// One poll right away; it keeps polling only if the server says so.
		if (getToken() && !started) { startPolling(); } else if (started) { schedule(0); }
		checkIdentity();
		armRecheck();
		armHours();
		scrollDown();
		if (!held) { el.input.focus(); } // else releaseList() focuses it
	}

	function close() {
		if (!built) { return; }
		isOpen = false;
		el.panel.hidden = true;
		el.panel.classList.remove('lcff-panel--open');
		armRecheck(); // stops the re-check timer
		armHours();
		if (started) { schedule(); }
	}

	function resume() {
		build();
		if (getToken() && !started) { startPolling(); }
	}

	window.LCFF = { open: open, close: close, resume: resume, logout: logout, continueChat: continueChat };
})();
