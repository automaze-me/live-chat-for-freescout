/* Live Chat for FreeScout - bubble and lazy loader. Plain JS, no dependencies. */
(function () {
	'use strict';

	var cfg = window.LCFF_CONFIG;
	if (!cfg || !cfg.apiBase || !cfg.panelUrl || window.LCFF_LOADER_STARTED) {
		return;
	}
	window.LCFF_LOADER_STARTED = true;

	/*
	 * Every key the widget keeps in this browser (readme, "Privacy"):
	 * localStorage holds the session (the chat token, the seen marker, the
	 * ask state, the account marker), sessionStorage this tab's feature
	 * flag. Defined once, here: the loader runs first and clears them after
	 * a logout; the panel it loads reads the same lists to drop a session.
	 */
	var STORAGE = window.LCFF_STORAGE = {
		local: ['lcff_token', 'lcff_seen', 'lcff_ask', 'lcff_acct'],
		session: ['lcff_page']
	};

	var strings = cfg.strings || {};
	// A cached page can lack a key; the built-in English text is the fallback.
	var DEFAULT_STRINGS = { title: 'Chat with us' };
	function str(key) {
		return strings[key] || DEFAULT_STRINGS[key] || '';
	}

	function storedToken() {
		try {
			return window.localStorage.getItem('lcff_token');
		} catch (e) {
			return null;
		}
	}

	/* WordPress sets lcff_logout=1 (600 s, JS-readable) when the visitor logs out. */
	function logoutSignalled() {
		try {
			return /(?:^|;\s*)lcff_logout=1(?:;|$)/.test(document.cookie);
		} catch (e) {
			return false;
		}
	}

	/*
	 * Expires the signal. WordPress set it with path COOKIEPATH (and
	 * COOKIE_DOMAIN when defined), neither known here, so every path prefix
	 * of this page and every parent domain is tried. The cookie is visible
	 * here, so its path is one of those prefixes.
	 */
	function expireLogoutSignal() {
		var paths = ['/'];
		var segs = window.location.pathname.split('/');
		var p = '';
		for (var i = 1; i < segs.length - 1; i++) {
			p += '/' + segs[i];
			paths.push(p + '/');
		}
		var domains = [''];
		var host = window.location.hostname.split('.');
		for (var j = 0; j < host.length - 1; j++) {
			domains.push('; domain=' + host.slice(j).join('.'));
		}
		for (var a = 0; a < paths.length; a++) {
			for (var b = 0; b < domains.length; b++) {
				try {
					document.cookie = 'lcff_logout=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=' + paths[a] + domains[b];
				} catch (e) {}
			}
		}
	}

	/*
	 * Ruling P5 on the logout signal: FreeScout's session forgets the
	 * account, this browser forgets the session, and the signal is removed.
	 * It runs before the panel is loaded, so it clears the STORAGE keys
	 * itself (as the panel's dropSession() does).
	 */
	function forgetAfterLogout() {
		var t = storedToken();
		if (t) {
			try {
				fetch(cfg.apiBase + '/logout', {
					method: 'POST',
					headers: { 'X-Chat-Token': t },
					credentials: 'omit'
				}).then(null, function () {});
			} catch (e) {}
		}
		var k;
		for (k = 0; k < STORAGE.local.length; k++) {
			try {
				window.localStorage.removeItem(STORAGE.local[k]);
			} catch (e2) {}
		}
		for (k = 0; k < STORAGE.session.length; k++) {
			try {
				window.sessionStorage.removeItem(STORAGE.session[k]);
			} catch (e3) {}
		}
		expireLogoutSignal();
	}

	var bubble = document.createElement('button');
	bubble.type = 'button';
	bubble.className = 'lcff-bubble';
	bubble.setAttribute('aria-label', cfg.teamName || str('title'));
	if (/^#[0-9a-f]{6}$/i.test(cfg.accent || '')) {
		bubble.style.setProperty('--lcff-accent', cfg.accent);
	}
	if (cfg.position === 'left') {
		bubble.classList.add('lcff-left');
	}

	var icon = document.createElement('span');
	icon.className = 'lcff-bubble-icon';
	icon.setAttribute('aria-hidden', 'true');
	bubble.appendChild(icon);

	var badge = document.createElement('span');
	badge.className = 'lcff-badge';
	badge.hidden = true;
	bubble.appendChild(badge);

	/* The bubble and, later, the panel live in one root element; the
	 * stylesheet resets everything inside it against the theme's CSS. */
	var root = document.createElement('div');
	root.id = 'lcff-root';
	root.appendChild(bubble);
	document.body.appendChild(root);

	/* The panel announces unread counts through this event. */
	document.addEventListener('lcff:unread', function (e) {
		var n = (e && e.detail && e.detail.count) || 0;
		badge.textContent = n > 99 ? '99+' : String(n);
		badge.hidden = n < 1;
	});

	var loading = null;

	function loadScript(url) {
		return new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = url;
			s.async = true;
			s.onload = function () {
				resolve();
			};
			s.onerror = function () {
				reject(new Error('lcff script failed to load: ' + url));
			};
			document.head.appendChild(s);
		});
	}

	function loadPanel() {
		if (window.LCFF) {
			return Promise.resolve();
		}
		if (loading) {
			return loading;
		}
		/* The hours script goes first so the panel can use it when it builds. A
		 * failure there only costs the status line, never the chat. */
		var hours = cfg.hours && cfg.hoursUrl && !window.LCFF_HOURS
			? loadScript(cfg.hoursUrl).then(null, function () {})
			: Promise.resolve();
		loading = hours.then(function () {
			return loadScript(cfg.panelUrl);
		}).then(null, function (err) {
			loading = null;
			throw err;
		});
		return loading;
	}

	/*
	 * A continue link (#lcff-continue=…) waiting for the panel. It stays here
	 * until the panel has it, so a panel that failed to load gets it on the
	 * next bubble click. Nothing is redeemed here: the panel asks for one
	 * click first.
	 */
	var pendingLink = null;

	/* Hands the waiting link to a loaded panel, which opens with the prompt. Returns whether there was one. */
	function handOverLink() {
		if (!pendingLink || !window.LCFF) {
			return false;
		}
		var link = pendingLink;
		pendingLink = null;
		window.LCFF.continueChat(link);
		return true;
	}

	bubble.addEventListener('click', function () {
		loadPanel().then(function () {
			if (window.LCFF && !handOverLink()) {
				window.LCFF.open();
			}
		}, function () {});
	});

	/*
	 * Takes a continue link out of the address bar (P11: the secret leaves
	 * it before anything else runs). Returns the link, or null when the
	 * fragment is not one.
	 */
	function takeContinueLink() {
		var m = /^#lcff-continue=([A-Za-z0-9_-]{43})$/.exec(window.location.hash || '');
		if (!m) {
			return null;
		}
		try {
			window.history.replaceState(null, '', window.location.pathname + window.location.search);
		} catch (e) {}
		return m[1];
	}

	function continueLink(link) {
		pendingLink = link;
		loadPanel().then(handOverLink, function () {});
	}

	/* Just logged out: forget, and don't resume. Returning visitor: load right
	 * away so history and unread badge work. */
	var startLink = takeContinueLink();

	if (logoutSignalled()) {
		forgetAfterLogout();
	} else if (storedToken() && !startLink) {
		loadPanel().then(function () {
			if (window.LCFF) {
				window.LCFF.resume();
			}
		}, function () {});
	}

	if (startLink) {
		continueLink(startLink);
	}

	/* A link opened on a page that is already showing (only the fragment changed). */
	window.addEventListener('hashchange', function () {
		var link = takeContinueLink();
		if (link) {
			continueLink(link);
		}
	});
})();
