/* Hexa PR Wire → Publications: live publication cards. */
(function ($) {
	'use strict';

	const cfg = window.hprwcPublications || {};
	const CONCURRENCY = 4;

	/* ---------- helpers ---------- */

	function ago(iso) {
		if (!iso) return '';
		const s = Math.max(0, (Date.now() - Date.parse(iso)) / 1000);
		if (s < 90) return 'just now';
		if (s < 5400) return Math.round(s / 60) + ' min ago';
		if (s < 129600) return Math.round(s / 3600) + ' h ago';
		return Math.round(s / 86400) + ' d ago';
	}

	function el(tag, cls, text) {
		const $e = $('<' + tag + '>');
		if (cls) $e.addClass(cls);
		if (text !== undefined) $e.text(text);
		return $e;
	}

	function post(action, data) {
		return $.post(cfg.ajaxUrl, $.extend({ action: action, nonce: cfg.nonce }, data));
	}

	function errorText(xhr) {
		const j = xhr && xhr.responseJSON;
		return (j && j.data && j.data.message) || ('Request failed (HTTP ' + (xhr ? xhr.status : 0) + ')');
	}

	/* ---------- card rendering ---------- */

	function setState($card, state) {
		$card.attr('data-state', state);
		summarize();
	}

	function section(title) {
		return el('section', 'hprwc-sec').append(el('h4', 'hprwc-sec__title', title));
	}

	/** One labelled line: label · value with its action right beside it · detail. */
	function row(label, value, tone, detail, action) {
		const $r = el('div', 'hprwc-row' + (tone ? ' is-' + tone : ''));
		$r.append(el('span', 'hprwc-row__dot'));
		$r.append(el('span', 'hprwc-row__label', label));
		$r.append(el('span', 'hprwc-row__value').append(el('span', '', value)).append(action || ''));
		return $r.append(el('span', 'hprwc-row__detail', detail || ''));
	}

	/** Plugin table: Plugin · Installed · Latest · Status, with Update in the status cell. */
	function pluginTable(plugins, remoteUpdates) {
		const $t = el('div', 'hprwc-ptable');
		$t.append(el('div', 'hprwc-ptable__head').append(el('span'), el('span', '', 'Plugin'), el('span', '', 'Installed'), el('span', '', 'Latest'), el('span', '', 'Status')));
		plugins.forEach(function (t) {
			const tone = !t.installed ? 'muted' : (t.outdated || !t.active ? 'warn' : 'ok');
			const $status = el('span', 'hprwc-row__detail');
			if (!t.installed) $status.text('Not installed');
			else if (t.outdated) $status.append(button('Update now', { 'data-pub-update': t.slug }, remoteUpdates ? '' : 'Turn on Remote Plugin Updates in Distributor on this site.'));
			else $status.text(t.active ? '✓ Up to date' : 'Inactive');
			$t.append(el('div', 'hprwc-ptable__row is-' + tone).attr('data-plugin', t.slug).append(
				el('span', 'hprwc-row__dot'),
				el('span', 'hprwc-row__label', t.label),
				el('span', 'hprwc-ptable__ver' + (t.outdated ? ' is-old' : ''), t.installed ? t.version : '—'),
				el('span', 'hprwc-ptable__ver', t.latest || '—'),
				$status
			));
		});
		return $t;
	}

	function button(text, attrs, disabledTitle) {
		const $b = el('button', 'button button-small', text).attr($.extend({ type: 'button' }, attrs));
		if (disabledTitle) $b.prop('disabled', true).attr('title', disabledTitle);
		return $b;
	}

	function renderConnected($card, d) {
		const $body = $card.find('[data-pub-body]').empty();
		if (!d.reachable) {
			$body.append(section('Site').append(row('Connection', d.http ? 'HTTP ' + d.http : 'No answer', 'bad', d.message)));
			setState($card, 'error');
			return;
		}

		const plugins = d.plugins || [];
		const outdated = plugins.some(function (t) { return t.installed && t.outdated; });

		// Site
		$body.append(section('Site')
			.append(row('Response', 'HTTP ' + d.http, d.http === 200 ? 'ok' : 'bad', d.ms + ' ms'))
			.append(row('Last sync', d.last_sync ? ago(d.last_sync) : 'never', d.feed_enabled ? 'ok' : 'warn',
				d.last_sync_error ? 'Last run reported: ' + d.last_sync_error.replace(/^Legacy import conflict:\s*/, '') : (d.feed_enabled ? 'Feed on' : 'Feed off'),
				button('Sync now', { 'data-pub-site': 'sync' })))
			.append(row('Remote updates', d.remote_updates ? 'On' : 'Off', d.remote_updates ? 'ok' : 'warn', d.remote_updates ? '' : 'Turn on in Distributor on this site')));

		// Plugins
		$body.append(section('Plugins').append(pluginTable(plugins, d.remote_updates)));

		// Compatibility
		$card.attr('data-echo', d.echo_jobs > 0 && d.echo_route ? d.echo_jobs : 0);
		const $compat = section('Compatibility');
		$compat.append(row('Echo RSS', d.echo_jobs > 0 ? 'Importing Hexa PR Wire' : (d.echo_active ? 'On' : 'Off'), d.echo_jobs > 0 ? 'warn' : 'ok',
			d.echo_jobs > 0 ? d.echo_jobs + ' Hexa PR Wire job(s) still on' : (d.echo_active ? 'Hexa PR Wire job off · other feeds untouched' : ''),
			d.echo_jobs > 0 ? button('Switch off Hexa PR Wire job', { 'data-pub-site': 'echo' }) : null));
		$compat.append(row('FIFU', d.fifu_active ? 'Active' : 'Off', d.fifu_to_clean > 0 ? 'warn' : 'ok',
			d.fifu_to_clean > 0 ? d.fifu_to_clean + ' press releases still carry FIFU data' : (d.fifu_active ? 'Press releases clean · FIFU works for other posts' : ''),
			d.fifu_to_clean > 0 ? button('Remove FIFU from press releases', { 'data-pub-site': 'fifu' }) : null));
		$body.append($compat);

		// Recent releases
		const $recent = section('Most recent press releases');
		if ((d.recent || []).length) {
			const $list = el('ul', 'hprwc-recent');
			d.recent.forEach(function (p) {
				$list.append(el('li').append(el('a', '', p.title).attr({ href: p.url, target: '_blank', rel: 'noopener noreferrer' })).append(el('span', '', ago(p.date))));
			});
			$recent.append($list);
		} else {
			$recent.append(el('p', 'hprwc-muted', 'No imported releases yet.'));
		}
		$body.append($recent);

		const attention = d.http !== 200 || outdated || !d.plugins_route || !d.feed_enabled || d.echo_jobs > 0 || d.fifu_to_clean > 0;
		setState($card, attention ? 'warn' : 'ok');
	}

	function renderOffline($card, d) {
		const ok = d.site.http >= 200 && d.site.http < 400;
		$card.find('[data-pub-body]').empty().append(section('Site')
			.append(row('Response', d.site.http ? 'HTTP ' + d.site.http : 'No answer', ok ? 'ok' : 'bad', d.site.http ? d.site.ms + ' ms · no plugin connection' : 'No plugin connection')));
		setState($card, ok ? 'ok' : 'error');
	}

	/* ---------- loading ---------- */

	const queue = [];
	let active = 0;

	/**
	 * Check one site and redraw its card. With `done` set (after an action), the current card stays
	 * on screen while checking, and the row that changed is highlighted with the result message.
	 */
	function load($card, done) {
		const pub = $card.data('pub');
		setState($card, 'loading');
		if (!done) $card.find('[data-pub-body]').html('<div class="hprwc-pcard__loading"><span class="hprwc-spin"></span>Checking site…</div>');
		return post(cfg.actions.status, { publication_id: pub.id })
			.done(function (r) {
				if (!r || !r.success) {
					$card.find('[data-pub-body]').empty().append(el('p', 'hprwc-pcard__error', (r && r.data && r.data.message) || 'Check failed.'));
					setState($card, 'error');
				} else if (r.data.connected) {
					renderConnected($card, r.data);
					if (done) highlight($card, done);
				} else {
					renderOffline($card, r.data);
				}
			})
			.fail(function (xhr) {
				$card.find('[data-pub-body]').empty().append(el('p', 'hprwc-pcard__error', errorText(xhr)));
				setState($card, 'error');
			});
	}

	function pump() {
		while (active < CONCURRENCY && queue.length) {
			const job = queue.shift();
			active += 1;
			load(job.$card).always(function () { active -= 1; pump(); });
		}
	}

	function enqueue($cards) {
		$cards.each(function () {
			const $c = $(this);
			setState($c, 'queued');
			$c.find('[data-pub-body]').html('<div class="hprwc-pcard__loading"><span class="hprwc-spin"></span>Waiting…</div>');
			queue.push({ $card: $c });
		});
		pump();
	}

	/* ---------- actions ---------- */

	function busy($btn, text) {
		$btn.prop('disabled', true).hide().after(el('span', 'hprwc-busy').html('<span class="hprwc-spin"></span>' + text));
	}

	function failed($btn, xhr) {
		$btn.siblings('.hprwc-busy').remove();
		$btn.prop('disabled', false).show().text('Retry');
		const $d = $btn.closest('.hprwc-row, .hprwc-ptable__row').find('.hprwc-row__detail');
		$d.find('.hprwc-row__err').remove();
		$d.append(el('span', 'hprwc-row__err', errorText(xhr)));
	}

	/** Mark the row an action just changed and say what happened in its status cell. */
	function highlight($card, done) {
		const $r = $card.find(done.plugin ? '.hprwc-ptable__row[data-plugin="' + done.plugin + '"]' : '.hprwc-row').filter(function () {
			return done.plugin || $(this).find('.hprwc-row__label').text() === done.label;
		}).first();
		$r.addClass('is-just');
		if (done.plugin) {
			const v = $r.find('.hprwc-ptable__ver').first().text();
			$r.find('.hprwc-row__detail').text($r.hasClass('is-ok') ? '✓ Updated to ' + v : 'Still on ' + v + ' — update did not apply');
		}
	}

	function update($card, slug, $btn) {
		busy($btn, 'Updating…');
		return post(cfg.actions.update, { publication_id: $card.data('pub').id, plugin: slug })
			.done(function () { load($card, { plugin: slug }); })
			.fail(function (xhr) { failed($btn, xhr); });
	}

	function siteAction($card, action, $btn) {
		const label = $btn.closest('.hprwc-row').find('.hprwc-row__label').text();
		busy($btn, { sync: 'Syncing…', fifu: 'Cleaning…', echo: 'Switching off…' }[action] || 'Working…');
		return post(cfg.actions.site, { publication_id: $card.data('pub').id, site_action: action })
			.done(function () { load($card, { label: label }); })
			.fail(function (xhr) { failed($btn, xhr); });
	}

	function updateEchoBanner() {
		const n = $('.hprwc-pcard').filter(function () { return +$(this).attr('data-echo') > 0; }).length;
		$('[data-pubs-echo-count]').text(n);
		$('[data-pubs-echo-banner]').prop('hidden', n === 0);
	}

	/* ---------- page ---------- */

	function summarize() {
		updateEchoBanner();
		const $cards = $('.hprwc-pcard');
		const n = function (s) { return $cards.filter('[data-state="' + s + '"]').length; };
		const pending = n('loading') + n('queued');
		const attention = n('warn') + n('error');
		$('[data-pubs-attention-count]').text(attention);
		$('[data-pubs-summary]').text(
			(pending ? 'Checking ' + pending + ' of ' + $cards.length + '… ' : '') +
			n('ok') + ' all green · ' + n('warn') + ' need attention · ' + n('error') + ' failing'
		);
	}

	function applyFilters() {
		const filter = $('[data-pubs-filter].is-active').data('pubs-filter');
		const term = String($('[data-pubs-search]').val() || '').toLowerCase();
		$('.hprwc-pcard').each(function () {
			const $c = $(this);
			const pub = $c.data('pub');
			const byType = filter === 'all' || (filter === 'attention' ? /warn|error/.test($c.attr('data-state')) : $c.attr('data-type') === filter);
			const byTerm = !term || (pub.title + ' ' + pub.domain).toLowerCase().indexOf(term) !== -1;
			$c.prop('hidden', !(byType && byTerm));
		});
	}

	function init() {
		const $root = $('[data-hprwc-pubs]');
		if (!$root.length || $root.data('init')) return;
		$root.data('init', true);

		$root.on('click', '[data-pubs-filter]', function () {
			$root.find('[data-pubs-filter]').removeClass('is-active');
			$(this).addClass('is-active');
			applyFilters();
		});
		$root.on('input', '[data-pubs-search]', applyFilters);
		$root.on('click', '[data-pubs-refresh-all]', function () { enqueue($root.find('.hprwc-pcard')); });
		$root.on('click', '[data-pub-refresh]', function () { load($(this).closest('.hprwc-pcard')); });
		$root.on('click', '[data-pub-site]', function () { siteAction($(this).closest('.hprwc-pcard'), $(this).data('pub-site'), $(this)); });
		$root.on('click', '[data-pubs-echo-all]', function () {
			const $btn = $(this).prop('disabled', true).text('Switching off…');
			const cards = $root.find('.hprwc-pcard').filter(function () { return +$(this).attr('data-echo') > 0; }).toArray();
			(function next() {
				if (!cards.length) { $btn.prop('disabled', false).text('Switch off on all'); return; }
				const $c = $(cards.shift());
				siteAction($c, 'echo', $c.find('[data-pub-site="echo"]')).always(next);
			})();
		});
		$root.on('click', '[data-pub-update]', function () {
			update($(this).closest('.hprwc-pcard'), $(this).data('pub-update'), $(this));
		});

		enqueue($root.find('.hprwc-pcard'));
	}

	$(init);
	document.addEventListener('hexa-core-host-tab-loaded', init);
})(jQuery);
