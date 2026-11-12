<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# ADR 0001 — Native Akaunting cooperative production module

Status: **conditional design decision** (implementation gates below). Relates to #275 and #277.
Baseline inspected: legacy `main` at `9f73d31bbad15ef9a4fd510acf9b81f571f0be6e`.

## Context and verified constraints

| Concern | Current legacy | Target instance inspected |
| --- | --- | --- |
| Runtime | `composer.json`: PHP `^8.5`, Symfony `^8.1`, Doctrine ORM `^3.7` | Local Akaunting 3 development Dockerfile: PHP `8.3.36`; Akaunting `composer.json`: Laravel `^10`, PHP `^8.2`, Symfony HTTP client `^6` |
| Rounding | `DecimalAllocation::cents()` uses `bcround` and `RoundingMode::HalfAwayFromZero`, introduced in PHP 8.4 | The same class cannot be loaded unchanged under PHP 8.3 |
| State | Import staging, per-competence source snapshots, approval and publication | Akaunting provides its own company, user, contacts, documents and payments |
| Durable journals | `publication_write_attempts` and `production_income_appropriations`, with intentionally irreversible Doctrine migrations | No proven equivalent native module tables yet |
| Authority | Kimai time entries; Nextcloud Profile Fields for explicit identities, weight and dependents; Akaunting for documents and financial status | These external sources retain ownership of their records |

The target runtime above is the inspected **development installation**, not a claim about the production container. Confirm the homologation image and lockfile before enabling the module.

A single Composer dependency graph cannot safely embed the current Symfony 8 application into the Akaunting/Laravel runtime. In particular, upgrading PHP alone does not address the incompatible Symfony component majors. No upgrade of the accounting core is authorized by this ADR.

## Decision

1. Build an **independently installable Akaunting module** following the existing native-module convention, using Laravel controllers, policies, migrations, translations and Akaunting's authenticated company context. Keep core Akaunting unchanged. Package location/repository is to be confirmed before implementing the scaffold; do not silently modify an unrelated module.
2. First extract the **pure economic calculation kernel** behind a versioned input/output contract. It must not depend on Symfony, Doctrine, Laravel, HTTP, database, current timestamps or environment variables. Target PHP 8.3 and BCMath only after an exact-money equivalence test proves a PHP 8.3-compatible replacement for `bcround(..., HalfAwayFromZero)`; no silent rounding-policy change.
3. Keep the legacy application, its staging database and immutable evidence available throughout comparison and migration. Source snapshots are copied with original identifiers, competence, collection timestamp, schema revision, digest and provenance. Preserve original attempt IDs, operation keys, states and source appropriation amounts in integer cents.
4. Implement module-owned **company-scoped** persistence. Every financial query, approval, attempt and historical record must use a server-resolved company and explicit user permissions; never trust a client-supplied `company_id` or member ID. The Akaunting single-database deployment does **not** imply tenant isolation.
5. Divide read-only preview, authorized approval and explicit financial publication. A preview is not permission to publish. Existing `pending`/`uncertain` outcomes and `reserved` appropriations fail closed; only reconciliation under explicit operational approval may resume. No automatic POST/PATCH retry on ambiguous responses.
6. Expose internal read-only module data through an authenticated JSON contract after the #280 privacy/identity gate. The future Nextcloud integration is a **consumer**, not a secondary source of financial truth.

## Evidence and boundary mapping

| Legacy component | Intended boundary | Transfer gate |
| --- | --- | --- |
| `ProductionDistributionPlanner`, `WeightedAllocator`, `DecimalAllocation`, `AdministrativeRate`, `CustomerCostAllocator` | Framework-free economic kernel | Versioned synthetic golden cases; cents and error parity, including customer allocation |
| `CooperativeProfile`, `LibreCodeProfile`, policy strategies | Explicit policy profile/version | No new/default policy may change existing LibreCode results |
| `SourcePipeline`, Kimai and Nextcloud clients | External read adapters | Snapshot consistency, authenticated source, no auto-link by name/CPF/email |
| Doctrine migrations and staging entities | Read-only legacy source until audited import | Dry-run manifests, row counts, per-company and per-competence hashes, audited reconciliation |
| `publication_write_attempts`, `production_income_appropriations` | Durable native ledgers, not disposable staging | State/identity/cents preservation; no duplicate or missing operations |
| `PublicationRouter`, `LibreCodeAkauntingPublisher` | Server-only publication service | Local Akaunting-only writes; reject paid/cancelled documents and ambiguous outcomes |
| Legacy preview and approval UI | Akaunting native role-based pages | Draft UX, translated labels, accessibility and screenshots with synthetic data |
| `akaunting-reports`, `akaunting-nfse`, `akaunting-field-studio` | Independent module integrations | Inspect their current APIs, schemas and permissions before using; no guessed internal route |

