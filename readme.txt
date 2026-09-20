=== Hexa PR Wire Core ===
Contributors: hexaprwire
Tags: press releases, syndication, publication taxonomy
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.0.1
License: Proprietary

Source-side customer permissions, publication registry, editorial workflow, syndication, notifications, and secure Force Sync controls for Hexa PR Wire.

== Description ==

Hexa PR Wire Core owns origin-site customer access, the publication registry and
taxonomy, release fields, editorial checks, feeds, delivery links, canonical URLs,
notifications, and publication-specific customer pricing. It resolves selected
publication terms through their mapped publication records and securely calls
destination Distributor endpoints.

Checkout and payment fulfillment remain in Hexa PR Wire Billing. Destination-side
import behavior remains in Hexa PR Wire Distributor.

== Customer Access ==

Administrators select one of four submission modes and the publications available
to each customer. These rules are enforced in admin, REST, media, capabilities,
queries, and taxonomy assignments.

== Migration ==

Version 2 includes parity, dry-run migration, reversible execution, and postflight
commands. Legacy snippets and UI definitions are disabled only after parity passes.

== Security ==

The Force Sync credential is stored with Hexa Credential Vault. Requests use
HTTPS POST with `X-HPR-Token`; credentials are never placed in a URL, DOM value,
status message, or Force Sync log entry.

Force requests require an administrator, never follow redirects, and are limited
to the publication ID/hostname pairs in the approved destination registry.

== Changelog ==

= 2.0.1 =
* Correct ACF UI deactivation verification to use ACF's `acf-disabled` status.
* Preserve and resume a prepared migration manifest after an interrupted handoff.
* Make rollback restore Code Snippets without runtime redeclaration failures.

= 2.0.0 =
* Move customer permissions, publication structures, release fields, feeds, shortcodes, delivery links, canonical URLs, and notifications into versioned Core modules.
* Add customer submission modes, publication entitlements, and per-publication pricing.
* Add the live four-item editorial checklist for H2 headings, featured image, location, and date.
* Add a Shared Core-powered admin interface, protected notification actions, status reporting, and a reversible legacy migration.
* Register the publication CPT, taxonomy, and approved ACF definitions from code with stable legacy keys.

= 1.0.1 =
* Pin Force requests to an approved destination-host registry and block redirects.
* Restrict Force Sync to administrators and block the retired AJAX routes.
* Synchronize both Publications taxonomy lists and retain saved result statuses.

= 1.0.0 =
* Move source-side Force Sync and publication metabox behavior out of Code Snippets.
* Resolve only saved, eligible taxonomy destinations.
* Add dynamic unsaved-selection previews and server-side allowlist enforcement.
* Replace legacy GET transport with authenticated HTTPS POST.
