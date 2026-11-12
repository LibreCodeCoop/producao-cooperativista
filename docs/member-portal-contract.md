<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Member portal identity, forecast and read-only API contract

Status: **design contract proposed for review**, not deployed or enabled. Supports #276 and #280; final route and authorization mechanisms depend on #277.

## Security authority and principal mapping

- **Authentication:** require an authenticated Akaunting user/session (or a user-bound, properly scoped API credential for a future Nextcloud client). HTTP Basic credentials shared by the legacy application must **never** authorize a member endpoint.
- **Server-side lookup:** resolve the active Akaunting company from the trusted server session, then resolve one unique, explicitly approved link between that Akaunting user, the corresponding Nextcloud account and its `kimai_user_id` / `akaunting_contact_id`. Nextcloud Profile Fields remain the source of identity linkage; they are not a login provider.
- **Fail closed:** missing, stale, conflicting, disabled, unapproved or multiple matches yield no financial rows. Do not match by CPF/CNPJ, e-mail, name, request parameter or a guessed contact. CPF/CNPJ is a reconciliation attribute only.
- **Tenant isolation:** every read joins or scopes source snapshots, appropriation ledgers, production decisions, bills and payments by company **and** the resolved member/contact identity. Authorization is enforced in the server domain/query path, not by hiding menu items or frontend filters. A user-supplied ID may not extend scope.
- **Administrative privileges:** staff-wide financial access requires a distinct, explicitly configured Akaunting permission and cannot be inferred merely from being a cooperado. Treat permission checks as additive to ownership; audit sensitive administrative queries without logging financial payloads.
- **No credential propagation:** never expose Nextcloud Profile Fields technical IDs, CPF/CNPJ, raw source hashes, source tokens, other members or cross-company aggregate amounts in the individual response.

This is a contract to implement and negative-test, not a claim that Akaunting already exposes a suitable member-to-user mapping. Inspect the actual Akaunting ACL and API middleware before selecting the mechanism. `akaunting-field-studio` demonstrates explicit `Access` checks and company-scoped fields, but it does not prove the member portal's authorization.

## Candidate v1 JSON shapes (path deliberately not finalized)

The response schema illustrates semantics and must be reviewed before choosing routes:

```json
{
  "schema_version": 1,
  "data": {
    "worked_competence": "2026-09",
    "revenue_competence": "2026-10",
    "payment_competence": "2026-11",
    "observed_at": "2026-11-11T15:00:00Z",
    "snapshot_revision": "opaque-version",
    "currency": "BRL",
    "status": "estimated",
    "hours": {"observed_seconds": 21600, "as_of": "2026-11-11T15:00:00Z"},
    "money": {
      "eligible_production_cents": 780000,
      "approved_gross_cents": null,
      "deductions_cents": null,
      "approved_net_cents": null,
      "billed_cents": null,
      "paid_cents": null
    },
    "forecast": {
      "scenario": "observed-only",
      "estimated_net_cents": null,
      "limitations": ["The receipt base has not been approved", "Future deductions are not determined"]
    }
  }
}
```

The numbers and IDs above are **invented example values**; they are not real member history. The example `estimated_net_cents: null` is intentional: an estimated result is unavailable when necessary inputs are not known, and cannot be replaced with zero.

Proposed API operations, names not yet bound to routes:

| Operation | Input | Contract |
| --- | --- | --- |
| Member summary | Trusted session; optional validated competence | Read only the resolved member; return current/historical statuses and sync time |
| History | Page cursor, date range and validated sort | Read only the resolved member's historical decisions, documents and payments |
| Forecast | Explicit scenario and reviewed assumptions | Return derived, labelled estimates with provenance and unavailability reasons |
| Document summary | Opaque identifier from previously authorized listing | Enforce exact company/member/resource ACL **again** before returning |

No endpoint accepts `company_id`, `contact_id` or `member_id` as an authorization shortcut. Administrators need a separately namespaced/staff-authorized resource.

## Financial state semantics

