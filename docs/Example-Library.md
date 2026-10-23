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
