# Akaunting category reconciliation — LibreCode company 1 (2026-11-08)

The production Akaunting categories were reconciled on 2026-11-08.
Do **not** issue/cancel NFS-e or modify financial amounts during a category import.

| Category ID | Result | Financial impact |
| --- | --- | --- |
| 19 | Renamed `Cliente > Externo > Serviço`, parent 45 preserved | Consolidated 31 documents (30 active and one historical), 32 transactions and 11 document lines from category 20 |
| 20 | Removed after references moved to 19 | Keep historical documents and fiscal receipt IDs; do not reintroduce it on ETL refresh |
| 28 | Renamed `Custos de clientes`, moved to root | Client costs remain separate from external income (45) and internal costs (16); ID unchanged |
| 59 | Attached to parent 46 (`Cliente > Interno`) | One transaction preserved |
| 37 | Removed (no category references) | No financial impact |
| 5 | Six catalog items moved from 19 to `Geral` | Item IDs and document lines unchanged |
| 24 / 25 | 14 income-yield and 9 cooperative-capital invoices recategorized from deposit (2) | 12 linked transactions and one line recategorized; amounts/receipts unchanged |

### Produção Cooperativista / PR #263

- Keep `AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID=45` and `AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID=28`. Never move 28 below the internal expense parent (16).
- Keep the configured IDs 11, 25, 33, 34, 35, and 49. These are referenced by production, FRRA, advance, capital-social and surplus rules.
- Category sync now migrates known references from category 20 to 19, then physically removes absent source categories from the cooperative staging database. No obsolete category row is retained; invoices/transactions and their NFS-e identifiers remain intact. Unmapped categories with references cause a transaction rollback, and an empty source fails closed.
- Run source refresh and a read-only financial preview in the production-cooperative environment. Compare eligible customer receipts, customer costs, internal expenses, surplus and tax calculations before authorizing publication.
- Keep historical test fixtures that intentionally model older years; add the current category hierarchy as a separate regression rather than rewriting historical source data.

**Review checklist:** Akaunting displays 43 live categories; 19 under parent 45, 28 as root, 59 under 46, and no live 20/37. Reimport categories; assert no phantom 20. Financial documents and NFS-e attachments must retain their original identifiers. Do not publish payables from this reconciliation.
