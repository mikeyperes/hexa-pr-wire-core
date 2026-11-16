(function ($) {
	'use strict';

	$(function () {
		var box = $('[data-hprwc-profiles]');
		if (!box.length) {
			return;
		}
		var status = box.find('[data-profile-status]');
		var results = box.find('[data-profile-results]');

		function editorContent() {
			var editor = window.wp && wp.data && wp.data.select('core/editor');
			if (editor && editor.getEditedPostContent) {
				return {
					title: editor.getEditedPostAttribute('title'),
					content: editor.getEditedPostContent()
				};
			}
			var tiny = window.tinymce && tinymce.get('content');
			return {
				title: $('#title').val(),
				content: tiny && !tiny.isHidden() ? tiny.getContent() : $('#content').val()
			};
		}

		function request(action, data, done) {
			box.find('button').prop('disabled', true);
			status.text('Working...');
			$.post(ajaxurl, $.extend({
				action: action,
				nonce: hprwcReleaseProfiles.nonce,
				post_id: box.data('post-id')
			}, data)).done(function (response) {
				if (!response || !response.success) {
					status.text(response && response.data && response.data.message || 'The request could not finish.');
					return;
				}
				done(response.data);
			}).fail(function () {
				status.text('The request could not finish. Refresh the editor and try again.');
			}).always(function () {
				box.find('button').prop('disabled', false);
			});
		}

		function show(data) {
			results.empty();
			(data.profiles || []).forEach(function (profile) {
				var row = $('<li>');
				var label = profile.url
					? $('<a>', {href: profile.url, target: '_blank', rel: 'noopener'}).text(profile.name)
					: $('<strong>').text(profile.name);
				row.append(label);
				if (profile.evidence) {
					row.append($('<p>').text(profile.evidence));
				}
				if (profile.id) {
					var choose = $('<button>', {type: 'button', class: 'button'}).text('Use on next save');
					choose.on('click', function () {
						$('[data-hprwc-profile-selection]').val(profile.id);
						status.text(profile.name + ' selected. Save or update the release to attach this profile.');
						results.empty();
					});
					row.append(' ', choose);
				}
				results.append(row);
			});
			status.text(results.children().length ? 'Review the matches and choose a profile.' : 'No matching person profiles found. Search by name or create the profile in SMP Verified Profiles.');
		}

		box.find('[data-profile-search]').on('click', function () {
			results.empty();
			request('hprwc_verified_profiles_query', {name: box.find('[data-profile-name]').val()}, show);
		});
		box.find('[data-profile-recognize]').on('click', function () {
			results.empty();
			request('hprwc_release_subjects', editorContent(), show);
		});
	});
})(jQuery);
