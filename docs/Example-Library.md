# Example library and runtime SQL ownership

Application SQL Resources are local deployment configuration. Runtime resources
belong in `queries/reports/` and `queries/widgets/`; those directories are ignored
and are not framework source. Internal `queries/system/` SQL is framework-owned,
tracked, excluded from public discovery, and must not be copied or renamed.

Version-controlled references live under `examples/`:

- `examples/sql-resources/catalog/` demonstrates generic read-only SQL features.
- `examples/api/sql-resource/` and `json-query/` contain validated read requests.
- `examples/api/metadata/` and `backend-only/` demonstrate privileged API shapes;
  write and routine examples are validation fixtures and are never auto-run.

## Catalog

| Path | Purpose and dependency | Authorization and validation |
| --- | --- | --- |
| `api/json-query/advanced-select.json` | Join, CASE, aggregation, HAVING, sorting, and paging over `DemoOrders` and `DemoCustomers` in logical database `sales` | Requires an authorized read principal and matching objects; request validator coverage only |
| `api/sql-resource/runtime-filtered.json` | Runtime source/HAVING filters and paging for `catalog/customer-summary` | Requires the matching installed resource, read/resource scope, and compatible output fields |
| `api/metadata/tables.json` | Table discovery for logical database `sales` | Requires `metadata.read` and an access scope that permits the database/list operation |
| `api/backend-only/insert.json` | Single-row insert into illustrative `DemoNotes` | Requires `data.write`; never issued or auto-run by Dashboard |
| `api/backend-only/procedure.json` | Illustrative `dbo.RebuildDemoSummary` call | Requires routine and write permissions; never auto-run |
| `sql-resources/catalog/basic-orders.sql` | Bounded order list with CASE and deterministic order | Requires `DemoOrders`; review and install deliberately |
| `sql-resources/catalog/customer-summary.sql` | Customer/order aggregation used by the SQL Resource request example | Requires `DemoCustomers` and `DemoOrders` |
| `sql-resources/catalog/advanced-ranking.sql` | CTE and monthly window ranking | Requires `DemoOrders` and a compatible SQL Server version |
| `sql-resources/catalog/set-and-subquery.sql` | `UNION ALL` and correlated `EXISTS` | Requires `DemoOrders`, `DemoCustomers`, and `DemoOrderArchive` |
| `sql-resources/catalog/cross-database.sql` | Registered logical database placeholders | Replace `crm` and `sales`; both databases must use one server profile |

All objects and logical database IDs are illustrative. Validate structure with:

```bash
php tests/ExampleLibraryTest.php
```

That command does not execute SQL. Live verification requires a disposable,
authorized SQL Server environment with matching objects and access scopes.

## Activating examples

From the backend repository root, copy only the resources the application needs:

```bash
mkdir -p queries/catalog
cp examples/sql-resources/catalog/customer-summary.sql queries/catalog/customer-summary.sql
```

The relative path determines the ID: the file above becomes
`catalog/customer-summary`. No registry edit is needed. Do not copy the entire
library blindly: every discovered file is trusted backend code potentially
available to callers with the relevant authorization. Review SQL, access scopes,
application resource policy, physical schema, database permissions, and query
plans first.

The framework accepts a single read-only SELECT/CTE statement. Runtime values use
validated prepared filters, never text substitution. Output/source/HAVING filter
placement, sorting, paging, database selection, cross-database placeholders, and
resource naming are defined in [SQL Resource authoring](SQL-Resource-Authoring.md).

Run `php tests/ExampleLibraryTest.php` to parse every API JSON fixture, analyze
every SQL example, and prove examples remain outside live discovery. This is
structural verification only; execute against a disposable matching SQL Server
before claiming database compatibility.

Historical application SQL needed by database-independent regression tests is
isolated under `tests/fixtures/sql-resources/`. The test runner installs only
missing fixtures for its lifetime and removes exactly what it created. These
fixtures are not public examples and are never loaded by production startup.
