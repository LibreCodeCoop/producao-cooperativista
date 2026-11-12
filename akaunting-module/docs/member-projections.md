# Member scenario projections — isolated domain contract

Status: **pure domain implementation only, not an HTTP endpoint or deployable portal forecast.**

This implementation extends #276/#280 by reusing the existing PHP 8.3
\`ProductionDistributionPlanner\`. It does not duplicate rate or distribution
rules, query a financial database, collect user profiles, write documents,
estimate taxes, grant rights, or calculate payments.

## Ownership and authorization boundary

Only a trusted service that has already resolved **one** authenticated
Akaunting user, active company, approved Nextcloud Profile Fields identity
link, valid Kimai ID, and contact can invoke this calculator. The
\`$memberKey\`, incomes, work and scenario input arrays **must not come from
untrusted API bodies**. This class does not authenticate users and does not
make the legacy planner's internal \`tax_number\` allocation key a valid identity
lookup. Its results never contain the other member's identifier or salary.

The service must first create an immutable, company-scoped evidence bundle
whose income and worked-member cohort are fully reconciled with their
authoritative sources. It then passes the whole verified cohort to the
existing distribution engine and returns **only the resolved member's**
projection. Incomplete cohorts fail closed: recalculating on a subset could
incorrectly increase an individual's share.

## Pure input and output contract

\`MemberScenarioProjection::project($memberKey, $source, $scenario, $evaluatedAt)\`
uses an explicit UTC evaluation time and the following input boundaries:

- \`worked_competence\`: valid \`YYYY-MM\`;
  \`synchronized_at\` and \`valid_until\`: strict UTC timestamps. If missing,
  expired, in the future, or inconsistent, the result is \`unavailable\`.
- \`public_revision\`: an opaque, safe display revision containing at most 64
  approved alphanumeric, dot, underscore or dash characters. No raw source IDs
  or fingerprints may be surfaced.
- \`evidence\` flags \`cohort_complete\`, \`identity_link_verified\`, and
  \`eligible_receipts_verified\` must be true, with full validated \`incomes\`
  and \`work\`. These flags represent **verified backend evidence**, never
  optimistic client-provided labels.
- \`observed-only\`: use the full approved-for-calculation income/work snapshot,
  including only receipts accepted by the source eligibility policy. The
  output remains **estimated** until an independent recorded approval exists.
- \`additional-hours\`: add nonnegative \`additional_seconds\` within the
  evidence bundle's \`max_additional_seconds\` and only to an already verified
  source customer/member work row. Do not fabricate new projects, members or
  received revenue. This is a deterministic hypothetical, not a time record.
- \`conditional-receipts\`: include only backend-verified classified,
  **unreceived** \`conditional_incomes\` when the additional
  \`conditional_receipts_classified\` evidence is true. Mark the result
  \`conditional\`, never banked, approved, billed or paid.

Amounts are in integer cents using the existing versioned production
allocation engine. \`estimated_production_cents\` can be zero **only if
a validated full input mathematically produces zero**. Otherwise missing,
stale, ambiguous or invalid inputs return \`null\` with stable reason codes.
\`estimated_net_cents\` is always \`null\` because taxes, FRRA, capital social,
benefits and advances are not fully confirmed or versioned for this stage.

The output includes \`scenario\`, \`state\`, \`worked_competence\`,
\`synchronized_at\`, \`source_version\`, explicit assumption codes,
\`limitations\`, \`unavailable_reasons\` and only the current member's
projection. It never implies a payable, approved amount, or guaranteed
payment. Monetary output **must not be published through a shared widget**.

## Verification and future integration

Run in an isolated PHP 8.3 runtime with BCMath:

\`\`\`sh
php akaunting-module/tests/member-scenario-projection.php
\`\`\`

The deterministic synthetic cases cover normal observed income, bounded
additional hours, verified unreceived receipts, invalid scenarios, stale
snapshots, missing cohort or member, unknown customer, invalid classifications,
null-vs-zero, and absence of other members' identifiers in the projection.

**Dependencies remaining in #275/#277:** trusted and authenticated
Nextcloud/Kimai/Akaunting adapters; source timestamp/revision semantics;
reconciliation of approval, invoice, transactions and net deductions; real
member-and-company API authorization tests; UI/browser homologation.
Do not add a route, attach this service to the portal, or enable production
until those contracts are implemented and validated. There is no source
synchronization or financial mutation in this component.
