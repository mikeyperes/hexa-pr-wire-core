# Hexa PR Wire Core

Versioned source-site ownership for Hexa PR Wire customers, publications, releases, editorial workflow, syndication, notifications, and Force Sync.

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

The plugin registers the released `hexa/plugin-core` 3.0.6 package under `Hexa\PluginCore\`. Reusable bootstrap, ACF/CPT/taxonomy registries, admin tabs/components, guarded AJAX, activity logging, and GitHub updater mechanics come from that package. Hexa PR Wire business policy stays under `HexaPrWire\Core\`.

## Customer modes

| Mode | Create | Publish | Edit others |
|---|---:|---:|---:|
| Edit existing | No | No | No |
| Create pending | Yes | No | No |
| Create and publish | Yes | Yes | No |
| Full access | Yes | Yes | Yes |

Administrators explicitly choose `Unrestricted` or `Restricted` publication access for each non-full customer. Existing accounts default to unrestricted, and routine profile saves or price changes never activate restrictions. The validated checkbox selection is retained independently so switching modes does not erase the prepared allowlist. When restricted, the checked set is authoritative and an empty set means no access. Existing post assignments remain stable during edits. The policy is enforced in classic admin, REST, post queries, media queries, capabilities, and taxonomy assignment.

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