| State | Evidence | User-facing language |
| --- | --- | --- |
| `observed` | Kimai time snapshot, with timestamp | Hours recorded, not approved remuneration |
| `estimated` | Deterministic scenario and explicitly declared receipt/policy assumptions | Projection, not guaranteed earnings or a payable |
| `approved` | Immutable production approval + revision | Approved amount, not necessarily invoiced |
| `billed` | Akaunting payable document, scoped to the correct contact and company | Account recorded, not proof of payment |
| `partially_paid` | Confirmed individual payment transactions | Paid part + remaining balance |
| `paid` | Confirmed matched payments | Payment recorded; do not infer from document existence |
| `unavailable` | Missing/ambiguous or stale source data | Value unavailable, not zero |

A status transition must come from authoritative evidence. Preserve worked, revenue and payment competences independently. A cancelled payable cannot be shown as paid without a separately verified payment trail. FRRA and social capital deductions require separate labels and cannot be conflated with incoming bank transfers.

## Forecast decision rules

- **Default scenario:** `observed-only` uses known hours, profile/policy and known eligible receipts at the snapshot boundary, explicitly showing which amounts are provisional.
- **Additional-hours scenario:** accept nonnegative, bounded future hours, maintain the current eligibility and weight policy; do not fabricate future received revenue.
- **Conditional-receipts scenario:** show the effect of currently unreceived receivables only when their classification is verified. Clearly distinguish `conditional` revenue from cash received or expressly approved advance inclusion.
- **Unknown taxes/advances/FRRA:** represent unavailable or conditional estimates as `null` with explicit limitations, never assume they are zero.
- **No statistical confidence interval** without calibration against historical out-of-sample results. Do not present a deterministic what-if range as a probabilistic forecast.

Rates, deductions, 2026/2027 tax treatment and social capital are versioned by effective competence. UI must make stale snapshots and conflicting records visible; the endpoint must not publish calculations or trigger financial writes.

## Errors, privacy and cache

- `401`: no valid session/credential; `403`: authenticated but not authorized for the company or capability.
- `404`: member-scoped resource absent or inaccessible, with the same safe response; no ID enumeration.
- `409`: conflicting/multiple identity links or financial states requiring reconciliation.
- `422`: unsupported competence, scenario or invalid input; `503`: authoritative source temporarily unavailable (without returning cached amounts as fresh).
- Member responses: `Cache-Control: private, no-store`; prevent shared widget/API caches from combining identities. Scrub query and exception logs.
- No personal financial metrics in anonymous pages, shared Nextcloud widgets, telemetry or published screenshots.

## Test matrix before implementation

1. Two different users in the **same** company cannot query each other's member records by guessing document/competence identifiers.
2. Two different companies with overlapping source IDs and contacts remain isolated; tenant switch cannot reuse previously cached authorized results.
3. Role without individual link is denied; disabled or ambiguous links fail closed.
4. A non-admin member cannot access staff-wide lists, aggregate remuneration or the publication endpoint.
5. Unauthenticated and expired sessions fail; tampered user/tenant parameters cannot bypass server-side resolution.
6. Missing receipt, tax and deduction inputs yield `null` with explanation; zero is shown only where definitively known.
7. Historical payment statuses are derived from matched payments, not the bill issue date or predicted payment date.
8. CSS/JS widgets never carry identifiers, secrets or other member values in public HTML or shared cache keys.
9. A JSON GET request cannot cause import, approval, financial mutation or source synchronization as a side effect.

Tests use two synthetic companies and multiple synthetic member identities. Provide accessible responsive wireframes and screenshots using invented data before accepting the portal UI; member summaries must never expose third-party salaries. Use current Akaunting translations and menus, and place user/admin instructions within the native module's documentation.

## Planning handoff

Do not create or publish read endpoints until #277 establishes the real Akaunting ACL and identity-link contracts and #278/#279 confirm the output fields. Keep #276 progressive: first resolve identity/security and estimates, then decompose actual API, UI, graph, documentation and integration issues according to demonstrated contracts. The final Nextcloud consumer must use user-bound authorization, not a global bearer token containing every cooperado's data.
