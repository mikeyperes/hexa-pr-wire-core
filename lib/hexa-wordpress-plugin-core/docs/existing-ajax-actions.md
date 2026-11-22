# Existing AJAX actions

Use `Hexa\PluginCore\WpAdminAjax\AjaxGuard::require_access()` for a handler’s capability and nonce guard. It calls Core’s existing capability and nonce helpers, keeping the original check order, capability object IDs, WordPress nonce field fallback, and failure body and status.

For a custom JSON failure, pass the payload and optional HTTP status as `capability_error` or `nonce_error`. Without a custom nonce failure, the WordPress source retains WordPress’s native `-1` response. Set `nonce_source: AjaxGuard::CORE_NONCE` for an action that already uses Core’s standard nonce validation, or pass a closure that reads an explicit nonce after permission is checked. Set `nonce_first: true` where the old handler checked the nonce first.

Run `php tests/admin-ajax-legacy-guards.php` for the complete guard comparison.
