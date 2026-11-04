/* Live Chat for FreeScout - bubble and lazy loader. Plain JS, no dependencies. */
(function () {
	'use strict';

	var cfg = window.LCFF_CONFIG;
	if (!cfg || !cfg.apiBase || !cfg.panelUrl || window.LCFF_LOADER_STARTED) {
		return;
	}
	window.LCFF_LOADER_STARTED = true;
	var strings = cfg.strings || {};

	function hasStoredToken() {
		try {
			return !!window.localStorage.getItem('lcff_token');
		} catch (e) {
			return false;
		}
	}

	var bubble = document.createElement('button');
	bubble.type = 'button';
	bubble.className = 'lcff-bubble';
	bubble.setAttribute('aria-label', strings.title || 'Chat');

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

	function loadPanel() {
		if (window.LCFF) {
			return Promise.resolve();
		}
		if (loading) {
			return loading;
		}
		loading = new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = cfg.panelUrl;
			s.async = true;
			s.onload = function () {
				resolve();
			};
			s.onerror = function () {
				loading = null;
				reject(new Error('lcff panel failed to load'));
			};
			document.head.appendChild(s);
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

	/* Returning visitor: load right away so history and unread badge work. */
	if (hasStoredToken()) {
		loadPanel().then(function () {
			if (window.LCFF) {
				window.LCFF.resume();
			}
		}, function () {});
	}
})();
