(function ($) {
	'use strict';

	$(function () {
		if ($('body').hasClass('user-new-php')) {
			const $email = $('#email').closest('tr');
			const $username = $('#user_login').closest('tr');
			if ($email.length && $username.length) {
				$username.before($email);
			}
		}
	});
})(jQuery);
