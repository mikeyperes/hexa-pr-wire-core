(function ($) {
	'use strict';

	$(document).on('click', '[data-hprwc-notify]', function () {
		const $button = $(this);
		const $box = $button.closest('[data-hprwc-notifications]');
		const $status = $box.find('[data-hprwc-notify-status]');
		const type = $button.data('hprwc-notify') === 'draft' ? 'draft' : 'live';
		const action = type === 'draft' ? 'hprwc_notify_draft_update' : 'hprwc_send_delivery_links';

		$button.addClass('is-busy').prop('disabled', true);
		$status.text('Sending…');

		$.post(window.ajaxurl, {
			action: action,
			nonce: $box.data('nonce'),
			post_id: $box.data('post-id')
		}).done(function (response) {
			const data = response && response.data ? response.data : {};
			$status.text(data.message || (response && response.success ? 'Notification sent.' : 'Notification failed.'));
		}).fail(function (xhr) {
			const data = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
			$status.text(data.message || 'Notification failed. Refresh and try again.');
		}).always(function () {
			$button.removeClass('is-busy').prop('disabled', false);
		});
	});
})(jQuery);
