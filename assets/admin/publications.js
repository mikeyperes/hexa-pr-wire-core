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

	function stat(label, value, tone) {
		return el('div', 'hprwc-stat' + (tone ? ' is-' + tone : ''))
			.append(el('span', 'hprwc-stat__label', label))
			.append(el('span', 'hprwc-stat__value', value));
	}

	function tile($card, t, canUpdate) {
		const tone = !t.installed ? 'missing' : (t.outdated ? 'warn' : (t.active ? 'ok' : 'warn'));
		const $tile = el('div', 'hprwc-tile is-' + tone).attr('data-plugin', t.slug);
		$tile.append(el('span', 'hprwc-tile__name', t.label));
		if (!t.installed) {
			$tile.append(el('span', 'hprwc-tile__version', 'Not installed'));
			return $tile;
		}
		$tile.append(el('span', 'hprwc-tile__version', t.version + (t.outdated ? ' → ' + t.latest : '')));
		$tile.append(el('span', 'hprwc-tile__note', t.outdated ? 'Update available' : (t.active ? 'Up to date' : 'Inactive')));
		if (t.outdated) {
			const $btn = el('button', 'button button-small hprwc-tile__btn', 'Update').attr('type', 'button').attr('data-pub-update', t.slug);
			if (!canUpdate) $btn.prop('disabled', true).attr('title', 'Turn on Remote Plugin Updates in Distributor on this site.');
			$tile.append($btn);
		}
		return $tile;
	}

	function renderConnected($card, d) {
		const $body = $card.find('[data-pub-body]').empty();
		if (!d.reachable) {
			$body.append(el('p', 'hprwc-pub__error', d.message));
			$body.append(el('div', 'hprwc-stats').append(stat('Site', d.http ? 'HTTP ' + d.http : 'No answer', 'bad')));
			setState($card, 'error');
			return;
		}

		const outdated = (d.plugins || []).some(function (t) { return t.installed && t.outdated; });
		const missingCore = (d.plugins || []).some(function (t) { return !t.installed && t.slug === 'hexa-pr-wire-distributor'; });
		const syncTone = d.last_sync_error ? 'bad' : (d.feed_enabled ? '' : 'warn');

		$body.append(el('div', 'hprwc-stats')
			.append(stat('Site', 'HTTP ' + d.http + ' · ' + d.ms + ' ms', d.http === 200 ? 'ok' : 'bad'))
			.append(stat('Last sync', d.last_sync ? ago(d.last_sync) : 'never', syncTone))
			.append(stat('Feed', d.feed_enabled ? 'On' : 'Off', d.feed_enabled ? 'ok' : 'warn'))
			.append(stat('Remote updates', d.plugins_route ? (d.remote_updates ? 'On' : 'Off') : 'Needs Distributor 3.6+', d.remote_updates ? 'ok' : 'warn')));

		if (d.last_sync_error) {
			$body.append(el('p', 'hprwc-pub__error', 'Last sync error: ' + d.last_sync_error));
		}

		const $tiles = el('div', 'hprwc-tiles');
		if (d.plugins_route) {
			(d.plugins || []).forEach(function (t) { $tiles.append(tile($card, t, d.remote_updates)); });
		} else {
			$tiles.append(tile($card, { slug: 'hexa-pr-wire-distributor', label: 'Hexa PR Wire Distributor', installed: true, active: true, version: d.distributor, latest: '3.6+', outdated: true }, false));
		}
		$body.append($tiles);

		const $recent = el('div', 'hprwc-recent').append(el('h4', '', 'Most recent press releases'));
		if ((d.recent || []).length) {
			const $list = el('ul');
			d.recent.forEach(function (p) {
				$list.append(el('li').append(el('a', '', p.title).attr({ href: p.url, target: '_blank', rel: 'noopener noreferrer' })).append(el('span', '', ago(p.date))));
			});
			$recent.append($list);
		} else {
			$recent.append(el('p', 'hprwc-muted', 'No imported releases yet.'));
		}
		$body.append($recent);

		const attention = d.http !== 200 || outdated || missingCore || !d.plugins_route || !!d.last_sync_error || !d.feed_enabled;
		setState($card, attention ? 'warn' : 'ok');
	}

	function renderOffline($card, d) {
		const $body = $card.find('[data-pub-body]').empty();
		$body.append(el('p', 'hprwc-muted', 'No plugin connection. Only the public site is checked.'));
		$body.append(el('div', 'hprwc-stats').append(stat('Site', d.site.http ? 'HTTP ' + d.site.http + ' · ' + d.site.ms + ' ms' : 'No answer', d.site.http >= 200 && d.site.http < 400 ? 'ok' : 'bad')));
		setState($card, d.site.http >= 200 && d.site.http < 400 ? 'ok' : 'error');
	}

	/* ---------- loading ---------- */

	const queue = [];
	let active = 0;

	function load($card, refresh) {
		const pub = $card.data('pub');
		setState($card, 'loading');
		$card.find('[data-pub-body]').html('<div class="hprwc-pub__loading"><span class="hprwc-spin"></span>Checking site…</div>');
		return post(cfg.actions.status, { publication_id: pub.id, refresh: refresh ? 1 : 0 })
			.done(function (r) {
				if (!r || !r.success) {
					$card.find('[data-pub-body]').empty().append(el('p', 'hprwc-pub__error', (r && r.data && r.data.message) || 'Check failed.'));
					setState($card, 'error');
				} else if (r.data.connected) {
					renderConnected($card, r.data);
				} else {
					renderOffline($card, r.data);
				}
			})
			.fail(function (xhr) {
				$card.find('[data-pub-body]').empty().append(el('p', 'hprwc-pub__error', errorText(xhr)));
				setState($card, 'error');
			});
	}

	function pump() {
		while (active < CONCURRENCY && queue.length) {
			const job = queue.shift();
			active += 1;
			load(job.$card, job.refresh).always(function () { active -= 1; pump(); });
		}
	}

	function enqueue($cards, refresh) {
		$cards.each(function () {
			const $c = $(this);
			setState($c, 'queued');
			$c.find('[data-pub-body]').html('<div class="hprwc-pub__loading"><span class="hprwc-spin"></span>Waiting…</div>');
			queue.push({ $card: $c, refresh: refresh });
		});
		pump();
	}

	/* ---------- updates ---------- */

	function update($card, slug, $btn) {
		const pub = $card.data('pub');
		const $tile = $btn.closest('.hprwc-tile').addClass('is-busy');
		$btn.prop('disabled', true).html('<span class="hprwc-spin"></span>Updating…');
		post(cfg.actions.update, { publication_id: pub.id, plugin: slug })
			.done(function (r) {
				const d = (r && r.data) || {};
				$tile.removeClass('is-busy').find('.hprwc-tile__note').text(d.message || 'Updated') ;
				load($card, true);
			})
			.fail(function (xhr) {
				$tile.removeClass('is-busy').addClass('is-bad').find('.hprwc-tile__note').text(errorText(xhr));
				$btn.prop('disabled', false).text('Retry');
			});
	}

	/* ---------- page ---------- */

	function summarize() {
		const $cards = $('.hprwc-pub');
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
		$('.hprwc-pub').each(function () {
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
		$root.on('click', '[data-pubs-refresh-all]', function () { enqueue($root.find('.hprwc-pub'), true); });
		$root.on('click', '[data-pub-refresh]', function () { load($(this).closest('.hprwc-pub'), true); });
		$root.on('click', '[data-pub-update]', function () {
			update($(this).closest('.hprwc-pub'), $(this).data('pub-update'), $(this));
		});

		enqueue($root.find('.hprwc-pub'), false);
	}

	$(init);
	document.addEventListener('hexa-core-host-tab-loaded', init);
})(jQuery);
