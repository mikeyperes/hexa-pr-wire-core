(function ($) {
	'use strict';

	const config = window.hprwcForceSync || {};

	function checkedTermIds() {
		const ids = $('#publicationchecklist input[type="checkbox"]:checked, #publicationchecklist-pop input[type="checkbox"]:checked').map(function () {
			return parseInt(this.value, 10) || 0;
		}).get().filter(Boolean);
		return Array.from(new Set(ids)).sort(function (a, b) {
			return a - b;
		});
	}

	function statusText(data) {
		if (!data) {
			return 'No response data.';
		}

		const parts = [data.ok ? 'OK' : 'Issue'];
		if (data.mode) parts.push('mode=' + data.mode);
		if (data.matched !== null && typeof data.matched !== 'undefined') parts.push('matched=' + data.matched);
		if (data.new_count) parts.push('new=' + data.new_count);
		if (data.updated_count) parts.push('updated=' + data.updated_count);
		if (data.redirects_disabled) parts.push('redirects disabled=' + data.redirects_disabled);
		if (data.public_status) parts.push('public HTTP=' + data.public_status);
		if (data.message) parts.push(data.message);

		return parts.join(' | ');
	}

	function responseMessage(xhr, fallback) {
		const json = xhr && xhr.responseJSON ? xhr.responseJSON : null;
		if (json && json.data && json.data.message) {
			return json.data.message;
		}
		return fallback || (config.messages && config.messages.ajaxFailed) || 'The request failed.';
	}

	function createRow(target, previewOnly) {
		const $row = $('<tr>').attr({
			'data-hprwc-row': '',
			'data-publication-id': target.publication_id
		});
		const $checkCell = $('<th>').addClass('check-column');
		$('<input>', {
			type: 'checkbox',
			value: target.publication_id,
			checked: true,
			disabled: !!previewOnly,
			'data-hprwc-source-check': ''
		}).appendTo($checkCell);

		const $publication = $('<td>');
		$('<strong>').text(target.title || '').appendTo($publication);
		$publication.append('<br>');
		$('<code>').text(target.domain || '').appendTo($publication);

		const $url = $('<td>');
		$('<a>', {
			href: target.live_url || '#',
			target: '_blank',
			rel: 'noopener noreferrer'
		}).text(target.live_url || '').appendTo($url);

		const $status = $('<td>').addClass('hprwc-row-status').attr('data-hprwc-row-status', '').text(previewOnly ? 'Save the post to enable this source.' : (target.status || 'Not checked'));
		return $row.append($checkCell, $publication, $url, $status);
	}

	function renderPreview($panel, data) {
		const targets = Array.isArray(data.targets) ? data.targets : [];
		const warnings = Array.isArray(data.warnings) ? data.warnings : [];
		const previewOnly = !data.is_saved;
		const $rows = $panel.find('[data-hprwc-rows]').empty();

		if (!targets.length) {
			$('<tr>').addClass('hprwc-empty-row').append(
				$('<td>').attr('colspan', 4).text((config.messages && config.messages.noRows) || 'No eligible publication sources are selected.')
			).appendTo($rows);
		} else {
			targets.forEach(function (target) {
				$rows.append(createRow(target, previewOnly));
			});
		}

		const $warningBox = $panel.find('[data-hprwc-warning-box]');
		const $warningList = $warningBox.find('[data-hprwc-warnings]').empty();
		warnings.forEach(function (warning) {
			const $item = $('<li>');
			$('<strong>').text((warning.term_name || 'Publication') + ': ').appendTo($item);
			$item.append(document.createTextNode(warning.message || 'Invalid publication mapping.'));
			$warningList.append($item);
		});
		$warningBox.prop('hidden', warnings.length === 0);

		$panel.find('[data-hprwc-unsaved-notice]').prop('hidden', !previewOnly);
		$panel.attr('data-hprwc-can-force', data.can_force ? '1' : '0');
		$panel.attr('data-hprwc-can-check', data.can_check ? '1' : '0');
		$panel.attr('data-hprwc-has-targets', targets.length ? '1' : '0');
		$panel.find('[data-hprwc-run="force"]').prop('disabled', !data.can_force);
		$panel.find('[data-hprwc-run="check"]').prop('disabled', !data.can_check);
		$panel.find('[data-hprwc-select]').prop('disabled', previewOnly || targets.length === 0);
		$panel.find('[data-hprwc-progress]').text(previewOnly ? 'Preview updated. Save the post to enable actions.' : 'Publication sources are saved and ready.');
	}

	let previewTimer = null;
	let previewRequest = null;

	function refreshPreview($panel) {
		if (!$panel.length || !config.ajaxUrl || !config.actions || !config.actions.resolve) {
			return;
		}

		if (previewRequest && previewRequest.readyState !== 4) {
			previewRequest.abort();
		}

		$panel.addClass('is-loading');
		$panel.find('[data-hprwc-spinner]').addClass('is-active');
		$panel.find('[data-hprwc-progress]').text('Resolving selected publication sources…');

		previewRequest = $.post(config.ajaxUrl, {
			action: config.actions.resolve,
			nonce: config.nonce,
			post_id: config.postId || $panel.data('post-id') || 0,
			term_ids: checkedTermIds()
		}).done(function (response) {
			if (response && response.success) {
				renderPreview($panel, response.data || {});
				return;
			}
			$panel.find('[data-hprwc-progress]').text((response && response.data && response.data.message) || 'Publication resolution failed.');
		}).fail(function (xhr, status) {
			if (status !== 'abort') {
				$panel.find('[data-hprwc-progress]').text(responseMessage(xhr));
			}
		}).always(function () {
			$panel.removeClass('is-loading');
			$panel.find('[data-hprwc-spinner]').removeClass('is-active');
		});
	}

	function schedulePreview($panel) {
		window.clearTimeout(previewTimer);
		previewTimer = window.setTimeout(function () {
			refreshPreview($panel);
		}, 180);
	}

	function installTaxonomyControls($panel) {
		const $box = $('#publicationdiv');
		const $checklists = $('#publicationchecklist, #publicationchecklist-pop');
		if (!$box.length || !$checklists.length) {
			return;
		}

		// Remove the legacy snippet controls so the plugin remains the sole UI owner.
		$box.find('.publication-bulk-actions, .hprwc-publication-bulk-actions').remove();
		const $controls = $('<p>').addClass('hprwc-publication-bulk-actions');
		$('<button>', { type: 'button', class: 'button button-small', 'data-hprwc-taxonomy': 'all', text: 'Check all' }).appendTo($controls);
		$controls.append(document.createTextNode(' '));
		$('<button>', { type: 'button', class: 'button button-small', 'data-hprwc-taxonomy': 'none', text: 'Uncheck all' }).appendTo($controls);
		$box.find('.inside').prepend($controls);

		$box.off('.hprwc');
		$box.on('click.hprwc', '[data-hprwc-taxonomy]', function () {
			const checked = $(this).data('hprwc-taxonomy') === 'all';
			$checklists.find('input[type="checkbox"]').prop('checked', checked);
			schedulePreview($panel);
		});

		$checklists.off('.hprwc').on('change.hprwc', 'li > label > input[type="checkbox"]', function () {
			const $checkbox = $(this);
			const checked = $checkbox.prop('checked');
			const termId = parseInt($checkbox.val(), 10) || 0;
			if (termId) {
				$checklists.find('input[type="checkbox"][value="' + termId + '"]').prop('checked', checked);
			}
			const $children = $checkbox.closest('li').children('ul.children').find('input[type="checkbox"]').prop('checked', checked);
			$children.each(function () {
				const childId = parseInt(this.value, 10) || 0;
				if (childId) {
					$checklists.find('input[type="checkbox"][value="' + childId + '"]').prop('checked', checked);
				}
			});
			schedulePreview($panel);
		});

		if (config.autoSelectAll && !$checklists.first().data('hprwc-auto-selected')) {
			$checklists.data('hprwc-auto-selected', true).find('input[type="checkbox"]').prop('checked', true);
			schedulePreview($panel);
		}
	}

	function runRow($panel, $row, mode) {
		$row.removeClass('hprwc-ok hprwc-error').addClass('hprwc-running');
		$row.find('[data-hprwc-row-status]').text('Running…');

		return $.post(config.ajaxUrl, {
			action: config.actions.force,
			nonce: config.nonce,
			post_id: config.postId || $panel.data('post-id') || 0,
			publication_id: $row.data('publication-id'),
			mode: mode
		}).done(function (response) {
			const data = response && response.data ? response.data : {};
			$row.removeClass('hprwc-running hprwc-error').toggleClass('hprwc-ok', !!(response && response.success));
			if (!(response && response.success)) $row.addClass('hprwc-error');
			$row.find('[data-hprwc-row-status]').text(statusText(data));
		}).fail(function (xhr) {
			$row.removeClass('hprwc-running hprwc-ok').addClass('hprwc-error');
			$row.find('[data-hprwc-row-status]').text(responseMessage(xhr, 'Force Sync request failed.'));
		});
	}

	function runSelected($panel, mode) {
		const $rows = $panel.find('[data-hprwc-row]').filter(function () {
			return $(this).find('[data-hprwc-source-check]').is(':checked:not(:disabled)');
		});
		const $progress = $panel.find('[data-hprwc-progress]');
		const total = $rows.length;
		let index = 0;

		if (!total) {
			$progress.text('Select at least one saved publication source.');
			return;
		}

		$panel.find('button').prop('disabled', true);
		$panel.find('[data-hprwc-spinner]').addClass('is-active');

		function next() {
			if (index >= total) {
				$progress.text('Complete: ' + total + '/' + total + '.');
				$panel.find('[data-hprwc-spinner]').removeClass('is-active');
				const canForce = $panel.attr('data-hprwc-can-force') === '1';
				const canCheck = $panel.attr('data-hprwc-can-check') === '1';
				const hasTargets = $panel.attr('data-hprwc-has-targets') === '1';
				$panel.find('[data-hprwc-run="force"]').prop('disabled', !canForce);
				$panel.find('[data-hprwc-run="check"]').prop('disabled', !canCheck);
				$panel.find('[data-hprwc-select]').prop('disabled', !hasTargets || !canCheck);
				return;
			}

			const $row = $rows.eq(index);
			$progress.text('Running ' + (index + 1) + '/' + total + '…');
			runRow($panel, $row, mode).always(function () {
				index += 1;
				next();
			});
		}

		next();
	}

	$(function () {
		const $panel = $('[data-hprwc-panel]').first();
		if (!$panel.length) {
			return;
		}

		// Let any legacy DOM-ready callback finish, then normalize ownership.
		window.setTimeout(function () {
			installTaxonomyControls($panel);
		}, 0);

		$panel.on('click', '[data-hprwc-run]', function () {
			runSelected($panel, $(this).data('hprwc-run') === 'check' ? 'check' : 'force');
		});

		$panel.on('click', '[data-hprwc-select]', function () {
			const checked = $(this).data('hprwc-select') === 'all';
			$panel.find('[data-hprwc-source-check]:not(:disabled)').prop('checked', checked);
		});
	});
})(jQuery);
