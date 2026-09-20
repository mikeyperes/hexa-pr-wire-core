# Shared Core, ACF, CPT, and taxonomy audit

Original saved audit: `/home/hexaprwire/.hws-audits/hexa-pr-wire-core-shared-core-acf-cpt-audit-2026-09-20.md`

## Shared Core used

The plugin vendors/registers `hexa/plugin-core` 3.0.6 and uses its bootstrap/runtime, content-type registry, taxonomy registry, ACF field-group registry/factory, admin tabs/components, AJAX guard, activity log, and GitHub updater.

## Definitions moved

- CPT `publication` (existing publication records remain unchanged).
- Hierarchical taxonomy `publication` on ordinary WordPress `post` records.
- `group_6506a8003237a` — publication registry.
- `group_69326f80f12ff` — taxonomy-to-publication mapping.
- `group_64a72abaeeff0` — public release fields.
- `group_64a6e0e504f9e` — editorial checklist fields.
- `group_63a0418e58839` — private release workflow fields.
- Customer-owned fields from legacy `group_64a74dfaa21da` were converted to the customer profile interface.
- Legacy draft-notification options from `group_65a0884cd33ba` were converted to Core notification settings.

## Definitions not moved

- Ordinary releases remain WordPress `post` records.
- Distributor-owned CPT `press-release`.
- Billing fields and order linkage.
- HWS Base Tools general information/user profile fields.
- Podcast fields.
- Plaintext password fields.

Database-backed definitions are disabled, never deleted, after the code-owned definitions pass live parity. Existing values remain in their original metadata tables.
