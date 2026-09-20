# Code Snippets ownership audit

Site: `https://hexaprwire.com/`  
Original saved audit: `/home/hexaprwire/.hws-audits/hexa-pr-wire-core-snippets-audit-2026-09-20.md`

## Migrated into Hexa PR Wire Core

| ID | Functionality | Core owner |
|---:|---|---|
| 6 | TinyMCE editorial formats | `Admin\EditorStyles` |
| 8 | Four RSS feed routes and renderers | `Syndication` |
| 10 | release ownership and delivery-link generation | `Workflow` |
| 17 | dateline, contacts, and disclaimer rendering | `Frontend\PressReleaseContent` |
| 18 | customer role and post/media scope | `Customer` |
| 20 | customer navigation/editor behavior | `Admin\CustomerNavigation` and assets |
| 22 | pending-review notification | `Workflow\SubmissionNotifications` |
| 25 | publication table/new-source shortcodes | `Frontend\PublicationShortcodes` |
| 27 | live-link and draft-update email controls | `Workflow\DeliveryNotifications` |
| 29 | deletion manifest compatibility | `Syndication\DeletionManifest` |
| 32 | customer profile, manual draft, onboarding, notification recipients | `Admin\CustomerProfile` and `Admin\CustomerActions` |
| 35 | safe post-create user redirect | `Admin\CustomerActions` |
| 36 | default recent-user ordering | `Admin\CustomerProfile` |
| 37 | new-user field ordering | `assets/admin/core.js` |
| 38 | aggregate draft/release counts | `Admin\CustomerProfile` |
| 47 | publication taxonomy controls | Force Sync admin asset; no auto-select entitlement bypass |
| 48 | deterministic canonical URL | `Seo\CanonicalService` |
| 49 | publication links and Elementor query | `Frontend` and `Integrations` |
| 51 | server-generated publication URLs | `Domain\Publication\PublicationUrl` |

Already superseded and included in the final disabled set: `26, 30, 34, 43, 44, 52`.

## Explicitly excluded

Generic SEO/cache/media/theme behavior, podcast fields, server log rotation, destination deletion polling, and plaintext password storage do not belong in Core. Checkout/payment behavior remains in Billing; destination import behavior remains in Distributor.

## Final disabled snippet set

`6, 8, 10, 17, 18, 20, 22, 25, 26, 27, 29, 30, 32, 34, 35, 36, 37, 38, 43, 44, 47, 48, 49, 51, 52`
