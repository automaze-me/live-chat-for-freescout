/* Live Chat for FreeScout - chat panel. Plain JS, no dependencies. */
(function () {
	'use strict';

	var cfg = window.LCFF_CONFIG;
	if (!cfg || window.LCFF) {
		return;
	}
	var strings = cfg.strings || {};
	var API = cfg.apiBase;
	var POLL_FAST = 3000;
	var POLL_SLOW = 30000;
	var POLL_MAX = 600000;       // backoff ceiling: 10 min
	var REQUEST_TIMEOUT = 20000; // every request is aborted after 20 s

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
	var seenSent = {};      // agent ids already POSTed to /seen
	var unseenAgentIds = [];
	var unread = 0;
	var pollTimer = null;
	var polling = false;
	var pollStale = false;  // the poll in flight answers from before a send or login
	var failures = 0;       // consecutive failed polls, for the backoff
	var initialDone = false;
	var el = {};

	/* ---- identity state ---- */
	var lastIdentity = null;   // {level, account} from the latest send, poll or identity answer
	var loggedIn = false;      // WordPress said "logged in" on its latest answer
	var confirmLogout = false; // logged out, and the session's first answer decides (see checkIdentity)
	var checking = false;      // a panel-open identity check is running
	var gen = 0;               // bumped by forget(); answers to older requests are dropped

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
		var timer = null;
		function clear() {
			if (timer) { clearTimeout(timer); timer = null; }
		}
		try {
			var headers = {};
			if (token === undefined) { token = getToken(); }
			if (token) { headers['X-Chat-Token'] = token; }
			var init = { method: method, headers: headers, mode: 'cors', credentials: 'omit' };
			if (body !== undefined) {
				headers['Content-Type'] = 'application/json';
				init.body = JSON.stringify(body);
			}
			try {
				if (typeof window.AbortController === 'function') {
					var ctrl = new window.AbortController();
					init.signal = ctrl.signal;
					timer = setTimeout(function () { ctrl.abort(); }, REQUEST_TIMEOUT);
				}
			} catch (e) {}
			return fetch(API + path, init).then(function (res) {
				return res.text().then(function (t) {
					clear();
					var data = null;
					try { data = t ? JSON.parse(t) : null; } catch (e2) {}
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

	/* ---- build ---- */
	function build() {
		if (built) { return; }
		built = true;

		el.panel = mk('div', 'lcff-panel');
		el.panel.setAttribute('role', 'dialog');
		el.panel.setAttribute('aria-label', strings.title || 'Chat');
		el.panel.hidden = true;

		var header = mk('div', 'lcff-header');
		header.appendChild(mk('div', 'lcff-title', strings.title || ''));
		el.close = mk('button', 'lcff-close', '×');
		el.close.type = 'button';
		el.close.setAttribute('aria-label', strings.close || 'Close');
		el.close.addEventListener('click', close);
		header.appendChild(el.close);
		el.panel.appendChild(header);

		el.list = mk('div', 'lcff-messages');
		el.list.setAttribute('aria-live', 'polite');
		el.welcome = mk('div', 'lcff-welcome', strings.welcome || '');
		el.list.appendChild(el.welcome);
		el.panel.appendChild(el.list);

		el.form = mk('form', 'lcff-form');
		el.input = mk('textarea', 'lcff-input');
		el.input.rows = 1;
		el.input.maxLength = 5000;
		el.input.placeholder = strings.placeholder || '';
		el.input.setAttribute('aria-label', strings.placeholder || 'Message');
		el.input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
				e.preventDefault();
				submit();
			}
		});
		el.send = mk('button', 'lcff-send', strings.send || 'Send');
		el.send.type = 'submit';
		el.form.appendChild(el.input);
		el.form.appendChild(el.send);
		el.form.addEventListener('submit', function (e) {
			e.preventDefault();
			submit();
		});
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
		if (role === 'agent' && m.author) {
			n.appendChild(mk('div', 'lcff-author', String(m.author)));
		}
		n.appendChild(mk('div', 'lcff-text', String(m.text)));
		rendered[m.id] = n;
		el.list.appendChild(n);
		return n;
	}

	function addPending(text, clientId) {
		var n = mk('div', 'lcff-message lcff-message--visitor lcff-message--sending');
		n.setAttribute('data-role', 'visitor');
		n.setAttribute('data-client-id', clientId);
		n.appendChild(mk('div', 'lcff-text', text));
		el.list.appendChild(n);
		scrollDown();
		return n;
	}

	/* ---- sending ---- */
	function submit() {
		var text = el.input.value.trim();
		if (!text) { return; }
		if (text.length > 5000) { text = text.slice(0, 5000); }
		el.input.value = '';
		var clientId = uuid();
		var node = addPending(text, clientId);
		enqueue({ node: node, text: text, clientId: clientId });
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
	 * Resolves to the body and chat token of a send. A tokenless send carries
	 * the pending token as `new_token`; when WordPress last said "logged in",
	 * it also carries a fresh login token bound to it (each one is
	 * single-use), so the conversation starts as an account conversation
	 * (Ruling P7). Never rejects.
	 */
	function prepareSend(job) {
		var body = { text: job.text, client_message_id: job.clientId };
		var token = getToken();
		if (token) { return Promise.resolve({ body: body, token: token }); }
		if (!pendingToken) { pendingToken = newToken(); }
		var chat = pendingToken;
		body.new_token = chat;
		var plain = { body: body, token: null };
		if (!loggedIn || !identityOn()) { return Promise.resolve(plain); }
		return wpIdentity(chat).then(function (who) {
			if (who) { loggedIn = who.logged_in; }
			if (who && who.logged_in) { body.login_token = who.token; }
			return plain;
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
			sent = prepareSend(job).then(function (p) {
				// The widget forgot everything while the login token was fetched.
				if (job.gen !== gen) { throw new Error('lcff: forgotten'); }
				return request('POST', '/messages', p.body, p.token);
			});
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
			noteIdentity(r.data.identity);
			if (job.gen !== gen) { return; } // the answer showed a logged-out account
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
		// 409 (same id, different text; or a token taken elsewhere) and 422
		// (invalid input) come back identically on every retry.
		fail(job, r.status !== 409 && r.status !== 422);
	}

	/*
	 * A failed message never blocks the queue: done() moves on to the next one.
	 * Before the first 2xx the next one carries the same pending token, so it
	 * still joins the same session.
	 */
	function fail(job, retryable) {
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
			node.appendChild(mk('div', 'lcff-unsent', strings.unsent || ''));
			return;
		}

		node.classList.add('lcff-message--failed');
		node.setAttribute('role', 'button');
		node.setAttribute('tabindex', '0');
		node.appendChild(mk('div', 'lcff-retry', strings.retry || ''));
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
		if (added && el.list) { scrollDown(); }
		if (active()) { markSeen(); }
	}

	function markSeen() {
		if (unread) { setUnread(0); }
		var ids = unseenAgentIds.filter(function (id) { return !seenSent[id]; });
		unseenAgentIds = [];
		if (lastAgentId() > 0) { store('lcff_seen', String(lastAgentId())); }
		if (!ids.length || !getToken()) { return; }
		ids.slice(0, 100).forEach(function (id) { seenSent[id] = true; });
		request('POST', '/seen', { message_ids: ids.slice(0, 100) }).then(function () {}, function () {
			ids.forEach(function (id) { delete seenSent[id]; });
		});
	}

	function lastAgentId() {
		var max = 0;
		Object.keys(rendered).forEach(function (k) {
			if (rendered[k].getAttribute('data-role') === 'agent' && +k > max) { max = +k; }
		});
		return max;
	}

	/* Never restarts a stopped poller; a running one polls at once when the tab
	 * becomes visible. While it is backing off, the armed timer keeps its
	 * delay (N3): every switch would otherwise restart the full wait. */
	function onVisibility() {
		if (active()) { markSeen(); }
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
	 * fresh one for every use. Resolves to {logged_in, token}, or null on
	 * any failure (Ruling P4: carry on). Never rejects.
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
					if (!d.logged_in) { return { logged_in: false, token: null }; }
					return typeof d.token === 'string' && d.token.indexOf('v1.') === 0
						? { logged_in: true, token: d.token }
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
	 */
	function noteIdentity(identity) {
		if (!identity || typeof identity.level !== 'string') { return; }
		lastIdentity = { level: identity.level, account: identity.account === true };
		if (confirmLogout) {
			confirmLogout = false;
			if (lastIdentity.account) { logout(); }
		}
	}

	/*
	 * A 401: the session behind `used` is gone. The stored token, with the
	 * seen marker and ask state that belong to it, is dropped only while it
	 * is still the token the request used; a newer one stays. Returns
	 * whether it dropped.
	 */
	function unauthorized(used) {
		if (!used || getToken() !== used) { return false; }
		store('lcff_token', null);
		store('lcff_seen', null);
		store('lcff_ask', null);
		pendingToken = null;
		lastIdentity = null;
		stopPolling();
		return true;
	}

	/* Empties the message list; the next session starts from nothing. */
	function clearView() {
		rendered = {};
		seenSent = {};
		unseenAgentIds = [];
		lastId = 0;
		initialDone = false;
		if (unread) { setUnread(0); }
		if (!built) { return; }
		var kids = Array.prototype.slice.call(el.list.childNodes);
		for (var i = 0; i < kids.length; i++) {
			if (kids[i] !== el.welcome) { el.list.removeChild(kids[i]); }
		}
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
		pendingToken = null;
		lastIdentity = null;
		loggedIn = false;
		confirmLogout = false;
		queue.length = 0;
		clearView();
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

	/*
	 * On every panel open, when identity is on: asks WordPress who is logged
	 * in, then
	 * - logged in, token stored: refreshes the account login (Ruling P4);
	 * - logged in, no token: looks for the account's chats with the pending
	 *   token, which is stored only when FreeScout created a session for it
	 *   (Ruling P6);
	 * - logged out while account-bound: logout() (Ruling P5);
	 * - failed: nothing changes (Ruling P4).
	 */
	function checkIdentity() {
		if (checking || !identityOn()) { return; }
		var stored = getToken();
		if (!stored && !pendingToken) { pendingToken = newToken(); }
		var chat = stored || pendingToken;
		var myGen = gen;
		checking = true;
		confirmLogout = false;
		var finish = function () { checking = false; };
		wpIdentity(chat).then(function (who) {
			if (!who || myGen !== gen) { return null; }
			if (!who.logged_in) {
				loggedIn = false;
				if (lastIdentity && lastIdentity.account) {
					logout();
				} else if (!lastIdentity && getToken()) {
					// The session's first answer is still outstanding; it decides.
					confirmLogout = true;
				}
				return null;
			}
			loggedIn = true;
			var now = getToken();
			if (now) {
				// The login token is bound to `chat`. A first send that completed
				// meanwhile stored `chat` itself; any other token can't use it.
				if (now !== chat) { return null; }
				return request('POST', '/identity', { login_token: who.token }, chat).then(function (r) {
					onLogin(r, chat, myGen);
				});
			}
			if (pendingToken !== chat) { return null; }
			return request('POST', '/identity', { login_token: who.token, new_token: chat }, null).then(function (r) {
				onDiscovery(r, chat, myGen);
			});
		}).then(finish, finish);
	}

	function onLogin(r, chat, myGen) {
		if (myGen !== gen) { return; }
		if (r.status === 200 && r.data) {
			noteIdentity(r.data.identity);
			if (myGen === gen) { kick(); }
		} else if (r.status === 401) {
			unauthorized(chat);
		}
		// 403 identity_rejected and anything else: carry on (Ruling P4).
	}

	function onDiscovery(r, chat, myGen) {
		if (myGen !== gen) { return; }
		var d = r.data || {};
		if (r.status === 200 && d.token === chat) {
			// The account has chats here; FreeScout created the session.
			if (!getToken()) { store('lcff_token', chat); }
			if (pendingToken === chat) { pendingToken = null; }
			noteIdentity(d.identity);
			if (myGen === gen) { kick(); }
			return;
		}
		if (r.status === 409 && !getToken() && pendingToken === chat) {
			// The token clashed with an existing session: never use it again.
			pendingToken = newToken();
		}
		// {"linked": false}, 409 and errors: nothing is stored. A first send
		// creates the session, with a login token (loggedIn).
	}

	/* ---- public API ---- */
	function open() {
		build();
		isOpen = true;
		el.panel.hidden = false;
		el.panel.classList.add('lcff-panel--open');
		markSeen();
		// One poll right away; it keeps polling only if the server says so.
		if (getToken() && !started) { startPolling(); } else if (started) { schedule(0); }
		checkIdentity();
		scrollDown();
		el.input.focus();
	}

	function close() {
		if (!built) { return; }
		isOpen = false;
		el.panel.hidden = true;
		el.panel.classList.remove('lcff-panel--open');
		if (started) { schedule(); }
	}

	function resume() {
		build();
		if (getToken() && !started) { startPolling(); }
	}

	window.LCFF = { open: open, close: close, resume: resume, logout: logout };
})();
