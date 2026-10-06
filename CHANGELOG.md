# Changelog

## 2.9.6 — 2026-10-06

- **One card for publications, pricing and payment.** The customer profile's Publications card now hosts Billing's standard price, credit-card switch and custom services through the `hprwc_customer_billing_settings` and `hprwc_customer_billing_services` actions. Empty publication prices show the customer's effective standard price via `hprwc_customer_default_price`.
- Generic add/remove row repeater (`[data-hprwc-repeater]`) and a public `CustomerProfile::toggle_html()` switch for add-ons.

## 2.9.5 — 2026-10-06

- **Fewer misleading tiles.** "Not installed" tiles now appear only on Internal sites. Partner (REST and Plugin) sites run just Distributor by design.
- **Premium site check uses GET.** The outside check for premium sites now sends a GET with redirects followed, instead of HEAD. Newsmax answers HEAD with a 301 that was reported as "No answer".

## 2.9.4 — 2026-10-06

- **Faster release detection.** `PluginReleases` caches the latest GitHub version for 2 minutes instead of 10, so a new plugin release shows up on the cards almost immediately.

## 2.9.3 — 2026-10-06

- **Card class renamed.** Publications cards use `.hprwc-pcard*`. `.hprwc-pub` belongs to the customer profile's entitlement rows, and its `core.css` rule (`align-items: center`) was squeezing the card header. The 2.9.2 change alone did not fix it.

## 2.9.2 — 2026-10-06

- **Card header layout fix.** Publications cards now set `align-items: stretch`. An inherited admin style centered the card's children, which squeezed the header.

## 2.9.1 — 2026-10-06

- **Latest versions now come from Hexa PR Wire, not from each site.** The new `Syndication\PluginReleases` reads the `Version:` header of the plugin's main file on GitHub `main` and caches it for 10 minutes. The cards compare that against the version each site reports.
- **Why:** sites running older HWS Base Tools only load their updater in wp-admin, so they reported no latest version, and their tile showed as up to date.

## 2.9.0 — 2026-10-06

- **Publications page redesigned as live cards.** Code: `Admin\PublicationConnections`, `assets/admin/publications.js`, `assets/admin/publications.css`.
  - Each card loads its site over AJAX, four at a time. It shows:
    - site response and time
    - last sync, feed state, and whether remote updates are on
    - a tile per Hexa plugin (HWS Base Tools, Distributor, SMP Publication Integration, plus SMP Verified Profiles when installed) with installed → latest version and an Update button
    - the five most recent imported press releases
  - The page has search, type filters, a "Needs attention" filter and "Check all".
- **Updates** go through the outlet's Distributor 3.6+ routes (`/plugins`, `/plugins/update`; `OutletClient::plugins()` and `update_plugin()`). They need that site's Remote Plugin Updates switch on.
- **Connection types** are now Internal, REST, Plugin and Premium (`ConnectionType::LABELS` and `DESCRIPTIONS`).
- **Removed:** the old table, the per-outlet last-push record, and the namespace probe.

## 2.8.2 — 2026-10-06

- Distribution panel: each outlet row links its full expected release permalink instead of only the domain.
- Every checked row states the outlet server's actual answer, for example "Server response: HTTP 200 · Checked 1 minute ago". Sync failures also show the Distributor endpoint's HTTP code. No answer at all shows the connection error.

## 2.8.1 — 2026-10-05

- Publications dashboard: when an outlet has no `/health` route, `OutletClient::has_distributor()` checks Distributor's REST namespace, so the row reads **Distributor outdated** (installed, too old to report) or **Distributor not installed**.
- Live-status lines decode HTML entities, so trimmed titles end with … instead of `&hellip;`.

## 2.8.0 — 2026-10-05

- **Publications dashboard** (Hexa PR Wire → Publications, `Admin\PublicationConnections`): one table of every publication record. Each row shows:
  - its connection type
  - its approved push host
  - a live status
  - the last push from Hexa PR Wire, with result and time
  - its release count and latest release

  The table can be filtered by type or by inactive records.
