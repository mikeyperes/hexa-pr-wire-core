(function ($) {
	'use strict';

	// Press Release Date (ACF text field, format "October 5, 2026"): defaults
	// to today when empty, ‹ › step one day, and a calendar picker sets it.
	const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	const $input = $('.acf-field[data-key="field_64a72abb01ec2"] input[type="text"]').first();
	if (!$input.length) {
		return;
	}

	function format(d) {
		return MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
	}
	function parse(text) {
		const d = new Date(String(text || '').trim());
		return isNaN(d.getTime()) ? null : new Date(d.getFullYear(), d.getMonth(), d.getDate());
	}
	function iso(d) {
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}
	function set(d) {
		$input.val(format(d)).trigger('change').trigger('input');
		$picker.val(iso(d));
	}

	const $prev = $('<button type="button" class="button" aria-label="Previous day">‹</button>');
	const $next = $('<button type="button" class="button" aria-label="Next day">›</button>');
	const $picker = $('<input type="date" aria-label="Pick a date" style="margin-left:6px">');
	const $today = $('<button type="button" class="button-link" style="margin-left:8px">Today</button>');
	$input.css({ width: 'auto', minWidth: '220px' }).after($('<span class="hprwc-date-tools" style="margin-left:6px"></span>').append($prev, ' ', $next, $picker, $today));

	function step(days) {
		const d = parse($input.val()) || new Date();
		d.setDate(d.getDate() + days);
		set(d);
	}
	$prev.on('click', function () { step(-1); });
	$next.on('click', function () { step(1); });
	$today.on('click', function () { set(new Date()); });
	$picker.on('change', function () {
		const parts = String($picker.val()).split('-');
		if (parts.length === 3) set(new Date(+parts[0], +parts[1] - 1, +parts[2]));
	});
	$input.on('keydown', function (event) {
		if (event.key === 'ArrowLeft' && event.altKey) { event.preventDefault(); step(-1); }
		if (event.key === 'ArrowRight' && event.altKey) { event.preventDefault(); step(1); }
	});
	$input.on('change', function () { const d = parse($input.val()); if (d) $picker.val(iso(d)); });

	const current = parse($input.val());
	if (current) {
		$picker.val(iso(current));
	} else if ($.trim($input.val()) === '') {
		set(new Date());
	}
})(jQuery);
