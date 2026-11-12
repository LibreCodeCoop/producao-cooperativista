# Member portal interface (foundation)

The native Akaunting portal page uses `Route::portal('cooperative-production', ...)`; Akaunting's `portal` middleware authenticates the user, checks the current company and requires `read-client-portal`. The domain reader independently enforces the explicit member-company-contact link.

The page renders only the member's recorded historical statement revisions. Values absent from authoritative sources are shown as unavailable. Paid amounts are charted **only when actually present in a recorded statement**, and estimates are explicitly not represented as paid. There is no financial publication action.

Pending gates: an authenticated and authorized source importer, reconciliation to the actual Akaunting payment ledger, native module activation in an **isolated** homologation instance, browser E2E with two company/users, screenshots with synthetic fixtures, additional forecast contracts and accessibility review. Never screenshot private financial information.

The module must not be enabled on production solely because this page renders successfully.
