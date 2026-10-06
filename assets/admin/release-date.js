(function ($) {
	'use strict';

	// Press Release Date (stored as text, e.g. "October 5, 2026"): one joined
	// control — ‹ [date] › — plus a calendar button and Today. Empty → today.
	const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	const $input = $('.acf-field[data-key="field_64a72abb01ec2"] input[type="text"]').first();
	if (!$input.length || $input.data('hprwcDate')) {
		return;
	}
	$input.data('hprwcDate', true);

	const css = '.hprwc-date{display:flex;align-items:center;gap:10px;flex-wrap:wrap}'
		+ '.hprwc-date__group{display:inline-flex;align-items:stretch;border:1px solid #8c8f94;border-radius:4px;background:#fff;overflow:hidden}'
		+ '.hprwc-date__group:focus-within{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}'
		+ '.hprwc-date__step{border:0;background:#f6f7f7;color:#1d2327;width:32px;font-size:18px;line-height:1;cursor:pointer;padding:0}'
		+ '.hprwc-date__step:hover{background:#f0f0f1;color:#2271b1}.hprwc-date__step:first-child{border-right:1px solid #dcdcde}.hprwc-date__step:last-child{border-left:1px solid #dcdcde}'
		+ '.hprwc-date .hprwc-date__text{border:0!important;box-shadow:none!important;border-radius:0!important;width:190px!important;min-height:32px;text-align:center;margin:0!important}'
		+ '.hprwc-date__cal{position:relative;display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border:1px solid #8c8f94;border-radius:4px;background:#fff;color:#50575e;cursor:pointer;padding:0}'
		+ '.hprwc-date__cal:hover{color:#2271b1;border-color:#2271b1}.hprwc-date__cal .dashicons{font-size:18px;width:18px;height:18px}'
		+ '.hprwc-date__picker{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer;border:0;padding:0}'
		+ '.hprwc-date__today{font-size:13px}';
	$('<style id="hprwc-date-css"></style>').text(css).appendTo(document.head);

	function format(d) { return MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); }
	function parse(text) {
		const d = new Date(String(text || '').trim());
		return isNaN(d.getTime()) ? null : new Date(d.getFullYear(), d.getMonth(), d.getDate());
	}
	function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

	const $prev = $('<button type="button" class="hprwc-date__step" aria-label="Previous day" title="Previous day">‹</button>');
	const $next = $('<button type="button" class="hprwc-date__step" aria-label="Next day" title="Next day">›</button>');
	const $picker = $('<input type="date" class="hprwc-date__picker" tabindex="-1" aria-hidden="true">');
	const $cal = $('<label class="hprwc-date__cal" title="Pick a date"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><span class="screen-reader-text">Pick a date</span></label>').append($picker);
	const $today = $('<button type="button" class="button-link hprwc-date__today">Today</button>');
	const $group = $('<span class="hprwc-date__group"></span>');
	const $wrap = $('<div class="hprwc-date"></div>');

	$input.addClass('hprwc-date__text').before($wrap);
	$group.append($prev, $input, $next);
	$wrap.append($group, $cal, $today);

	function set(d) {
		$input.val(format(d)).trigger('change').trigger('input');
		$picker.val(iso(d));
	}
	function step(days) {
		const d = parse($input.val()) || new Date();
		d.setDate(d.getDate() + days);
		set(d);
	}
	$prev.on('click', function () { step(-1); });
	$next.on('click', function () { step(1); });
	$today.on('click', function () { set(new Date()); });
	$cal.on('click', function (event) {
		if (typeof $picker[0].showPicker === 'function') {
			event.preventDefault();
			try { $picker[0].showPicker(); } catch (e) { $picker.trigger('focus'); }
		}
	});
	$picker.on('change', function () {
		const parts = String($picker.val()).split('-');
		if (parts.length === 3) set(new Date(+parts[0], +parts[1] - 1, +parts[2]));
	});
	$input.on('keydown', function (event) {
		if (event.key === 'ArrowUp' || (event.altKey && event.key === 'ArrowRight')) { event.preventDefault(); step(1); }
		if (event.key === 'ArrowDown' || (event.altKey && event.key === 'ArrowLeft')) { event.preventDefault(); step(-1); }
	});
	$input.on('blur', function () { const d = parse($input.val()); if (d) { $input.val(format(d)); $picker.val(iso(d)); } });

	const current = parse($input.val());
	if (current) {
		$input.val(format(current));
		$picker.val(iso(current));
	} else if ($.trim($input.val()) === '') {
		set(new Date());
	}
})(jQuery);