- **Live check:** Distributor-managed outlets are checked over AJAX through Distributor's token-protected `/health` route, four at a time. It shows Distributor and WordPress versions, feed state, last pull and the latest imported copy, or "Unreachable" / "Token rejected" / "Distributor missing or outdated". It runs on load and from **Check managed outlets**.
- New **Connection Type** field (`connection_type`) on publication records: Managed (Distributor), RSS pull (Echo), External or Premium. Premium-tier records default to Premium.
- `ForceSyncService` keeps the latest push result per publication (`hprwc_outlet_last_push`), so the dashboard shows the latest push without scanning releases.
- `OutletClient` gains `health()`; commands and health share one token-bearing request path that keeps TLS verification on and never follows redirects.

## 2.7.0 — 2026-10-05

- Release editor: the Force Sync meta box is replaced by a **Distribution** panel rendered directly above the content (`edit_form_after_title`).
  - It loads entirely over AJAX with a loader.
  - Right after a save it shows each outlet as "Sending…" until the real-time outlet push finishes, polling while `hprwc_push_release` is still scheduled. Otherwise it re-checks every outlet link on load, four at a time.
  - Picking or unpicking outlets, in the admin taxonomy box or the customer picker, updates the list with a spinner. Newly picked, unsaved outlets show "Not created".
  - Each outlet shows one state, computed server-side by `ForceSyncAdmin::present_status()`: Live, Not created (404/410, or the page loads without the release title), Waiting (release not published), Sync failed or Error. The panel header shows a summary such as "3 of 4 live · 1 not created".
- Customers who can edit the release now see the panel and can **Refresh** (check mode). **Force sync**, outlet selection and mapping warnings stay administrator-only and are enforced again server-side per request.
- Fixed false "post title was not found" results. The live check compared the page text against the texturized, entity-encoded title (`Men&#8217;s`); both sides are now compared as decoded plain text.

## 2.6.0 — 2026-10-05

- New Elementor dynamic tag "Publication Release URL" (`hpr-publication-release-url`, URL category). In the release page's `publication_links` grid it returns `PublicationUrl::for_slug()` for the card's outlet and the queried release, so "View This Press Release On" cards open the outlet's copy instead of its homepage. Outlets without a press-release prefix fall back to their homepage. It replaces legacy snippet 51's footer JavaScript rewrite, retired on 2026-09-20 without a server-side replacement for the cards.
- `ElementorPublicationQuery` reuses one outlet lookup per release for both the grid query and the card links.

## 2.5.0 — 2026-10-05

- Release Requirements: "Headings use H2" passes only when the release has at least one H2 and no H1/H3–H6. It used to pass for releases with no headings at all. The box now also updates live while typing in the Visual editor. One rule, tested in PHP and in the editor script (`tests/editor-checklist.php`).
- Editor Screens: new "Lock Modified Date (customers)" option (on by default) removes Rank Math's switch for customers through Rank Math's own filter; staff keep it.
- Editor Screens: customer limits. "Customers can pick only one category" (on by default) shows categories as radio buttons; "Most tags a customer can add" defaults to 3. Both are enforced again on save through Core's `TermChoiceLimits`.
- Bundles Hexa WordPress Plugin Core 3.16.0.

## 2.4.0 — 2026-10-05

- New Hexa PR Wire → Editor Screens tab. Its FIFU Box toggle (on by default) removes the FIFU (Featured Image from URL) box from the release editor through WordPress's `remove_meta_box`.
- Bundles Hexa WordPress Plugin Core 3.15.0 (was 3.4.1), which owns the shared clean-up toggles and presets.

## 2.3.3 — 2026-10-03

- Removed the release-specific View As toolbar and picker. The generic, configurable feature is now owned by HWS Base Tools 13.3.10+.

## 2.3.2 — 2026-10-03

- Added an administrator-only View as user toolbar on release editors and single release pages. Shows author and Submitted By once each, plus a searchable account picker. Each action opens the same page in a new tab through HWS Base Tools' isolated sessions, preserving the administrator login and the selected user's real permissions. Requires Base Tools 13.3.9+ and its View As feature enabled.

## 2.3.1 — 2026-10-02

- The customer-published alert goes to the administrator recipients and the submitting customer's own account email.

## 2.3.0 — 2026-10-01

