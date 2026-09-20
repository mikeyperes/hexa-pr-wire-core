# Migration runbook

1. Deploy the current Core release while legacy snippets and UI definitions remain enabled.
2. Run `wp hprwc parity`; stop without mutation on any failed check.
3. Run `wp hprwc migrate` and review the dry-run selector and before/after hashes.
4. Run `wp hprwc migrate --execute`.
5. Start a fresh WordPress process and run `wp hprwc parity --post-migration`.
6. Verify plugin status/version, homepage HTTP 200, feeds, admin screens, customer policies, and payment-created draft assignment.

The execute command saves option `hprwc_legacy_migration_manifest` before mutation. It disables the audited snippets, removes only the `publication` entry from CPT UI, and changes only the selected ACF taxonomy/group definitions from `publish` to ACF's native `acf-disabled` status.

Rollback is `wp hprwc rollback --execute`. It restores each recorded snippet state, the exact prior CPT UI publication array, and each ACF record's prior status.
