# Hexa PR Wire Core

Versioned source-site ownership for Hexa PR Wire customers, publications, releases, editorial workflow, syndication, notifications, and Force Sync.

> Feature base for HWS Skills. Read before building on this plugin; use or
> extend these features instead of rebuilding them.

**Purpose:** source-site ownership of Hexa PR Wire customers, publications, releases, editorial workflow and syndication. **Admin:** WP Admin → Hexa PR Wire Core. **Depends on:** ACF Pro; WP-CLI commands `wp hprwc ...`.

## Features

### Publication lists
- **Does:** the network's publications for marketing pages.
- **Use:** `[display_standard_releases_table]` (all active standard-tier outlets), `[display_featured_standard_releases_links]` (featured outlets), `[display_new_sources]` (featured new outlets with icons), `[publication_press_links]` (inside a release: links to where it was published).
- **Code:** `src/Frontend/PublicationShortcodes.php`

### Release URL tag
- **Does:** a publication's link to the current release, for Elementor loops.
- **Use:** Elementor dynamic tag `hpr-publication-release-url`.
- **Code:** `src/Integrations/PublicationReleaseUrlTag.php`

### Customer modes and publication access
- **Does:** what each customer can create, publish or edit, and which publications they can use (see Customer modes below).
- **Switch:** the customer's profile in WP Admin.
- **Extend:** `hprwc_customer_default_price`, `hprwc_push_on_publish`.

### REST routes
- `hprwc/v1/author`, `hprwc/v1/deletions`, and the outlet onboarding routes below.

## Ownership

Core owns:

- the `publication` CPT and the `publication` taxonomy on WordPress posts;
- customer submission modes and publication entitlements;
- per-customer, per-publication price overrides exposed to Billing;
- release ownership, editorial checklist, delivery links, canonical URLs, feeds, shortcodes, and notifications;
- the source-side Force Sync interface;
- parity reporting and the reversible legacy handoff.
- the administrator-only source API used by Publish to plan, apply, inspect,
  reconcile and roll back one reviewed outlet onboarding operation.

Core does not own WooCommerce checkout/order fulfillment, destination imports, generic HWS site identity fields, podcast fields, or plaintext credentials.

## Shared Core

The plugin bundles the released `hexa/plugin-core` 3.4.1 package and requires 3.0.6 or newer under `Hexa\PluginCore\`, so a newer compatible copy bundled by another Hexa plugin can be selected. Reusable bootstrap, ACF/CPT/taxonomy registries, admin tabs/components, guarded AJAX, activity logging, and GitHub updater mechanics come from that package. Hexa PR Wire business policy stays under `HexaPrWire\Core\`.

## Customer modes

| Mode | Create | Publish | Edit others |
|---|---:|---:|---:|
| Edit existing | No | No | No |
| Create pending | Yes | No | No |
| Create and publish | Yes | Yes | No |
| Full access | Yes | Yes | Yes |

Administrator recipients are emailed when a release enters pending review and when a customer publishes a release directly.

View As User is owned and configured entirely by HWS Base Tools 13.3.10+. Enable its feature and select locations and optional owner metadata fields there. Hexa PR Wire Core does not provide a separate picker.

Administrators explicitly choose `Unrestricted`, `Restricted` or `All except excluded` publication access for each non-full customer. `All except excluded` allows every current and future publication except the excluded ones; excluding a group also excludes its outlets. Existing accounts default to unrestricted, and routine profile saves or price changes never activate restrictions. The validated checkbox selection is retained independently so switching modes does not erase the prepared allowlist. When restricted, the checked set is authoritative and an empty set means no access. Existing post assignments remain stable during edits. The policy is enforced in classic admin, REST, post queries, media queries, capabilities, and taxonomy assignment.

## Commands

```bash
wp hprwc status
wp hprwc parity
wp hprwc migrate
wp hprwc migrate --execute
wp hprwc parity --post-migration
wp hprwc rollback --execute
```

`migrate` is a dry run unless `--execute` is present. Execution requires passing Core parity first, saves a rollback manifest, disables only the audited snippet IDs and UI definitions, and verifies the resulting stored state.

## Outlet onboarding API

Core 2.1 exposes authenticated administrator routes under `hprwc/v1`:

- `GET /onboarding` returns exact outlet matches, the live hierarchy with full
  paths, and its immutable revision.
- `POST /onboarding/outlet` idempotently reconciles the publication record,
  hierarchy mapping, source-hosted logo/icon assignments and approved Force
  Sync host for one reviewed plan.
- `POST /onboarding/rollback` restores the recorded Core state while retaining
  newly uploaded source media for review.

The contract rejects credentials and requires both logo and icon to be existing
HTTPS attachments hosted on `hexaprwire.com`.

See `docs/architecture.md`, `docs/snippet-ownership-audit.md`, and `docs/migration-runbook.md`.
