/* Live Chat for FreeScout - bubble and lazy loader. Plain JS, no dependencies. */
(function () {
	'use strict';

	var cfg = window.LCFF_CONFIG;
	if (!cfg || !cfg.apiBase || !cfg.panelUrl || window.LCFF_LOADER_STARTED) {
		return;
	}
	window.LCFF_LOADER_STARTED = true;
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
		var keys = ['lcff_token', 'lcff_seen', 'lcff_ask', 'lcff_acct'];
		for (var k = 0; k < keys.length; k++) {
			try {
				window.localStorage.removeItem(keys[k]);
			} catch (e2) {}
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

	document.body.appendChild(bubble);

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

	bubble.addEventListener('click', function () {
		loadPanel().then(function () {
			if (window.LCFF) {
				window.LCFF.open();
			}
		}, function () {});
	});

	/* Just logged out: forget, and don't resume. Returning visitor: load right
	 * away so history and unread badge work. */
	var continueMatch = /^#lcff-continue=([A-Za-z0-9_-]{43})$/.exec(window.location.hash || '');
	if (continueMatch) {
		/* P11: the secret leaves the address bar before anything else runs. */
		try {
			window.history.replaceState(null, '', window.location.pathname + window.location.search);
		} catch (e) {}
	}

	if (logoutSignalled()) {
		forgetAfterLogout();
	} else if (storedToken() && !continueMatch) {
		loadPanel().then(function () {
			if (window.LCFF) {
				window.LCFF.resume();
			}
		}, function () {});
	}

	/* Nothing is redeemed here: the panel asks for one click first. */
	if (continueMatch) {
		var linkToken = continueMatch[1];
		loadPanel().then(function () {
			if (window.LCFF) {
				window.LCFF.continueChat(linkToken);
			}
		}, function () {});
	}
})();
