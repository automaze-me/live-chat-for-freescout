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
					preview.attr('src', url).removeClass('lcff-hidden');
					remove.removeClass('lcff-hidden');
				});
			}
			frame.open();
		});

		var testButton = $('#lcff_test_connection');
		var testResult = $('#lcff_test_result');

		function showTest(ok, message) {
			testResult
				.text(message)
				.toggleClass('lcff-test-ok', ok)
				.toggleClass('lcff-test-error', !ok);
		}

		testButton.on('click', function (e) {
			e.preventDefault();
			testButton.prop('disabled', true);
			testResult.text('').removeClass('lcff-test-ok lcff-test-error');
			$.ajax({
				url: i18n.ajaxUrl,
				method: 'POST',
				dataType: 'json',
				timeout: 30000,
				data: { action: i18n.testAction, nonce: i18n.testNonce }
			}).done(function (res) {
				showTest(!!(res && res.ok), (res && res.message) || '');
			}).fail(function (xhr) {
				// A 403 from the server carries its own message (data.message); anything else is a generic failure.
				var data = xhr && xhr.responseJSON && xhr.responseJSON.data;
				showTest(false, (data && data.message) || i18n.testFailed || '');
			}).always(function () {
				testButton.prop('disabled', false);
			});
		});

		remove.on('click', function (e) {
			e.preventDefault();
			input.val('0');
			preview.addClass('lcff-hidden').attr('src', '');
			remove.addClass('lcff-hidden');
		});
	});
})(jQuery);
