# Existing AJAX actions

Use `Hexa\PluginCore\WpAdminAjax\AjaxGuard::check_request_nonce()` for WordPress’s exact request field fallback and optional 403 response. Call `verify_explicit_nonce()` when the handler already reads and normalizes its own input. It retains the original nonce age result. Use `allows()` for checks with object IDs or a plugin’s established error response. Existing action names, privileges, response formats and nonce sources stay with the host.

Run `php tests/admin-ajax-legacy-guards.php` to compare the forwarding methods with WordPress-compatible fixtures.
