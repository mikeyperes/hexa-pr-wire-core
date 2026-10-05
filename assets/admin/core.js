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


	/** Hexa PR Wire → Publications: filters plus live Distributor health checks, four at a time. */
	function initConnections($box) {
		if ($box.data('hprwc-init')) {
			return;
		}
		$box.data('hprwc-init', true);
		const $rows = $box.find('tbody tr');
		const $summary = $box.find('[data-conn-summary]');
		let running = false;

		function applyFilter(filter) {
			$box.find('[data-conn-filter]').removeClass('is-active').filter('[data-conn-filter="' + filter + '"]').addClass('is-active');
			$rows.each(function () {
				const $row = $(this);
				const show = filter === 'all' || (filter === 'inactive' ? $row.data('active') === 0 : $row.data('type') === filter);
				$row.prop('hidden', !show);
			});
		}

		function setLive($row, state, label, lines) {
			const $cell = $row.find('[data-conn-live]').empty();
			const $state = $('<span>').addClass('hprwc-state').attr('data-state', state).appendTo($cell);
			if (state === 'running') {
				$('<span>').addClass('hprwc-spin').attr('aria-hidden', 'true').appendTo($state);
			}
			$state.append(document.createTextNode(label));
			(lines || []).forEach(function (line) {
				$('<div>').addClass('hprwc-conn-meta').text(line).appendTo($cell);
			});
		}

		function summarize() {
			const $checked = $rows.filter('[data-checkable="1"]');
			const ok = $checked.find('.hprwc-state[data-state="ok"]').length;
			const warn = $checked.find('.hprwc-state[data-state="warn"]').length;
			const bad = $checked.find('.hprwc-state[data-state="error"]').length;
			$summary.text('Managed outlets: ' + ok + ' connected' + (warn ? ', ' + warn + ' need attention' : '') + (bad ? ', ' + bad + ' failing' : '') + ' of ' + $checked.length + '.');
		}

		function checkAll() {
			if (running) {
				return;
			}
			const queue = $rows.filter('[data-checkable="1"]').toArray();
			if (!queue.length) {
				$summary.text('No managed outlets to check.');
				return;
			}
			running = true;
			const $button = $box.find('[data-conn-refresh]').prop('disabled', true).addClass('is-busy');
			queue.forEach(function (row) { setLive($(row), 'running', 'Checking…'); });
			$summary.text('Checking ' + queue.length + ' managed outlets…');
			let active = 0;
			function next() {
				if (!queue.length && !active) {
					running = false;
					$button.prop('disabled', false).removeClass('is-busy');
					summarize();
					return;
				}
				while (active < 4 && queue.length) {
					const $row = $(queue.shift());
					active += 1;
					$.post($box.data('ajax-url'), { action: $box.data('action'), nonce: $box.data('nonce'), publication_id: $row.data('id') })
						.done(function (response) {
							const data = response && response.data ? response.data : {};
							if (response && response.success) {
								setLive($row, data.state, data.label, data.lines);
							} else {
								setLive($row, 'error', 'Error', [data.message || 'Check failed.']);
							}
						})
						.fail(function (xhr) {
							setLive($row, 'error', 'Error', ['HTTP ' + (xhr && xhr.status ? xhr.status : 0)]);
						})
						.always(function () {
							active -= 1;
							next();
						});
				}
			}
			next();
		}

		$box.on('click', '[data-conn-filter]', function () { applyFilter($(this).data('conn-filter')); });
		$box.on('click', '[data-conn-refresh]', checkAll);
		checkAll();
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
		$('.hprwc-connections').each(function () {
			initConnections($(this));
		});
	});

	// Dashboard tabs load over AJAX; initialize the Publications panel when it arrives.
	document.addEventListener('hexa-core-host-tab-loaded', function () {
		$('.hprwc-connections').each(function () {
			initConnections($(this));
		});
	});
})(jQuery);
