# Changelog

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
