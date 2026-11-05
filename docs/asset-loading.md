# Asset loading

Distribution uses the existing `hprwc-force-sync-admin` handles: `assets/admin.js` and `assets/admin.css`, enqueued by `ForceSyncAdmin::enqueue_assets()` only on post.php/post-new.php for the post type and users permitted to view the panel. Category links use the same script; no added script, stylesheet or front-end hook.

## 2.22.4 inventory

- Home page: no Distribution assets before or after (admin-only enqueue hook).
- Article: no Distribution assets before or after (admin-only enqueue hook).
- Feature page: Distribution is available only in the release editor, not on a public feature page.
- Release editor: `hprwc-force-sync-admin` script and style before and after, version changes 2.22.3 to 2.22.4. Other editor handles remain the existing core.css, release-date.js, editor-checklist.js and delivery-notifications.js. No new asset requests are introduced.
