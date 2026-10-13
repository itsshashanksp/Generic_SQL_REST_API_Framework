# Current limitations

These are current public-contract boundaries, not hidden supported features.
Planned work is tracked separately in [Roadmap](Roadmap.md).

## Multi-database (V3)

**Not supported by design in V3:**

- database engines other than Microsoft SQL Server;
- queries across server profiles (`CROSS_SERVER_QUERY_NOT_SUPPORTED`), linked
  servers, and `OPENQUERY`/`OPENROWSET`/`OPENDATASOURCE`;
- cross-database queries on engines without same-instance cross-database
  names: Azure SQL Database and Synapse dedicated SQL pools
  (`CROSS_DATABASE_QUERY_NOT_SUPPORTED`);
- writes or routine calls against more than one database, cross-database write
  sources, and distributed transactions;
- per-user or row-level authorization: [access scopes](Access-Scopes.md) are
  assigned per role (and to frontend users), not per individual account or row;
- persistent connections or connection pools: each request opens its own
  connection;
- literal database-qualified names in SQL Resources (use
  `{{database:id}}` placeholders).

**Not implemented:** cross-request metadata caching (only 15-second health
checks are cached), metadata federation across databases (metadata reads one
selected database), automatic encryption-key rotation or re-encryption,
and correlated subqueries.

**Not verified in the project's test environment:** execution against a real
SQL Server — including same-instance cross-database queries, collation
behavior, catalog views of other databases, and routine and write execution.
The regression suite uses recorded ODBC calls and in-memory catalogs; it shows
that the generated SQL, plans, and connections are as intended, not how a
given SQL Server responds. Verify on a dedicated non-production SQL Server
before production use.

## Universal API and security

- Authentication supports sessions, managed API keys, the legacy environment
  key, and configured anonymous mode. Tenant isolation is not implemented.
- User identity is unified, with separate frontend and backend authorization.
  System Administrators manage backend configuration; Application Administrators
  can manage frontend-only access but cannot assign backend roles.
- CORS uses exact validated backend origins; it is not itself access control.
- The endpoint accepts POST and OPTIONS only.
- [Access scopes](Access-Scopes.md) limit databases, tables, columns, SQL
  Resources, filter columns, and metadata per role, but only when configured
  and enforced; in log-only mode or with an unrestricted scope a principal with
  `data.read` can still query any table the database login can read, and
  `data.write` can change any table the login can write (ST-003). Keep the
  database login least-privileged as the second boundary.
- The data API addresses only user objects of registered databases: system
  schemas, client-written database-qualified names, and system procedures are
  rejected. SELECT and set-operation requests can combine databases of one
  server profile; metadata actions and routines select one database; SQL
  Resources address registered databases only through `{{database:id}}`
  placeholders; writes target exactly one selected database. Cross-server
  queries, multi-target or cross-database writes, and distributed
  transactions are not supported. Metadata is not cached across requests.
- Subqueries cannot refer to columns of the outer query (no correlated
  subqueries).
- There is no separate limit on the number of filters per request; it is bounded
  by the request body limit (ST-004).
- Unpaginated reads are limited to `GENERIC_MAX_RESULT_ROWS` rows (default 10,000)
  and fail with `413 RESULT_TOO_LARGE` instead of truncating.
- SQL Server over ODBC is the only database engine.

## JSON Query Mode

- Public joins are INNER/LEFT/RIGHT only, with one equality predicate. FULL,
  CROSS, APPLY, multiple ON predicates, and non-equality joins are unavailable.
- Filter subqueries exist only for IN, NOT IN, EXISTS, and NOT EXISTS. There are
  no general FROM/derived-table or SELECT-expression subqueries.
- Filter logic is one top-level AND/OR value; nested Boolean groups are absent.
- HAVING entries use aggregate comparisons and are always joined with AND.
- One standard or recursive CTE is supported. Multiple/nested CTE definitions
  and CTEs inside nested query/set branches are rejected.