The current `production-treatment.md` distinguishes **worked competence**, **financial receipt competence** and **payment competence**. A new snapshot and portal response must preserve all three fields. Paid/issued/draft/void status is not interchangeable with a work-period calculation. Capital social, FRRA, advances and tax deductions must stay separate from incoming bank receipts. Excluding or appropriating a receipt does not create a bank transaction.

## Minimum contract and invariant tests

- Calculation input: explicit policy version, worked competence, preclassified income rows with source identity and currency, weighted work entries, configuration snapshot and provenance. Never accept account balance as eligible income.
- Calculation output: per-member integer cents and source references; totals, retained undistributed amounts, rounding decisions, validation errors and input digest. The baseline may still expose floats at its current boundary; normalize only at the payable boundary and record that limitation.
- Read snapshot: identified `company_id`, external IDs, recorded-at time, source revision, source-specific financial period, source digest and effective policy revision.
- Publication attempt: stable operation key, payload digest, origin snapshot revision, target company, method, state and remote response identity, with durable reservation **before** sending. Concurrent publication and unknown result must block, not retry.
- Appropriation: original source type/ID, company, financial/worked competence, full source amount cents, allocated cents, revision and `reserved|confirmed` state. Already allocated cents reduce future eligibility.
- Security: run read/approve/publish checks with two companies and two cooperative members; deny cross-company, cross-member, unauthenticated and read-only publication requests at the server boundary.
- Comparison: execute both implementations against the same immutable synthetic vectors. Assert exact cent values and expected failure behavior. Any historical discrepancy requires a recorded reconciliation decision; never rewrite a paid/cancelled document to make a fixture pass.

## Migration, homologation and reversible cutover

1. Freeze and checksum source data and schema mappings **without** stopping the legacy app; retain a restorable backup.
2. Import into an isolated native module schema, retaining legacy keys and journaling an idempotent per-source copy. Verify row counts, uniqueness, source totals, hashes, and every `pending`/`uncertain` or `reserved` state.
3. Compare the same frozen competences using legacy and module readers. Differences in eligibility, totals, rounding, person identity, statuses, approval or tax period are blockers, not tolerated drift.
4. Exercise preview → review → approve → publish **only against disposable local Akaunting financial fixtures**; simulate lost responses, concurrent operations, partial batch failure and recovery.
5. Run read-only parallel operation and document both directions of the rollback. Cutover requires explicit functional and operational authorization. Rollback switches application routing, **not** deletes tables or repeats writes. Historical ledgers remain queryable after rollback.
6. Do not disable the old application, create production invoices, change historic paid documents, run production migrations or call live financial POST/PATCH within this ADR's implementation.

## Alternatives rejected for now

- **Copy Symfony services into Akaunting:** conflicting framework/dependency majors and PHP 8.4+ financial rounding calls.
- **Recompute history on demand and delete staging:** loses source revisions, approval context, uncertain attempts and prior income appropriation.
- **Financial API calls within every module preview:** adds transport ambiguity and weakens transaction consistency.
- **Auto-retry or "fix" paid bills to restore parity:** breaks the existing publication safety contract.

## Open gates and handoff

- Verify exact installed Akaunting version and the PHP/Symfony/BCMath lock set in the intended **homologation** environment, not only the developer Dockerfile.
- Decide module package repository and distribution/license review (legacy module AGPL and Akaunting core BUSL) before publishing a distributable bundle.
- Inventory the real native Akaunting document/contact/category APIs, policy/ACL hooks and transaction boundaries; demonstrate company scoping with negative tests.
- Land #278 golden scenarios **before** extracting #279, then prove PHP 8.3 cents parity against the PHP 8.5 reference runtime.
- Inspect historical staging/migrations thoroughly and produce a non-destructive migration mapping before native ledger migrations.
- Refine downstream #275 issues and revisit #276 only against these verified contracts. Native GitHub parent/dependency relations are to be recorded separately; this ADR does not replace those relations.

References: `composer.json`, `docs/money-policy.md`, `docs/member-data-authority.md`, `docs/production-treatment.md`, `docs/publication-recovery.md`, `migrations/Version20261031020000.php`, `migrations/Version20261110173000.php`, `src/Service/Calculation/`.
