# Kimai read-only monthly import boundary

This slice provides an HTTPS GET transport, a complete-month reader, and a
snapshot preparation/capture adapter for the native Akaunting module.
It registers no financial-writing route, scheduled job or publication action.

Kimai remains the source of truth for work records. The reader requests all
users, retains the legacy timesheet fields, rejects missing or duplicate source
identities, validates pagination, and sorts entries by Kimai timesheet ID.

Because the inspected Kimai API exposes no immutable monthly revision, the
reader fetches the month twice and requires identical SHA-256 manifests.
This reduces the risk of an inconsistent paginated read but **does not prove
atomic point-in-time consistency**. An edit that occurs between page requests,
or an ABA change between full scans, can escape detection. Approval must
compare source revisions and unresolved source changes before publication.

The capture adapter records all three explicit competences, the manifest hash,
source schema and collection time. The existing `SnapshotStore` encrypts
payloads, prevents conflicting revisions and scopes reads to a company.
Callers must independently authorize the server-resolved Akaunting company
before invoking import; a supplied company ID is not permission.

## Evidence

The pure contract test has no network or financial database dependency:

```sh
php akaunting-module/tests/kimai-source-contract.php
```

The native kernel CI runs the same test with PHP 8.3. The independent
Laravel/SQLite snapshot test remains a separate integration gate.

## Follow-up gates

- Verify the configured read-only Kimai API identity, exact pagination headers,
  and month boundary behavior in an isolated synthetic integration environment.
- Validate explicit Kimai ID, Nextcloud Profile Fields and Akaunting contact links
  before converting source rows into approved member production.
- Add company-authorized capture orchestration, source reconciliation, and
  input-revision review. Do not log sensitive profiles.
- Implement Akaunting read services for company-scoped financial documents,
  structured Field Studio treatment, eligible receipts and prior appropriation.
- Keep approval, publication attempts and uncertain-write reconciliation
  separated from calculation. No production invoice creation is in scope.
