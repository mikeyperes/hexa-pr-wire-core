# Hexa PR Wire sitemap recommendations (Part 5)

This plugin recommends press-release, publication, article, organization, resource and product sitemaps on hexaprwire.com only. It does not set company-wide policy. It registers through `hexa_plugin_core_sitemap_recommendations`, supplied by bundled Core 3.48.0. It does not change Rank Math settings when enabled.

All six rows identify Hexa PR Wire Core as their recommendation source. A missing content type remains an unavailable required row so the shared engine warns rather than hiding it.

Read with GET `/wp-json/hexa-core/v1/sitemaps` (administrator authentication) or `wp hexa sitemaps list`. Shared check, change and condition routes retain the Core contract documented at `lib/hexa-wordpress-plugin-core/docs/sitemaps.md`. Input: optional site_type; output: full registry rows, current settings, recommendation sources and warnings. No separate endpoint or new admin screen is needed.

Core now loads from `lib/hexa-wordpress-plugin-core`; runtime and test references use that single bundle. The old vendor location is removed. No CSS or JavaScript assets are added.
