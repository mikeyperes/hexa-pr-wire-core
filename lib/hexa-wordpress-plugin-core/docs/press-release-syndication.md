# Press-release syndication

The source and distributor use the same RSS, deletion and author formats in `src/PressReleaseSyndication/`.

- `FeedProtocol` declares the outlet feed name, publication query parameter, and version. It writes RSS item XML and reads the established WordPress RSS fields and extensions. The distributor decides which source and image hosts are trusted, resolves its stored post identities, and applies its content and publishing policies.
- `DeletionProtocol` assembles and parses the JSON manifest and comma-separated or newline-separated legacy slugs. The source owns its deletion ledger. The distributor owns preview, matching and removal.
- `AuthorProtocol` publishes the profile and social URL format and reads and merges author responses. The source owns the profile and avatar lookup. The distributor owns its stored fallback, cache, and provisioning.

The feed reader preserves the historical whitespace repair for adjacent namespace declarations. Route names and endpoint response formats remain the same so older sources and distributors can coexist.
