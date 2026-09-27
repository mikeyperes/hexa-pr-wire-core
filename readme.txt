=== Hexa PR Wire Core ===
Contributors: hexaprwire
Tags: press releases, syndication, publication taxonomy
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.2.0
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

= 2.2.0 =
* Real-time syndication: publishing or updating a release makes each selected outlet pull it immediately (background, via each outlet's Force Sync link); trashing or deleting a release makes each outlet apply the deletion list immediately. Outlets still pull every 4 hours.
* Deletions are recorded by Hexa PR Wire post ID (`post:<ID>`) and published in `/wp-json/hprwc/v1/deletions` (`sources`), so outlets delete the exact copy regardless of slug.
* Hexa PR Wire author profile source of truth: `GET /wp-json/hprwc/v1/author` from the `hexaprwire` user; saving that user or "Push author profile to all outlets" (Hexa PR Wire → Publications) refreshes every outlet.
* Outlet feed: each release carries only its own image (third-party rss2_item output such as FIFU's outlet logo removed); unknown outlet slug returns 404; latest 50 releases by default (`limit` up to 200, `slug` for exact releases); cached until the next release change; `reprocess-*` require the shared token.
* Outlet picker: non-admins see a flat list of outlets (no group names) with Select all; single-outlet orders are locked to the purchased outlet; admins keep the full tree and ticking a group ticks its outlets.
* Custom fields migrated to Hexa WP Core 3.4.1 `Fields` (works with or without ACF); bundles Hexa WP Core 3.4.1.

= 2.1.3 =
* Removed the exact Hexa WP Core 3.0.6 ceiling: Core now requires 3.0.6 or newer and bundles 3.3.0, so hexaprwire.com can run current Hexa plugins together. The parity report accepts any selected Core from 3.0.6 up.

= 2.1.2 =
* Made Core namespace rendering compatible with RSS hooks that manage their own output buffers.

= 2.1.1 =
* Prevented duplicate Media RSS and Hexa PR Wire namespace declarations from making publication feeds invalid XML.

= 2.1.0 =
* Add an administrator-only, idempotent outlet-onboarding REST contract.
* Add hierarchy revision checks, source-hosted logo/icon readback, and bounded rollback.
* Add first-party featured-image and press-release metadata to publication feeds.

= 2.0.3 =
* Add an explicit unrestricted/restricted publication-access control.
* Keep unrelated customer and pricing saves from activating restrictions.
* Preserve validated allowlist selections independently in unrestricted mode.

= 2.0.2 =
* Keep legacy customer publication access unrestricted until an administrator saves explicit limits.
* Preserve existing post publication assignments while rejecting newly assigned unauthorized publications.
* Exempt Billing's payment-fulfillment assignment from customer-side taxonomy normalization.

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
