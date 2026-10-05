(function ($) {
	'use strict';

	const config = window.hprwcForceSync || {};
	const CONCURRENCY = 4;
	const PUSH_POLL_MS = 4000;
	const PUSH_POLL_LIMIT = 45;

	let $panel = $();
	let isAdmin = false;
	let state = { targets: [], published: false, can_force: false, is_saved: true };
	let resolveRequest = null;
	let resolveTimer = null;
	let busy = false;

	function checkedTermIds() {
		const ids = $('#publicationchecklist input[type="checkbox"]:checked, #publicationchecklist-pop input[type="checkbox"]:checked, #hprwc-outlets input[type="checkbox"]:checked').map(function () {
			return parseInt(this.value, 10) || 0;
		}).get().filter(Boolean);
		return Array.from(new Set(ids)).sort(function (a, b) {
			return a - b;
		});
	}

	function responseMessage(xhr) {
		const json = xhr && xhr.responseJSON ? xhr.responseJSON : null;
		return json && json.data && json.data.message ? json.data.message : 'The request failed. Reload the editor and try again.';
	}

	function setLoading(loading, text) {
		$panel.attr('aria-busy', loading ? 'true' : 'false').toggleClass('is-loading', loading);
		$panel.find('[data-hprwc-loader]').prop('hidden', !loading || state.targets.length > 0);
		if (loading && text) {
			$panel.find('[data-hprwc-summary]').text(text);
		}
	}

	function setNotice(text, tone) {
		const $notice = $panel.find('[data-hprwc-notice]');
		$notice.attr('data-tone', tone || 'info').text(text || '').prop('hidden', !text);
	}

	function syncButtons() {
		const savedRows = state.targets.filter(function (t) { return t.saved; }).length;
		$panel.find('[data-hprwc-run="check"]').prop('disabled', busy || !state.published || !state.is_saved || !savedRows);
		$panel.find('[data-hprwc-run="force"]').prop('disabled', busy || !state.can_force || !state.is_saved || !savedRows);
		$panel.find('[data-hprwc-select-bar]').prop('hidden', !isAdmin || !savedRows);
	}

	function summarize() {
		const counts = { live: 0, missing: 0, error: 0, other: 0 };
		state.targets.forEach(function (t) {
			const key = t.status && counts.hasOwnProperty(t.status.state) ? t.status.state : 'other';
			counts[key] += 1;
		});
		const total = state.targets.length;
		if (!total) {
			return 'No outlets selected. Choose outlets in Publications.';
		}
		const parts = [counts.live + ' of ' + total + ' live'];
		if (counts.missing) parts.push(counts.missing + ' not created');
		if (counts.error) parts.push(counts.error + ' with errors');
		return parts.join(' · ');
	}

	function renderStatus($row, status, running) {
		const s = status || { state: 'unchecked', label: 'Not checked', detail: '' };
		$row.attr('data-state', running ? 'running' : s.state);
		const $pill = $row.find('[data-hprwc-pill]').empty();
		if (running) {
			$('<span>').addClass('hprwc-spin').attr('aria-hidden', 'true').appendTo($pill);
		}
		$pill.append(document.createTextNode(running ? running : s.label));
		$row.find('[data-hprwc-detail]').text(running ? '' : (s.detail || ''));
	}

	function rowFor(target) {
		const $row = $('<li>').addClass('hprwc-row').attr('data-publication-id', target.publication_id);
		if (isAdmin) {
			$('<input>', { type: 'checkbox', class: 'hprwc-row__check', checked: true, disabled: !target.saved, 'aria-label': 'Select ' + target.title }).appendTo($row);
		}
		const $main = $('<div>').addClass('hprwc-row__main').appendTo($row);
		$('<span>').addClass('hprwc-row__name').text(target.title).appendTo($main);
		$('<a>', { href: target.live_url, target: '_blank', rel: 'noopener noreferrer', class: 'hprwc-row__url' }).text(target.domain).appendTo($main);
		const $status = $('<div>').addClass('hprwc-row__status').appendTo($row);
		$('<span>').addClass('hprwc-pill').attr('data-hprwc-pill', '').appendTo($status);
		$('<span>').addClass('hprwc-row__detail').attr('data-hprwc-detail', '').appendTo($status);
		renderStatus($row, target.status);
		return $row;
	}

	function render(data) {
		state = $.extend({ targets: [] }, data);
		const $rows = $panel.find('[data-hprwc-rows]').empty();
		state.targets.forEach(function (target) {
			$rows.append(rowFor(target));
		});

		const $warnings = $panel.find('[data-hprwc-warnings]').empty();
		(state.warnings || []).forEach(function (w) {
			$('<li>').text((w.term_name || 'Publication') + ': ' + (w.message || '')).appendTo($warnings);
		});
		$warnings.prop('hidden', !(state.warnings || []).length);

		if (!state.is_saved) {
			setNotice('Publication changes are not saved yet. Update the release to send it to the new outlets.', 'warning');
		} else if (!state.published && state.targets.length) {
			setNotice('Outlets receive this release when it is published.', 'info');
		} else {
			setNotice('');
		}
		$panel.find('[data-hprwc-summary]').text(summarize());
		syncButtons();
	}

	function updateRow(publicationId, status) {
		state.targets.forEach(function (t) {
			if (t.publication_id === publicationId) t.status = status;
		});
		renderStatus($panel.find('.hprwc-row[data-publication-id="' + publicationId + '"]'), status);
	}

	function resolve(withSelection) {
		if (resolveRequest && resolveRequest.readyState !== 4) {
			resolveRequest.abort();
		}
		const payload = { action: config.actions.resolve, nonce: config.nonce, post_id: config.postId };
		if (withSelection) {
			const ids = checkedTermIds();
			payload.term_ids = ids.length ? ids : '';
		}
		setLoading(true, 'Updating outlets…');
		resolveRequest = $.post(config.ajaxUrl, payload);
		return resolveRequest.done(function (response) {
			if (response && response.success) {
				render(response.data || {});
			} else {
				setNotice((response && response.data && response.data.message) || 'Could not load outlets.', 'error');
			}
		}).fail(function (xhr, status) {
			if (status !== 'abort') setNotice(responseMessage(xhr), 'error');
		}).always(function () {
			setLoading(false);
		});
	}

	/** Run check or force for each selected saved outlet, a few at a time. */
	function run(mode) {
		const rows = state.targets.filter(function (t) {
			if (!t.saved) return false;
			if (!isAdmin) return true;
			return $panel.find('.hprwc-row[data-publication-id="' + t.publication_id + '"] .hprwc-row__check').is(':checked');
		});
		if (!rows.length || busy) {
			return $.Deferred().resolve().promise();
		}

		busy = true;
		syncButtons();
		const label = mode === 'force' ? 'Syncing…' : 'Checking…';
		rows.forEach(function (t) {
			renderStatus($panel.find('.hprwc-row[data-publication-id="' + t.publication_id + '"]'), null, label);
		});
		setLoading(true, (mode === 'force' ? 'Syncing ' : 'Checking ') + rows.length + ' outlet' + (rows.length === 1 ? '' : 's') + '…');

		const queue = rows.slice();
		const done = $.Deferred();
		let active = 0;

		function next() {
			if (!queue.length && !active) {
				busy = false;
				setLoading(false);
				$panel.find('[data-hprwc-summary]').text(summarize());
				syncButtons();
				done.resolve();
				return;
			}
			while (active < CONCURRENCY && queue.length) {
				const target = queue.shift();
				active += 1;
				$.post(config.ajaxUrl, {
					action: config.actions.force,
					nonce: config.nonce,
					post_id: config.postId,
					publication_id: target.publication_id,
					mode: mode
				}).done(function (response) {
					const data = response && response.data ? response.data : {};
					updateRow(target.publication_id, data.status || { state: 'error', label: 'Error', detail: data.message || '' });
				}).fail(function (xhr) {
					const json = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
					updateRow(target.publication_id, json.status || { state: 'error', label: 'Error', detail: responseMessage(xhr) });
				}).always(function () {
					active -= 1;
					next();
				});
			}
		}

		next();
		return done.promise();
	}

	/** After a save, the outlet push runs in the background; show it running until its results land. */
	function waitForPush(attempt) {
		state.targets.forEach(function (t) {
			if (t.saved) renderStatus($panel.find('.hprwc-row[data-publication-id="' + t.publication_id + '"]'), null, 'Sending…');
		});
		setLoading(true, 'Sending the release to ' + state.targets.length + ' outlet' + (state.targets.length === 1 ? '' : 's') + '…');
		window.setTimeout(function () {
			resolve(false).done(function () {
				if (state.push_pending && attempt < PUSH_POLL_LIMIT) {
					waitForPush(attempt + 1);
				}
			});
		}, PUSH_POLL_MS);
	}

	function load() {
		resolve(false).done(function () {
			if (!state.published || !state.targets.length) return;
			if (state.push_pending) {
				waitForPush(1);
			} else {
				run('check');
			}
		});
	}

	function scheduleResolve() {
		window.clearTimeout(resolveTimer);
		setLoading(true, 'Updating outlets…');
		resolveTimer = window.setTimeout(function () { resolve(true); }, 250);
	}

	function installTaxonomyControls() {
		// Customers get Core's flat outlet picker instead of the taxonomy box.
		$('#hprwc-publications').off('.hprwc').on('change.hprwc', 'input[type="checkbox"]', scheduleResolve);

		const $box = $('#publicationdiv');
		const $checklists = $('#publicationchecklist, #publicationchecklist-pop');
		if (!$box.length || !$checklists.length) {
			return;
		}

		// Remove the legacy snippet controls so the plugin remains the sole UI owner.
		$box.find('.publication-bulk-actions, .hprwc-publication-bulk-actions').remove();
		if (isAdmin) {
			const $controls = $('<p>').addClass('hprwc-publication-bulk-actions');
			$('<button>', { type: 'button', class: 'button button-small', 'data-hprwc-taxonomy': 'all', text: 'Check all' }).appendTo($controls);
			$('<button>', { type: 'button', class: 'button button-small', 'data-hprwc-taxonomy': 'none', text: 'Uncheck all' }).appendTo($controls);
			$box.find('.inside').prepend($controls);
		}

		$box.off('.hprwc').on('click.hprwc', '[data-hprwc-taxonomy]', function () {
			$checklists.find('input[type="checkbox"]').prop('checked', $(this).data('hprwc-taxonomy') === 'all');
			scheduleResolve();
		});

		$checklists.off('.hprwc').on('change.hprwc', 'li > label > input[type="checkbox"]', function () {
			const $checkbox = $(this);
			const checked = $checkbox.prop('checked');
			const ids = [parseInt($checkbox.val(), 10) || 0];
			$checkbox.closest('li').children('ul.children').find('input[type="checkbox"]').each(function () {
				ids.push(parseInt(this.value, 10) || 0);
			});
			ids.filter(Boolean).forEach(function (id) {
				$checklists.find('input[type="checkbox"][value="' + id + '"]').prop('checked', checked);
			});
			scheduleResolve();
		});

		if (config.autoSelectAll && !$checklists.first().data('hprwc-auto-selected')) {
			$checklists.data('hprwc-auto-selected', true).find('input[type="checkbox"]').prop('checked', true);
			scheduleResolve();
		}
	}

	$(function () {
		$panel = $('[data-hprwc-panel]').first();
		if (!$panel.length || !config.ajaxUrl || !config.actions) {
			return;
		}
		isAdmin = $panel.attr('data-hprwc-admin') === '1';

		// Let any legacy DOM-ready callback finish, then normalize ownership.
		window.setTimeout(installTaxonomyControls, 0);

		$panel.on('click', '[data-hprwc-run]', function () {
			run($(this).data('hprwc-run') === 'force' ? 'force' : 'check');
		});
		$panel.on('click', '[data-hprwc-select]', function () {
			$panel.find('.hprwc-row__check:not(:disabled)').prop('checked', $(this).data('hprwc-select') === 'all');
		});

		load();
	});
})(jQuery);
