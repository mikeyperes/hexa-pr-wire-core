# Changelog

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
