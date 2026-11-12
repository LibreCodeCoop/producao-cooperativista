<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Portability calculation baseline (contract v1)

This is a **synthetic golden master**, not a production data export or a claim of full financial reconciliation. It supports #278 and precedes extraction under #279.

## How to run

```sh
vendor-bin/unit/vendor/bin/phpunit -c tests/php/phpunit.xml --filter PortabilityGoldenMasterTest
```

The reference runner reads `tests/php/Fixtures/portability/calculation-v1.json` and calls the existing legacy calculation services **without HTTP, DB, or financial publication**. CI must execute the suite on the legacy's pinned PHP 8.5 environment. The target Akaunting module should implement the same input/output contract and consume the **same checked-in cases** in its PHP 8.3 environment.

Each case has `id`, `kind`, `input` and either `expected` or `error`. Expected monetary outputs are **integer cents**, and `effective_percent_basis_points` is the effective percentage multiplied by 100. `error.class` and `error.contains` capture the observed failure contract. Case IDs and schema version are stable; no automatic rewriting of expected values. Reversing valid distribution input rows must retain each member's cent total.

## Validated scope

- Distribution of preclassified eligible receipts using weighted work, including fixed-versus-variable rules, unworked contract amounts, general sponsorship, cent tie-breaking and invalid inputs.
- Administrative fee floor, safety reference and 2x expense limit (not a statutory ceiling).
- Per-customer cost allocation, excess cost blocking and unmatched cost detection.
- Decimal half-away-from-zero rounding and largest-remainder allocation.

All identifiers refer to invented members and clients. No personal IDs, secrets, real statements or live Akaunting requests appear in the vectors.

## Deliberate gaps before the complete parity gate

The input to `ProductionDistributionPlanner` is **already classified and normalized**. These vectors do **not** validate eligibility by invoice status, partial receipts, revenue appropriation across competences, snapshot reimports, tax tables, capital social, FRRA, benefits, advances, payment dates, bills, or publication attempts. Those require additional, versioned source-adapter and workflow test vectors after the native contracts are inspected, and a separate approved accounting reconciliation of real historical competences.

Never treat a green golden-master result as authorization to publish money. Compare every payable amount and error path in the same environment and keep historical differences as review items. No silent baseline update and no rewriting of paid/cancelled documents.
