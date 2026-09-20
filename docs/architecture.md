# Architecture

## Boundary

`HexaPrWire\Core` owns source-side Hexa PR Wire business behavior. Reusable WordPress mechanics come from vendored `Hexa\PluginCore` 3.0.6. Billing continues to own WooCommerce commerce and payment-created drafts. Distributor continues to own destination imports.

## Modules

- `Bootstrap`: one plugin boot and module registry.
- `Contracts`: module, repository, and mail boundaries.
- `Content`: the `publication` CPT and taxonomy definitions.
- `Fields`: stable ACF group/field definitions and release location rule.
- `Customer`: roles, modes, access policy, and enforcement.
- `Admin`: shared-tab dashboard, customer profile/actions, checklist, navigation, editor styles, and Force Sync.
- `Workflow`: ownership, link generation, notification settings, submission notices, and delivery emails.
- `Infrastructure/WordPress`: customer/publication repositories and mail adapter.
- `Syndication`: four legacy-compatible feeds and the deletion manifest.
- `Frontend`: dateline/contact/disclaimer rendering and publication shortcodes.
- `Seo`: deterministic canonical URL selection and Rank Math compatibility meta.
- `Integrations`: Billing pricing/draft assignment, Elementor query, and Force Sync bridge.
- `Migration`: status, parity, reversible legacy handoff, and WP-CLI commands.

## Stored compatibility

Existing post, user, term, and option meta names remain readable. Stable ACF group and field keys are retained. The migration changes definition ownership, not content IDs, term IDs, relationships, or release records.

## Security

- Delivery email actions are authenticated, nonce-protected, capability-checked, and recipient-validated.
- Customer permissions are enforced server-side; CSS is presentation only.
- Publication assignment is normalized for classic and REST writes.
- No plaintext password field or workflow is implemented.
- Force Sync remains credential-vault-backed and does not place credentials in URLs or UI output.
