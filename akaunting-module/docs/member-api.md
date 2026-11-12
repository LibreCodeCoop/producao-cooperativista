# Member read-only API (foundation)

The Akaunting 3 API middleware was inspected in the LibreCode development instance: it requires an authenticated API user, `read-api`, and `company.identify`. This endpoint adds **explicit membership and member linkage** checks on the server.

Endpoint: `GET /api/v1/cooperative-production/me/history`. No member or company ID is accepted as a query/route parameter. It returns `Cache-Control: private, no-store` and `schema_version: 1`.

The `lcc_pdc_member_links` table caches a unique, time-bounded Nextcloud Profile Fields link from Akaunting user to the approved contact and Kimai ID. It is **not** a second identity authority. Links are not inferred from names, e-mails or CPF/CNPJ. Missing/expired/invalid links deny access.

The `lcc_pdc_member_statements` table is revisioned and scoped by company and contact. Unknown amounts remain JSON `null`, not `0`. The response never contains raw identifiers, CPF, source hashes or other members' financial records. Results are read only, newest revision per worked competence.

**Implementation gates:** This API intentionally exposes no financial values until authoritative importers populate approved member statements. It cannot manufacture historical payments, calculate tax from a future date or mark an invoice paid. Sync must verify Nextcloud and matching Akaunting contact and record an audit event for each link revision. API scope `read-api` must be provisioned per eligible user through the existing Akaunting ACL, not a global secret token.

**Required isolated tests:** two companies sharing an ID, two members in one company, missing/expired/conflicting link, missing company membership, cross-member document guess, session expiry, read-only enforcement and `null` handling. Validate with synthetic data and no production writes.

## Worked-competence filters and revision semantics

Optional `from` and `to` parameters must be valid `YYYY-MM` months, inclusive,
with `from <= to`. Invalid arrays, SQL-like values and reversed ranges return
`422`. Results are scoped by the authenticated user, current company and
verified contact **before** month filtering. The response includes the validated
`filters` object, and never lets a supplied identifier select another member.

The query selects the newest append-only statement ID **per worked month**
before limiting the response to 120 worked months. More than 120 revisions in
one month must not displace earlier months. It does not collapse distinct
worked/financial/payment competences or rewrite older revisions.

The adapter producing these records and real Akaunting payment reconciliation
are still **not implemented**. A non-null `paid_cents` is not populated from
an invoice due date or estimated value. The existing SQLite negative tests
require an isolated Akaunting/Laravel runtime and are not currently CI coverage.
