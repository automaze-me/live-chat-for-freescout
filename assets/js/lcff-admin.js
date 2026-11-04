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

		remove.on('click', function (e) {
			e.preventDefault();
			input.val('0');
			preview.hide().attr('src', '');
			remove.hide();
		});
	});
})(jQuery);
