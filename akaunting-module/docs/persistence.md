# Native persistence contract — cooperative production

This module schema is prepared for an isolated Akaunting database, **not deployed into production**.

## Data ownership

- `lcc_pdc_snapshots`: immutable per-company and per-competence source revisions, separate worked/financial/payment competences, SHA-256 digests and an encrypted payload field. The importer must encrypt before inserting.
- `lcc_pdc_approval_events`: append-only approval/revocation events bound to one input revision, actor and decision digest.
- `lcc_pdc_publication_attempts`: legacy IDs and fingerprints preserved. `pending` and `uncertain` writes must block new sends until reconciled.
- `lcc_pdc_income_appropriations`: reserved and confirmed revenue allocation by company/source/competence and integer cents; never double-count receipts.

Tables are namespaced and preserve financial/audit history in an intentionally non-destructive down() method.

## Migration gate

1. Audit legacy source rows, indexes, counts, company assignments and state distributions without exporting personal data to public CI.
2. Freeze an immutable source revision; validate a single, idempotent, source-keyed import. This migration adds schema only, not an importer.
3. Compare per-company, per-competence row counts, digests and integer-cent sums.
4. Reconcile every ambiguous attempt and reservation before any financial publication.
5. Keep the legacy app and a verified backup available. Preserve the appropriate application encryption key for recovery without publishing it.
6. Validate isolated database migrations and negative tenant isolation tests before any production cutover.

Financial documents, paid or cancelled items and other live sources are not modified.

Source references: `Version20261031020000`, `Version20261110173000`, and `docs/publication-recovery.md`.
