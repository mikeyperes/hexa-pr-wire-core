(function ($) {
	'use strict';

	// At least one H2 and no heading of any other level. Keep in step with
	// EditorChecklist::headings_use_h2() in PHP.
	function headingsUseH2(content) {
		return /<h2\b/i.test(content) && !/<h[13456]\b/i.test(content);
	}
	window.hprwcHeadingsUseH2 = headingsUseH2;

	const $checklist = $('[data-hprwc-checklist]').first();
	if (!$checklist.length) {
		return;
	}

	function editorContent() {
		if (window.wp && wp.data && wp.data.select('core/editor')) {
			return wp.data.select('core/editor').getEditedPostContent() || '';
		}
		if (window.tinymce && tinymce.get('content')) {
			return tinymce.get('content').getContent() || '';
		}
		return $('#content').val() || '';
	}

	function hasFeaturedImage() {
		if (window.wp && wp.data && wp.data.select('core/editor')) {
			return parseInt(wp.data.select('core/editor').getEditedPostAttribute('featured_media'), 10) > 0;
		}
		return parseInt($('#_thumbnail_id').val(), 10) > 0 || $('#postimagediv .inside img').length > 0;
	}

	function fieldValue(key, fallbackName) {
		const selectors = [
			'.acf-field[data-key="' + key + '"] input',
			'input[name="' + fallbackName + '"]',
			'input[name$="[' + key + ']"]'
		];
		const $field = $(selectors.join(',')).first();
		return $field.length ? String($field.val() || '').trim() : '';
	}

	function state() {
		const content = editorContent();
		return {
			h2: headingsUseH2(content),
			featured: hasFeaturedImage(),
			location: fieldValue('field_64a72abb01ef9', 'press_release_location') !== '',
			date: fieldValue('field_64a72abb01ec2', 'press_release_date') !== ''
		};
	}

	function render() {
		const values = state();
		let complete = 0;
		Object.keys(values).forEach(function (key) {
			const passed = values[key];
			const $item = $checklist.find('[data-hprwc-check="' + key + '"]');
			$item.toggleClass('is-complete', passed).toggleClass('is-missing', !passed);
			$item.find('span').text(passed ? '✓' : '○');
			if (passed) complete += 1;
		});
		$checklist.find('[data-hprwc-checklist-summary]').text(complete + '/4 complete');
	}

	let timer;
	function schedule() {
		window.clearTimeout(timer);
		timer = window.setTimeout(render, 120);
	}

	$(document).on('input change', '#content, #_thumbnail_id, .acf-field input', schedule);
	$(document).on('click', '#set-post-thumbnail, #remove-post-thumbnail', function () {
		window.setTimeout(render, 350);
	});

	// Classic editor Visual tab: TinyMCE edits do not fire input events on #content.
	function bindTinyMce(editor) {
		if (editor && editor.id === 'content') {
			editor.on('keyup change SetContent NodeChange undo redo', schedule);
		}
	}
	if (window.tinymce) {
		if (tinymce.get('content')) bindTinyMce(tinymce.get('content'));
		tinymce.on('AddEditor', function (event) { bindTinyMce(event.editor); });
	}

	if (window.wp && wp.data && typeof wp.data.subscribe === 'function' && wp.data.select('core/editor')) {
		let previous = '';
		wp.data.subscribe(function () {
			const current = editorContent() + '|' + (hasFeaturedImage() ? '1' : '0');
			if (current !== previous) {
				previous = current;
				schedule();
			}
		});
	}

	render();
})(jQuery);
