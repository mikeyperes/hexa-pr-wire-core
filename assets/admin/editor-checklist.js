(function ($) {
	'use strict';

	// At least one H2 and no heading of any other level. Keep in step with
	// EditorChecklist::headings_use_h2() in PHP.
	function headingsUseH2(content) {
		return /<h2\b/i.test(content) && !/<h[13456]\b/i.test(content);
	}
	window.hprwcHeadingsUseH2 = headingsUseH2;
	window.hprwcEditorContent = function () { return editorContent(); };

	// Counts for the Release Status box. Keep in step with ReleaseStatus::stats() in PHP.
	function releaseStats(html, siteHost) {
		const text = html.replace(/<[^>]*>/g, ' ').replace(/&nbsp;|\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
		let internal = 0, external = 0;
		const re = /<a\b[^>]*\bhref\s*=\s*["']([^"']+)["']/gi;
		let m;
		while ((m = re.exec(html)) !== null) {
			const href = m[1].trim();
			if (!href || href[0] === '#' || /^(mailto|tel|javascript):/i.test(href)) continue;
			const hostMatch = href.match(/^(?:https?:)?\/\/([^\/:?#]+)/i);
			const host = hostMatch ? hostMatch[1].toLowerCase().replace(/^www\./, '') : '';
			if (!host || host === siteHost) internal++; else external++;
		}
		return {
			words: text === '' ? 0 : text.split(/\s+/).length,
			images: (html.match(/<img\b/gi) || []).length,
			links_internal: internal,
			links_external: external,
			h2: (html.match(/<h2\b/gi) || []).length,
			h3: (html.match(/<h3\b/gi) || []).length
		};
	}
	window.hprwcReleaseStats = releaseStats;

	const $checklist = $('[data-hprwc-checklist]').first();
	if (!$checklist.length) {
		return;
	}

	// The block editor store can exist on classic screens (other plugins load
	// wp-data) and is then empty, so only use it on block-editor pages.
	function isBlockEditor() {
		return document.body.classList.contains('block-editor-page') && window.wp && wp.data && wp.data.select('core/editor');
	}

	function editorContent() {
		if (isBlockEditor()) {
			return wp.data.select('core/editor').getEditedPostContent() || '';
		}
		const visual = window.tinymce && tinymce.get('content');
		if (visual && visual.initialized && !visual.isHidden()) {
			// Before TinyMCE has loaded the article it reports empty; the textarea still holds it.
			return visual.getContent() || $('#content').val() || '';
		}
		return $('#content').val() || '';
	}

	function hasFeaturedImage() {
		if (isBlockEditor()) {
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

	function renderStats(content) {
		const $box = $('[data-hprwc-release-status]').first();
		if (!$box.length) return;
		const stats = releaseStats(content, String($box.data('site-host') || ''));
		Object.keys(stats).forEach(function (key) {
			$box.find('[data-hprwc-stat="' + key + '"]').text(stats[key]);
		});
	}

	function render() {
		renderStats(editorContent());
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
			editor.on('init keyup change SetContent NodeChange undo redo', schedule);
		}
	}
	if (window.tinymce) {
		if (tinymce.get('content')) bindTinyMce(tinymce.get('content'));
		tinymce.on('AddEditor', function (event) { bindTinyMce(event.editor); });
	}

	if (isBlockEditor() && typeof wp.data.subscribe === 'function') {
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
	$(window).on('load', function () { window.setTimeout(render, 300); });
})(jQuery);
