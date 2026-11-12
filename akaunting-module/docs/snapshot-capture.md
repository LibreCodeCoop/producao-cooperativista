# Source snapshot capture and reconciliation

`SnapshotStore` is an **internal, non-HTTP** store for immutable source evidence in the native module. It requires a verified backend company and source revision. No production migration or source fetching is performed here.

- Accepts the three distinct competences, a source in `kimai`, `nextcloud`, `akaunting`, a source-reported revision, a source fingerprint and capture timestamp.
- Normalizes associative JSON maps, computes an independent payload fingerprint and encrypts the payload using the current Laravel application key before database insert.
- Duplicate capture of identical source revision and content returns the existing ID; same revision with different content fails rather than overwriting history.
- Every read requires explicit company and snapshot IDs, checks fingerprint and fails on unknown company or corruption. Public-facing callers **must separately authorize** the company before invoking this service.
- Unique database constraints remain the last line of protection against concurrent duplicate snapshots. A unique-conflict is a hard failure for reconciliation, not an automatic retried write.

## Outstanding gates

The real Kimai, Nextcloud and Akaunting readers have not been connected. Before production imports, verify exactly which upstream revisions are stable; a source without immutable revision needs a manifest protocol instead of a guessed timestamp or mutable identifier.

Use synthetic company-owned source data and an ephemeral database to validate encryption, deduplication, tenant boundaries and corrupted hashes. Preserve the application encryption key securely for restore. Do not copy private profile fields to PRs or GitHub Actions.
