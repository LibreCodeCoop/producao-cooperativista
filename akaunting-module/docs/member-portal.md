# Member portal interface (foundation)

The native Akaunting portal page uses `Route::portal('cooperative-production', ...)`; Akaunting's `portal` middleware authenticates the user, checks the current company and requires `read-client-portal`. The domain reader independently enforces the explicit member-company-contact link.

The page renders only the member's recorded historical statement revisions. Values absent from authoritative sources are shown as unavailable. Paid amounts are charted **only when actually present in a recorded statement**, and estimates are explicitly not represented as paid. There is no financial publication action.

Pending gates: an authenticated and authorized source importer, reconciliation to the actual Akaunting payment ledger, native module activation in an **isolated** homologation instance, browser E2E with two company/users, screenshots with synthetic fixtures, additional forecast contracts and accessibility review. Never screenshot private financial information.

The module must not be enabled on production solely because this page renders successfully.

## History filters and accessibility

The member page exposes labelled, native month inputs for a start and end
worked competence. The form uses GET and no member, company or contact ID.
The read service validates filter types and order with HTTP 422 for malformed
ranges, preserving only validated values in the form. The chart reflects the
same authorised filtered records as the cards; it never treats unknown paid
amounts as zero. Both small-screen cards and graph labels use text equivalents.

**Visual review status:** a static synthetic wireframe is a review aid, not
evidence of an Akaunting browser test. A real isolated Akaunting portal must
still be checked for menus, styling, role ACL, mobile layout and accessibility.
