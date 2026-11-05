/* Live Chat for FreeScout - settings page: color picker and avatar chooser. */
(function ($) {
	'use strict';

	$(function () {
		if ($.fn.wpColorPicker) {
			$('.lcff-color-field').wpColorPicker();
		}

		var input = $('#lcff_avatar_id');
		var preview = $('#lcff_avatar_preview');
		var remove = $('#lcff_avatar_remove');
		var i18n = window.LCFF_ADMIN || {};
		var frame = null;

		$('#lcff_avatar_choose').on('click', function (e) {
			e.preventDefault();
			if (!window.wp || !wp.media) {
				return;
			}
			if (!frame) {
				frame = wp.media({
					title: i18n.chooseTitle || '',
					button: { text: i18n.chooseButton || '' },
					library: { type: 'image' },
					multiple: false
				});
				frame.on('select', function () {
					var a = frame.state().get('selection').first().toJSON();
					var url = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
					input.val(a.id);
					preview.attr('src', url).show();
					remove.show();
				});
			}
			frame.open();
		});

		var testButton = $('#lcff_test_connection');
		var testResult = $('#lcff_test_result');

		function showTest(ok, message) {
			testResult
				.text(message)
				.css({ color: ok ? '#00a32a' : '#d63638', fontWeight: '600' });
		}

		testButton.on('click', function (e) {
			e.preventDefault();
			testButton.prop('disabled', true);
			testResult.text('').css({ color: '', fontWeight: '' });
			$.ajax({
				url: i18n.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				timeout: 30000,
				data: { action: i18n.testAction, nonce: i18n.testNonce }
			}).done(function (res) {
				showTest(!!(res && res.ok), (res && res.message) || '');
			}).fail(function (xhr, status) {
				var reason = xhr && xhr.status ? 'HTTP ' + xhr.status : status;
				showTest(false, (i18n.unreachable || '%s').replace('%s', reason));
			}).always(function () {
				testButton.prop('disabled', false);
			});
		});

		remove.on('click', function (e) {
			e.preventDefault();
			input.val('0');
			preview.hide().attr('src', '');
			remove.hide();
		});
	});
})(jQuery);
