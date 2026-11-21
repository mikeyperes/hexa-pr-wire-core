# Legacy snippet compatibility

Use `Hexa\PluginCore\SnippetRegistry\LegacySnippetActivator::activate()` with the host’s catalog, namespace, and logger. The default option lookup uses the same rules as the snippet registry. Hosts can supply their existing option and eligibility callbacks. The host continues to own its snippets, option keys, and hook timing.

Use `Hexa\PluginCore\LogFiles\DebugLogger::write()` with the host’s existing logging format. It honors WordPress debug switches and omits arguments from stack traces.

Use `Hexa\PluginCore\WpAdminComponents\CoreUi::legacy_toggle()` where an existing switch relies on its original HTML and classes. Pass its CSS prefix and preserve the host’s label and click binding. The host still owns the styles and event handler.

Run `php tests/legacy-snippets.php` to verify activation order, saved settings, debug output, and switch markup.
