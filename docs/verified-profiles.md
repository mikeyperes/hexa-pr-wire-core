# Verified Profiles integration

The `hprwc-verified-profiles` release-editor box is administrator-only and applies to WordPress posts, the source plugin's release type. It has no visitor output and changes no existing release on installation.

`VerifiedProfiles::query()` searches titles of published profiles from SMP's registered profile types. SMP's schema policy determines whether each result is a person. Searches and selections check the current user's access; selection is validated again on save. Organizations and unpublished profiles cannot be attached through this box.

The guarded AJAX actions `hprwc_verified_profiles_query` and `hprwc_release_subjects` require the shared Core nonce and administrator capability, plus permission to edit the specific release. Recognition uses the current editor text and SMP's existing profile-generation service and protected settings. No separate AI key, transport, provider choice or prompt is maintained by PR Wire. Chat-driven verification does not call the provider API.

`ReleaseSubjectRecognition` narrows SMP proposals to people with confidence at least 0.9 whose complete names occur in the supplied headline or opening 1,200 characters. Unicode letter and number boundaries prevent partial-name matches. Headline evidence ranks before opening-text evidence; suggestions require editorial review. Names unsupported by the release are rejected. Exact local person-name matches are shown; duplicate names remain separate choices. Subjects without a local match are displayed without an attach button. This ranks existing AI proposals; it does not replace SMP's model or prompt.

The selected `hprwc_verified_profile` is submitted with the editor's nonce and applied after the release and ACF fields have saved, using SMP's existing duplicate-safe attachment function. Existing associations are preserved. Nothing is created or attached by searching or recognizing subjects.

SMP Verified Profiles must be active, with a registered profile type and published person records for local matching. AI recognition additionally requires its enabled Profile Generation connection. Missing prerequisites produce an explicit message. PR Wire does not enable SMP features or provision its credentials as part of an update.

The box markup uses `data-hprwc-profiles`, `data-profile-name`, `data-profile-results`, and a polite live status. It uses ordinary WordPress form and button styles. `assets/admin/release-profiles.js` is loaded only on the administrator release editor. No front-end scripts or styles are added.

## Public serving API

`ReleaseProfileApi` registers public read-only GET routes under `/wp-json/hprwc/v1`. It returns only public identity fields and release summaries. No account email, login, token, or raw profile fields are exposed. Author validation requires a real user with published posts and returns that user's canonical author URL. `find_author` accepts only the exact canonical HTTPS author URL on the serving site (a trailing slash is optional), without query, fragment or credentials.

Routes and DTOs follow the bundled Core contract in `lib/hexa-wordpress-plugin-core/docs/press-release-profiles.md`. `/authors?profile_url=...` resolves an author URL, `/authors/{id}` validates an ID, and `/authors/{id}/releases` returns authored releases. `/verified-profiles?q=...` searches person titles, `/verified-profiles/{id}` validates a person, and `/verified-profiles/{id}/releases` returns releases with that stored association. Search words are 2-120 characters; the database query includes only published, unprotected profiles with explicit SMP `profile_type=person` metadata. It applies `posts_per_page` and `paged` in the database and takes totals from `found_posts`. This preserves accurate person-only pagination without loading every match. Legacy untyped profiles, even those SMP can infer to be people, must be classified as people before appearing in directory search. Direct identity validation retains SMP's existing person policy. Each page has 20 items; page is bounded to 1-1000.

Release lists require the category resolved by `CustomerTermLimits::release_category()`. A missing category returns an empty list. Author selection uses the post author; person selection matches the exact profile ID in ACF-compatible repeater rows (`profiles_<row>_profile`), shared by ACF and Core native storage. Lists exclude drafts, private posts, other categories and password-protected posts. Person identities similarly exclude unpublished and password-protected profiles. Existing release associations are never rewritten by the API.

The shared client is released as Hexa WP Core 3.63.0. It validates HTTPS origins and the common response shape. No browser scripts or styles are added for these routes.
