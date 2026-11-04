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

	function dropToken() {
		store('lcff_token', null);
		pendingToken = null;
	}

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
	var failures = 0;       // consecutive failed polls, for the backoff
	var initialDone = false;
	var el = {};

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
	 * Resolves to { status, data, hadToken }; rejects on a network error or
	 * after REQUEST_TIMEOUT (where AbortController exists). Never throws.
	 */
	function request(method, path, body) {
		var timer = null;
		function clear() {
			if (timer) { clearTimeout(timer); timer = null; }
		}
		try {
			var headers = {};
			var token = getToken();
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
					return { status: res.status, data: data, hadToken: !!token };
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
	 * The token is read when a POST is dispatched (see request()). Before the
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

	function pump() {
		if (inFlight || !queue.length) { return; }
		inFlight = true;
		var job = queue.shift();
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
			var body = { text: job.text, client_message_id: job.clientId };
			if (!getToken()) {
				if (!pendingToken) { pendingToken = newToken(); }
				body.new_token = pendingToken;
			}
			sent = request('POST', '/messages', body);
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
			// A message was just sent, so there is something to wait for again,
			// and the server is reachable: a backing-off poller is rescheduled.
			var wasBackingOff = failures > 0;
			failures = 0;
			if (!started) { startPolling(); } else if (wasBackingOff) { schedule(); }
			return;
		}
		if (r.status === 401 && r.hadToken) {
			dropToken();
			stopPolling();
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
		request('GET', '/messages?after=' + encodeURIComponent(lastId)).then(function (r) {
			var keep = true;
			try {
				if (r.status === 401) {
					dropToken();
					keep = false;
					return;
				}
				if (r.status < 200 || r.status > 299) {
					failures = Math.min(failures + 1, 30);
					return;
				}
				failures = 0;
				var data = r.data || {};
				keep = !!(data.conversation && data.conversation.poll !== false);
				if (Array.isArray(data.messages)) {
					handleMessages(data.messages);
				}
			} finally {
				polling = false;
				if (keep) { schedule(); } else { stopPolling(); }
			}
		}, function () {
			// Network error or timeout.
			polling = false;
			failures = Math.min(failures + 1, 30);
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
	 * becomes visible, unless it is backing off. */
	function onVisibility() {
		if (active()) { markSeen(); }
		if (!started) { return; }
		schedule(visible() && !failures ? 0 : undefined);
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

	window.LCFF = { open: open, close: close, resume: resume };
})();