- Publication access gains a third mode, “All except excluded”: a customer can use every current and future publication except the ones excluded on their profile; excluding a group also excludes its current and future outlets.
- Administrator recipients are emailed when a customer publishes or schedules a release directly (new “Customer-published alert” template), not only when a release enters pending review.

## 2.2.1 — 2026-09-27

- Releases push to their outlets right after the editor's save response is sent (LiteSpeed/FastCGI finish-request), instead of waiting for the 15-minute server cron; deletions likewise. The cron event remains as a safety net and is cleared once the push has run.

## 2.2.0 — 2026-09-27

- Real-time syndication: publishing or updating a release makes each selected outlet pull it immediately (background, via each outlet's Force Sync link); trashing or deleting a release makes each outlet apply the deletion list immediately. Outlets still pull every 4 hours.
- Deletions are recorded by Hexa PR Wire post ID (`post:<ID>`) and published in `/wp-json/hprwc/v1/deletions` (`sources`), so outlets delete the exact copy regardless of slug.
- Hexa PR Wire author profile source of truth: `GET /wp-json/hprwc/v1/author` from the `hexaprwire` user; saving that user or "Push author profile to all outlets" (Hexa PR Wire → Publications) refreshes every outlet.
- Outlet feed: each release carries only its own image (third-party rss2_item output such as FIFU's outlet logo removed); unknown outlet slug returns 404; latest 50 releases by default (`limit` up to 200, `slug` for exact releases); cached until the next release change; `reprocess-*` require the shared token.
- Outlet picker: non-admins see a flat list of outlets (no group names) with Select all; single-outlet orders are locked to the purchased outlet; admins keep the full tree and ticking a group ticks its outlets.
- Custom fields migrated to Hexa WP Core 3.4.1 `Fields` (works with or without ACF); bundles Hexa WP Core 3.4.1.

## 2.1.3 — 2026-09-26

- Removed the exact Hexa WP Core 3.0.6 ceiling: Core now requires 3.0.6 or newer and bundles 3.3.0, so hexaprwire.com can run current Hexa plugins together. The parity report accepts any selected Core from 3.0.6 up.

## 2.1.2 — 2026-09-20

- Made Core namespace rendering compatible with RSS hooks that manage their own output buffers.

## 2.1.1 — 2026-09-20

- Prevented duplicate Media RSS and Hexa PR Wire namespace declarations from making publication feeds invalid XML.

## 2.1.0 — 2026-09-20

- Added an administrator-only source onboarding contract with immutable hierarchy and plan binding.
- Added idempotent publication record, taxonomy mapping, source media, destination approval and rollback handling.
- Added Media RSS featured images and versioned press-release metadata to source feeds for the native Distributor.

## 2.0.3 — 2026-09-20

- Added an explicit unrestricted/restricted publication-access control.
- Prevented unrelated profile and pricing saves from activating publication restrictions.
- Preserved validated allowlist selections independently when access is unrestricted.

## 2.0.2 — 2026-09-20

- Preserved unrestricted publication access for legacy customers until an administrator saves explicit entitlements.
- Preserved existing release publication assignments while blocking new unauthorized assignments.
- Kept Billing's payment-created draft assignment outside customer taxonomy normalization.

## 2.0.1 — 2026-09-20

- Corrected ACF UI deactivation verification for ACF's `acf-disabled` status.
- Preserved prepared migration manifests across interrupted execution and hardened rollback reporting.
- Restored Code Snippets directly from the rollback snapshot without same-request PHP redeclaration failures.

## 2.0.0 — 2026-09-20

- Refactored the plugin into namespaced, single-responsibility modules.
- Added customer access modes, publication entitlements, and per-publication prices.
- Moved the publication CPT, publication taxonomy, and five approved ACF field groups into source.
- Added release ownership, checklist, delivery links, canonical logic, feeds, shortcodes, secure notifications, and customer draft tools.
- Added a Shared Core-powered dashboard, activity reporting, parity checks, migration manifest, and rollback command.
- Preserved Force Sync behavior and its v1 compatibility facade.

## 1.0.1

- Pinned Force requests to approved destination hosts and blocked redirects.
- Restricted Force Sync to administrators and blocked retired AJAX routes.

## 1.0.0

- Introduced source-side publication resolution and secure Force Sync.
