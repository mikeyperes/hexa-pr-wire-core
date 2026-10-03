(function ($) {
	'use strict';

	function initEntitlements($box) {
		const $rows = $box.find('.hprwc-pub');
		const column = () => ({ restricted: 'allow', excluded: 'exclude' }[$box.attr('data-mode')] || '');

		function refresh() {
			const blocked = new Set();
			$rows.each(function () {
				const $row = $(this);
				const inherited = blocked.has(String($row.data('parent')));
				$row.toggleClass('is-inherited', inherited);
				if (inherited || $row.find('.hprwc-col-exclude input').is(':checked')) {
					blocked.add(String($row.data('term')));
				}
			});

			const total = $rows.length;
			let text = total + ' publications, all open';
			if (column() === 'allow') {
				text = $rows.find('.hprwc-col-allow input:checked').length + ' of ' + total + ' can publish';
			} else if (column() === 'exclude') {
				text = $rows.filter('.is-inherited').add($rows.has('.hprwc-col-exclude input:checked')).length + ' of ' + total + ' blocked';
			}
			$box.find('.hprwc-summary').text(text);
		}

		$box.on('change', 'input[name="hprwc_publication_access_mode"]', function () {
			$box.attr('data-mode', this.value);
			refresh();
		});
		$box.on('change', '.hprwc-switch input', refresh);
		$box.on('click', '.hprwc-pub-name', function () {
			if (column()) {
				$(this).closest('.hprwc-pub').find('.hprwc-col-' + column() + ' input').trigger('click');
			}
		});
		$box.on('input', '.hprwc-search', function () {
			const query = this.value.trim().toLowerCase();
			let visible = 0;
			$rows.each(function () {
				const match = !query || String($(this).data('name')).includes(query);
				$(this).prop('hidden', !match);
				visible += match ? 1 : 0;
			});
			$box.find('.hprwc-empty').prop('hidden', visible > 0);
		});
		$box.on('click', '[data-bulk]', function () {
			if (column()) {
				$rows.not('[hidden]').find('.hprwc-col-' + column() + ' input').prop('checked', $(this).data('bulk') === 1);
				refresh();
			}
		});
		refresh();
	}

	$(function () {
		if ($('body').hasClass('user-new-php')) {
			const $email = $('#email').closest('tr');
			const $username = $('#user_login').closest('tr');
			if ($email.length && $username.length) {
				$username.before($email);
			}
		}
		$('.hprwc-entitlements').each(function () {
			initEntitlements($(this));
		});
	});
})(jQuery);
