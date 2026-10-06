=== Hexa PR Wire Core ===
Contributors: hexaprwire
Tags: press releases, syndication, publication taxonomy
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.10.1
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

= 2.10.1 =
* Plugin header and runtime version agree again (2.10.0 shipped the Echo RSS and FIFU controls with the header still reading 2.9.7).

= 2.10.0 =
* Publications cards show each site's Echo RSS and FIFU state. A warning at the top of the page, with a one-click fix, appears while Echo RSS still imports Hexa PR Wire on any site. Fixes go through each site's Distributor and switch off only the Hexa PR Wire Echo job.

= 2.9.5 =
* Publications cards: partner (REST) sites no longer show tiles for plugins they intentionally don't run; premium sites are checked with a normal page request.

= 2.9.4 =
* Publications cards notice a new plugin release within two minutes (was ten).

= 2.9.3 =
* Publications cards no longer share a CSS class with the customer profile's publication rows, which had squeezed the card header.

= 2.9.2 =
* Publications cards: the card header spans the full card width.

= 2.9.1 =
* Publications cards compare each site's plugin versions with the latest GitHub release, looked up once for all sites, so outdated plugins are flagged even when a site cannot check for updates itself.

= 2.9.0 =
* Publications page redesigned as live cards. Each card shows the connection type, site response, last sync, the Hexa plugin versions with Update buttons, and the five most recent press releases. Everything loads and updates over AJAX.
* Connection types are now Internal, REST, Plugin and Premium.

= 2.8.2 =
* Distribution panel shows each outlet's full release permalink and the outlet server's HTTP response on every checked row.

= 2.8.1 =
* Publications dashboard tells an outdated Distributor apart from a site without Distributor, and shows release titles without HTML entities.

= 2.8.0 =
* Hexa PR Wire → Publications now lists every publication with its connection type (Managed / RSS pull / External / Premium), push host, last push result and releases, and live-checks each Distributor-managed outlet (version, feed, last pull, latest copy).
* New Connection Type field on publication records.

= 2.7.0 =
* Redesigned the release editor's Force Sync box as a Distribution panel above the content. It loads over AJAX with a loader, shows each outlet as Live, Not created, Waiting or an error, and updates with a spinner when outlets are picked. Customers see it and can refresh it; Force sync stays administrator-only.
* Live-link checks now match titles containing apostrophes and other typographic characters.

= 2.6.0 =
* New Elementor dynamic tag "Publication Release URL": on a release page it links each outlet card to that outlet's copy of the release (press-release URL prefix + release slug), falling back to the outlet homepage when the outlet has no prefix. Replaces the retired client-side link rewrite (legacy snippet 51).

= 2.5.0 =
* Release Requirements: "Headings use H2" now needs at least one H2 and no other heading level (it used to pass with no headings), and updates live in the Visual editor.
* Editor Screens: Lock Modified Date (customers) option, on by default; customer limits for categories (one, as radio buttons, on by default) and tags (default 3), enforced on save.

= 2.4.0 =
* New Hexa PR Wire → Editor Screens tab with a FIFU Box toggle (on by default) that removes the FIFU box from the release editor, using Hexa WordPress Plugin Core 3.15.0's shared clean-up options.

= 2.3.3 =
* Moved View As toolbar ownership and settings to HWS Base Tools 13.3.10+.

= 2.3.2 =
* Administrator release toolbar: View as the author or Submitted By user (deduplicated), or search any account. Opens the same page in an isolated new tab using HWS Base Tools 13.3.9+ with View As enabled.

= 2.3.1 =
* The customer-published alert goes to the administrator recipients and the submitting customer's own account email.

= 2.3.0 =
* Publication access gains a third mode, “All except excluded”: a customer can use every current and future publication except the ones excluded on their profile; excluding a group also excludes its current and future outlets.
* Administrator recipients are emailed when a customer publishes or schedules a release directly (new “Customer-published alert” template), not only when a release enters pending review.

= 2.2.1 =
* Releases push to their outlets right after the editor's save response is sent (LiteSpeed/FastCGI finish-request), instead of waiting for the 15-minute server cron; deletions likewise. The cron event remains as a safety net and is cleared once the push has run.

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
