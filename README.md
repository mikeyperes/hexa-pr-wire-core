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

Non-full customers can see and assign only the publication terms selected on their user profile. The policy is enforced in classic admin, REST, post queries, media queries, capabilities, and taxonomy assignment.

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

See `docs/architecture.md`, `docs/snippet-ownership-audit.md`, and `docs/migration-runbook.md`.