- Window functions require ORDER BY and support validated expression-based
  `partitionBy`. Numeric positional ORDER BY is rejected.
- Arithmetic, nested supported functions, CASE, unary operators, and literals use
  one recursive expression model with a depth limit of 32. Function-specific
  structural arguments remain restricted by the function registry.
- `TIMEFROMPARTS` exists in the internal function/builder list but is unusable
  publicly because the required `fractions` property is rejected.
- The documented function list is exhaustive; arbitrary SQL Server functions,
  JSON/XML functions, PIVOT, and free-text expressions are not public JSON.
- Public set operations are UNION and UNION ALL only. Their branches cannot sort,
  paginate, or define CTEs, and the combined result has no top-level sort/page.
  INTERSECT/EXCEPT are internal builder capabilities only.

Use SQL Resource Mode for approved complex read-only SQL beyond these boundaries.

## SQL Resource Mode

- Clients cannot send SQL or paths. Execution expressions and placement are
  accepted only through the narrow documented grammar; they are not arbitrary SQL.
- Discovery does not parse output projections. Runtime output controls require
  explicit `execution.columns`.
- A resource is one read-only SELECT/CTE statement; SELECT INTO and multiple
  statements are rejected.
- Runtime fields must be preconfigured. Mapped WHERE/HAVING insertion on a
  top-level set operation is rejected as ambiguous; use output placement or a
  dedicated resource.
- OR cannot combine filters assigned to different output/WHERE/HAVING locations.
- Only the `integer-date` custom runtime value type is implemented.
- Pagination requires an approved runtime/default sort or an authored top-level
  ORDER BY; otherwise metadata-free resources cannot be paginated.
- An authored OFFSET/FETCH resource rejects all request filters, sorting, and
  pagination. Resource authors must choose fixed pagination or runtime controls.
- Server-owned specialized SQL is still constrained by actual SQL Server version,
  compatibility, permissions, object schema, and resource transformation rules.
- **Known gap:** the bundled frontend references widget SQL Resources that are
  not present in `queries/widgets/` (9 of 26 exist). Those widgets fail with
  `INVALID_SQL_RESOURCE` until their SQL is added.

## Write API

- One data object is accepted per request; there is no bulk insert/update/delete.
- No public begin/commit/rollback or multi-action atomic transaction exists.
- UPDATE/DELETE filters can match multiple rows; there is no single-row guarantee.
- Values cannot contain SQL expressions or request database defaults explicitly.
- There is no identity-insert override, returned-column selection, soft delete,
  optimistic-concurrency token, or generic patch-test operation.
- UPSERT uses SQL Server MERGE with HOLDLOCK and requires an exact unfiltered
  UNIQUE/PRIMARY KEY index. The API does not provide broader transaction guarantees
  or eliminate SQL Server MERGE operational caveats.
- Unsupported SQL Server data types are rejected rather than guessed.

## Routines, metadata, and responses

- Routines are called by name and must exist in the selected database.
  Parameters are positional; named or output parameters, result-set choice, and
  runtime filter/sort/page are absent. Stored procedures require `data.write`
  because the API cannot tell whether a procedure changes data.
- `source.alias` passes the shared validator for routines and metadata.columns but
  is discarded by normalization and has no effect.
- Query responses do not contain result-column type/schema metadata. Use
  `metadata.columns` for a physical table, noting that derived result schemas are
  not described.
- Public database errors are intentionally generic. Diagnose through protected
  server logs rather than response text.

## Operations

- The PHP built-in server and both launchers are development conveniences and
  single-process; they are not production multi-worker hosting.
- Sessions, rate-limit counters, and runtime state are single-host files; there
  is no shared store for multiple application hosts.
- The Admin Console has no MFA.
- No live SQL Server integration workflow ships with CI. The automated suite uses
  fakes and validates generated SQL/contracts without database credentials.
