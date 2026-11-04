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

	function request(method, path, body, useToken) {
		var headers = {};
		var token = getToken();
		if (useToken !== false && token) { headers['X-Chat-Token'] = token; }
		var init = { method: method, headers: headers, mode: 'cors', credentials: 'omit' };
		if (body !== undefined) {
			headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(body);
		}
		return fetch(API + path, init).then(function (res) {
			return res.text().then(function (t) {
				var data = null;
				try { data = t ? JSON.parse(t) : null; } catch (e) {}
				return { status: res.status, data: data, hadToken: !!token };
			});
		});
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
	 * The token is read when a POST is dispatched (see request()), so messages
	 * queued behind the first one carry the token the first response issued and
	 * all land in the same conversation.
	 */
	var queue = [];
	var inFlight = false;

	function enqueue(job) {
		if (job.node.getAttribute('data-queued') === '1') { return; }
		job.node.setAttribute('data-queued', '1');
		job.node.classList.remove('lcff-message--failed');
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
		var done = function () {
			job.node.removeAttribute('data-queued');
			inFlight = false;
			pump();
		};
		request('POST', '/messages', { text: job.text, client_message_id: job.clientId }).then(function (r) {
			var node = job.node;
			if ((r.status === 201 || r.status === 200) && r.data && r.data.message) {
				if (r.data.token) { store('lcff_token', r.data.token); }
				var dto = r.data.message;
				node.classList.remove('lcff-message--sending');
				if (rendered[dto.id] && rendered[dto.id] !== node) {
					// A poll already rendered this message; drop the pending copy.
					if (node.parentNode) { node.parentNode.removeChild(node); }
				} else {
					node.setAttribute('data-id', String(dto.id));
					rendered[dto.id] = node;
				}
				if (!started) { startPolling(); }
			} else {
				if (r.status === 401 && r.hadToken) {
					store('lcff_token', null);
					stopPolling();
				}
				fail(job);
			}
			done();
		}, function () {
			fail(job);
			done();
		});
	}

	/*
	 * A failed message does not block the queue while a session exists (a
	 * permanent 422/409 must not stall the chat). Without a token, though, every
	 * later queued message would open its own new session, so those are put into
	 * the retry state too; the visitor retries them in any order and the first
	 * success issues the token the rest then share.
	 */
	function fail(job) {
		failNode(job);
		if (!getToken()) {
			var rest = queue;
			queue = [];
			rest.forEach(failNode);
		}
	}

	function failNode(job) {
		var node = job.node;
		node.removeAttribute('data-queued');
		node.classList.remove('lcff-message--sending');
		node.classList.add('lcff-message--failed');
		node.setAttribute('role', 'button');
		node.setAttribute('tabindex', '0');
		if (!node.querySelector('.lcff-retry')) {
			node.appendChild(mk('div', 'lcff-retry', strings.retry || ''));
		}
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

	function schedule(delay) {
		if (pollTimer) { clearTimeout(pollTimer); }
		if (!started) { return; }
		pollTimer = setTimeout(poll, delay === undefined ? (active() ? POLL_FAST : POLL_SLOW) : delay);
	}

	function poll() {
		pollTimer = null;
		if (!started || polling) { return; }
		if (!getToken()) { started = false; return; }
		polling = true;
		request('GET', '/messages?after=' + encodeURIComponent(lastId)).then(function (r) {
			polling = false;
			if (r.status === 401) {
				store('lcff_token', null);
				stopPolling();
				return;
			}
			if (r.status === 200 && r.data && Array.isArray(r.data.messages)) {
				handleMessages(r.data.messages);
			}
			schedule();
		}, function () {
			polling = false;
			schedule();
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

	function onVisibility() {
		if (!started) { return; }
		if (active()) { markSeen(); }
		schedule(visible() ? 0 : undefined);
	}

	/* ---- public API ---- */
	function open() {
		build();
		isOpen = true;
		el.panel.hidden = false;
		el.panel.classList.add('lcff-panel--open');
		markSeen();
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
