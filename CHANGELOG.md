# Changelog

All notable changes are recorded here. The project follows semantic versioning.
Planned work is in [docs/Roadmap.md](docs/Roadmap.md).

## [Unreleased]

### Added

- A tracked, opt-in `examples/` library now preserves application SQL Resources,
  provides generic read-only SQL and validated API request examples, and tests
  that examples stay outside live discovery. Runtime `queries/reports/` and
  `queries/widgets/` are deployment-owned and ignored; `queries/system/` remains
  tracked internal framework code.

- Public SQL examples are limited to the generic catalog. Historical application
  resources needed by regression tests are isolated under `tests/fixtures/` and
  are never production discovery inputs.

### Security

- **Access scopes** (`config/access-scopes.json`) limit each role, and every
  principal with frontend access, to approved databases, tables, columns, SQL
  Resources with their filter columns, and metadata operations, enforced before
  execution for direct API requests. `log-only` and `enforce` modes support a
  reviewed rollout; production fails closed when the file is missing or
  invalid. See [Access scopes](docs/Access-Scopes.md).
- `union`, `unionAll`, and `metadata.*` are denied to frontend users unless a
  scope grants them; routines and writes need an explicitly unrestricted scope.
- Read Only accounts created through frontend user management receive no
  backend role (only `frontend.read`). Existing accounts are reported by
  `scripts/report-frontend-reader-accounts.php` for migration.
- Anonymous (`none`) authentication is refused in production unless
  `GENERIC_ALLOW_ANONYMOUS_PRODUCTION=true`; `publicRoles` now defaults to none.
- Out-of-scope callers receive the same `403 AUTHORIZATION_DENIED` whether a
  database they name is unregistered, disabled, unavailable, or not granted:
  the scope check now runs before the database availability gate.
- New read-only operator scripts: `validate-access-scopes.php` (reports
  whether a valid file is complete and lists grants that need review; exit 4
  for unregistered databases or missing SQL Resources),
  `generate-access-scope-draft.php` (minimal column lists, with hidden filter
  columns listed for review), and `check-database-privileges.php`.

### Changed

- **SQL Resource IDs (breaking for direct API clients).** The TOP-10
  month-wise category report released as `widgets/TOP-10-month-Wise-Category-wise`
  (v2.0.0, v3.0.0) is now `widgets/sales-top-10-month-wise-category-wise`, one
  resource shared by the Bill and Sales dashboards; the unreleased intermediate
  copy `widgets/bill-top-10-month-wise-category-wise` was removed. Requests for
  either removed ID return `400 INVALID_SQL_RESOURCE`. Its results changed
  too: it has no five-years-ago window (a caller's date range limits the rows
  inside the `SalesData` CTE, before ranking), and `Month` is the calendar
  month number (`MM`, combining the same month across years) instead of
  `MM/YYYY`.
- **Bill dashboard SQL Resources** (`widgets/bill-*`) no longer limit
  themselves to FY 2021-22: without a runtime date filter they read all dates,
  and `widgets/bill-purchases-month-wise` groups by calendar month across
  years.
- **Open compatibility decision.** API-key integrations may call SQL
  Resources directly, and whether any uses the released TOP-10 ID or relies on
  the previous Bill results has not been confirmed by the API owner. Until it
  is: deploy this backend together with the matching frontend; before
  deploying, have each enabled API-key owner confirm the resources it calls;
  if one needs the released ID, restore it for one release as its own file
  with the v3.0.0 SQL (not an alias), list it in the duplicate allowance of
  `SqlResourceDiscoveryTest.php` only if its content matches another resource,
  and test it separately.
- **Admin Console: Configuration → Database removed.** The **Databases** page
  (Databases and Servers tabs) is the only database UI. A V2 `database.json` is
  migrated by editing and saving the `default` server profile there. The
  `admin.database.*` actions remain for compatibility.
- **System Health** no longer shows a Backup card; backup state is on the
  Backup & Recovery page. The `admin.health` response still carries the
  `backup` check.
- **Databases page:** the default database's row has no Set Default, Disable,
  or Delete actions (the API still rejects them with `409`), and the server and
  database tables size their columns to their content instead of reusing the
  Users table's fixed widths, so rows are compact.

### Fixed

- The database availability gate applies only to data actions. A request with
  an invalid action is rejected by validation (`400 INVALID_REQUEST`)
  regardless of database state instead of `503 DATABASE_UNAVAILABLE`; data
  actions are gated as before. Tests no longer depend on the deployment's own
  database registry, which made `AuthorizationApiCoverageTest` fail in CI.

## [3.0.0] - 2026-10-09

Multi-database support for Microsoft SQL Server: several server profiles and
databases behind logical database ids, with same-server cross-database
SELECT. SQL Server remains the only database engine. Upgrade instructions and
compatibility notes are in [Upgrading to V3](docs/Upgrading-to-V3.md).

### Added

- **Database registry** (`database/config/databases.json`): server profiles
  (connection, credentials, TLS options, login timeout) and database contexts
  (logical id, name, server profile, catalog, enabled flag) with a default
  database. Each entry is an AES-256-GCM envelope bound to its own id;
  structural validation needs no key.
- **Database selection** by logical id: a top-level `database` on `select`,
  `union`, `unionAll`, `sql`, `metadata.*` (except `metadata.databases`),
  `procedure`, `function`, `tableFunction`, `insert`, `update`, `delete`, and
  `upsert`, and `source.database` on SELECT sources. Requests are planned
  before any connection opens (references → registry contexts → access
  policy → one server profile).
- **Same-server cross-database SELECT**: joins, subqueries, CTEs, set
  operations, aggregation, windows, and pagination across databases of one
  server profile, as one statement on one connection; physical names are
  rendered from the registry as delimited identifiers, and each source's
  columns are validated against its own database.
- **Schema-aware SELECT sources** (`source.schema`).
- **Database-aware metadata**, `metadata.columns` with `source.schema`, and
  `metadata.databases`, which lists logical databases (`id`, `name`,
  `default`, `enabled`, `available`, `crossDatabaseGroup`) from the registry
  without connecting.
- **SQL Resource `{{database:id}}` placeholders** for registered databases.
- **Database-aware routines and writes**, each with exactly one target
  database.
- **Admin Console**: a Databases page and `admin.servers.*`/`admin.databases.*`
  actions to manage, enable, test, and connect server profiles and databases;
  a per-server and per-database health tree on System Health.
- Login timeout per server profile (`options.loginTimeoutSeconds`, ODBC
  `ConnectTimeout`).
- New error codes: `DATABASE_NOT_FOUND`, `DATABASE_DISABLED`,
  `SERVER_PROFILE_DISABLED`, `SERVER_PROFILE_NOT_FOUND`,
  `CROSS_SERVER_QUERY_NOT_SUPPORTED`, `CROSS_DATABASE_QUERY_NOT_SUPPORTED`,
  `COLLATION_CONFLICT`, `DATABASE_CONNECTION_TIMEOUT`,
  `DATABASE_CONNECTION_FAILED`.
- `scripts/migrate-database-registry.php` and `GENERIC_SECURITY_STORAGE_DIR`.

### Changed

- **Breaking:** SQL Resources may not contain literal database-qualified or
  four-part names, three-part column references, or `OPENQUERY`,
  `OPENROWSET`, and `OPENDATASOURCE`; another database is addressed only with
  a placeholder. A resource with placeholders runs on its first placeholder's
  database and rejects a request `database`.
- **Breaking:** SELECT `source.table` and source aliases are single
  identifiers (use `source.schema`); dotted names, which the catalog check
  never accepted, are now validation errors.
- The V2 `database.json` is read as the `default` server profile and
  database until migrated by the first registry write or the migration
  script; migration verifies the result before removing the V2 file.
- Database availability state is per database (version 2); application
  backups use format 4 with the registry, and format 2 and 3 recovery points
  are converted on restore.
- Connection failures map to specific codes instead of `500 INTERNAL_ERROR`.
- Health: per-database checks and caches; readiness still depends only on the
  default database and opens no connection.
- The test runner isolates logs and security state, and no suite writes the
  deployment's configuration, runtime, logs, or storage.

### Security

- The registry is the database allowlist: unregistered databases (including
  `master`, `msdb`, `tempdb`, and `model`), physical names, and qualified names
  never resolve; queries across server profiles are rejected before
  connecting, and cross-database queries are refused on Azure SQL Database and
  Synapse dedicated pools.
- An isolation sweep covers identifier injection in every request position,
  registry tampering, key handling, and secret leakage; Admin rejects catalog
  names that cannot be rendered as identifiers.
- Real SQL Server execution was not verified by the regression suite, which
  uses recorded ODBC calls and in-memory catalogs.

## [2.1.0]

Security verification, operational hardening, and the generic authorization
model. The API no longer needs application-specific table, write-target, or
routine registrations: authorization is decided by role permissions, and the
database login's permissions are the data access boundary. Security findings
and evidence are in
[docs/security/Security-Verification.md](docs/security/Security-Verification.md).

### Changed

- The project is named **Generic SQL REST API Framework** (previously Generic
  SQL API Framework). Endpoints, configuration keys, environment variables,
  and deployment identifiers are unchanged.
- Authorization is permission-only and identical for sessions, managed API keys,
  the legacy key, and anonymous mode: `data.read` for queries, `data.write` for
  writes, `sql.execute` for SQL Resources, `metadata.read` for metadata,
  `routine.execute` for functions, and `routine.execute` plus `data.write` for
  stored procedures. Authorization schema version 4 removes the per-role
  `sqlResources` and `writeResources` scopes; versions 2 and 3 migrate in place.
- **Breaking:** write requests name their target with `table` (`Table` or
  `Schema.Table`) instead of a registered `resource` ID. Any existing column can
  be filtered on, any non-generated column can be written, the identity column
  is detected from metadata, and UPSERT `keys` are required and must match the
  table's primary key or an unfiltered unique index.
- **Breaking:** routines are called by name. Names must match an existing user
  routine of the requested kind in the configured database; functions take
  exactly their declared parameters and procedures at most that many. Stored
  procedures require `data.write` and, for session callers, CSRF; functions
  require neither.
- JSON Query Mode reads any table or view the database catalog confirms;
  metadata listings are not filtered.
- A SQL Resource runtime mapping whose qualifier is not a top-level source
  returns `400 INVALID_SQL_RUNTIME_FILTER`. `RESOURCE_ACCESS_DENIED` and
  `INVALID_WRITE_RESOURCE` are no longer returned; unknown write tables return
  `400 INVALID_WRITE_TABLE`.
- `config/authorization.example.json` reflects schema version 4.
- Application version metadata reports `2.1.0`.
- Documentation reorganized into current reference, roadmap, changelog, and a
  consolidated security model and verification record.

### Removed

- `config/query-sources.php`, `config/write-resources.php` (including the
  `crud-test` sample), and `config/routine-resources.php`, with
  `QuerySourcePolicy`, `QuerySourceRegistry`, `WriteResourceRegistry`, and
  `RoutineResourceRegistry`. These registries were introduced during the v2.1.2
  static analysis and replaced by the generic authorization model before
  release.

### Fixed

- Readiness at the internal IIS route `/api/health/ready` always returned `200`;
  health probes are now recognized at any mount path.

### Security

- Dependency review (v2.1.1): the application has no Composer, npm, or vendored
  dependencies; its dependency surface is the host PHP runtime, extensions,
  operating-system libraries, and ODBC stack. The bundled Windows PHP runtime's
  provenance and SHA-256 checksums are recorded.
- Static security analysis (v2.1.2), findings SSA-01 – SSA-12:
  - first-run setup and backend user, API-key, and role management moved to the
    loopback Admin API only;
  - production ODBC driver selection limited to Driver 18/17 with no fallback
    after TLS failures, and transport warnings in health and validation;
  - `GENERIC_MAX_RESULT_ROWS` limit for unpaginated reads
    (`413 RESULT_TOO_LARGE`);
  - an administrator credential exposed in Git history was rotated.
  SSA-13 – SSA-20 are deferred. The registry controls added for SSA-01, SSA-03,
  and SSA-07 were superseded by the generic authorization model.
- Authorization and API security testing (v2.1.3), findings AAPI-01 – AAPI-09:
  - Application Administrators can no longer take over or alter backend-only
    accounts, and System Administrator accounts can no longer be managed through
    the public API;
  - write routines require CSRF (every stored procedure, under the final
    model); frontend user management returns minimized profiles and uniform
    not-found responses; the System Administrator role is rejected for anonymous
    and legacy-key principals; non-string public actions are rejected;
  - a 64-test authorization and API coverage suite.
- DAST and penetration-test preparation (v2.1.4): the SQL Parser no longer
  discloses the PHP version, and its development router serves only its intended
  assets. Authenticated dynamic testing is deferred to the external penetration
  test.
- Architecture and operational hardening (v2.1.5): deployment guidance moves
  runtime configuration out of the code tree through `GENERIC_RUNTIME_CONFIG_DIR`
  and keeps `Backend/config` and the bundled development runtimes read-only;
  `validate-production.php` reports the runtime configuration location.
- Final repository-level verification (v2.1.6): tests, mutation checks, lint,
  and documentation checks pass, and every finding has a recorded status.
- Generic authorization model: table and routine names must be `Name` or
  `Schema.Name`; `sys` and `INFORMATION_SCHEMA` objects, cross-database names,
  and `sp_`/`xp_` system procedures are rejected, and every table, column, and
  routine must exist in the configured database's catalog. Identifier
  validation, prepared parameters, request schema validation, CSRF, result-size
  limits, and Admin API separation are unchanged.

## [2.0.0] - 2026-10-05

### Added

- A unified loopback Admin Console for first-run setup, encrypted database
  configuration, users, fixed roles, managed API keys, CORS, runtime settings,
  health, and service/database availability controls (real processes in
  development, application availability in production).
- Session and managed API-key authentication with four normal API modes:
  `none`, `session`, `api_key`, and `session+api_key`.
- Separate backend and frontend authorization domains, deny-by-default resource
  scopes, last-administrator protection, and authorization-change session
  invalidation.
- One-time-reveal `gsk_` API keys with hash-only storage, owner/role assignment,
  enable/disable/revoke lifecycle, fingerprints, and last-used metadata.
- Single-object INSERT, UPDATE, DELETE, and SQL Server UPSERT actions backed by a
  deny-by-default write-resource registry and live metadata validation.
- Recursive SQL Resource discovery with safe path-derived IDs, collision and
  traversal protection, execution metadata, runtime filters, deterministic
  sorting, and pagination.
- Recursive query expressions, expanded function coverage, CTEs, windows,
  set operations, and SQL Server-safe structural numeric literals while runtime
  values remain prepared parameters.
- A standalone, non-executing SQL-to-Universal-JSON parser with lexical parsing,
  capability analysis, validation, browser UI, and structured errors.
- Configurable query timeout, pagination limits, request body limit, API/login
  rate limits, and idle/absolute session expiration.
- Request-correlated JSON Lines audit/security logging with redaction and
  concurrency-safe append behavior.
- Application configuration backup creation, checksum/encryption verification,
  and safe external restore staging.
- Public liveness/readiness plus authenticated detailed health checks for
  application, configuration, database, processes, logging, encryption, and
  backups.
- IIS/FastCGI and Nginx/PHP-FPM deployment templates, TLS/security-header
  examples, production PHP settings, validation tooling, Windows/Linux
  operator checklists, and a complete Windows Server IIS deployment guide.
- Database-independent regression coverage for API contracts, query/write SQL,
  authentication, authorization, sessions, API keys, security boundaries,
  concurrency, backup/recovery, monitoring, errors, and production templates.

### Changed

- Runtime configuration is bootstrapped atomically from tracked secret-free
  examples; supported older schemas migrate without discarding identities or
  authorization assignments.
- Database configuration can be stored as a complete AES-256-GCM authenticated
  envelope whose key is supplied outside the repository.
- Local launchers start the managed API and SQL Parser, connect database
  availability, verify all three, and keep the loopback Admin Console in the
  foreground; each component is then controlled independently from System
  Health.
- Query construction is split into focused builders while `QueryRepository`
  remains the execution facade.
- Production process ownership belongs to IIS/FastCGI or Nginx/PHP-FPM; local
  process managers remain development-only. Each IIS boundary has its own
  FastCGI registration that sets production mode explicitly; production Admin
  Enable/Disable/Reload changes only application availability; database
  Connect verifies one closed test connection before enabling access; System
  Health reports web-server ownership, explicit database/encryption state, and
  non-fatal backup health; readiness never opens a SQL connection; and the
  production Admin Console has no Server configuration tab.
- Production errors use a stable client-safe envelope and correlation ID while
  internal diagnostics remain in redacted logs.

### Fixed

- SQL Resource CTE filtering/count/pagination, logical output/source/HAVING
  mappings, integer-backed date conversion, and authored OFFSET/FETCH conflicts.
- SQL Server ordering in window and legacy pagination contexts.
- SQL Server/ODBC type inference for validated structural numeric expression
  arguments without making runtime values raw SQL.
- UPSERT key propagation from server-owned write-resource configuration.
- Cross-platform lifecycle state, stale/duplicate process recovery, restart
  cleanup, dynamic ports, and configuration bootstrap behavior.
- Admin Console view initialization and explicit selection of all supported
  authentication modes.
- Strict exact-origin CORS validation and throttling before authentication to
  prevent unauthenticated protected-request rate-limit bypass.

### Security

- Hardened session cookies, strict cookie-only transport, login regeneration,
  logout destruction, CSRF rotation, expiration, and concurrent-session writes.
- Hardened database/configuration file locking, temporary-file permissions,
  migration, key handling, log redaction, and secret rotation guidance.
- Added fixed-host HTTPS redirects, HSTS and boundary-specific CSP/security
  headers, sensitive-file web denials, and explicit trusted-proxy boundaries.
- Added attack-oriented tests for authentication, authorization, API keys,
  CSRF/CORS, SQL/CRUD injection, traversal, secrets, backups, health, logging,
  and error disclosure.

## [1.0.0] - 2026-07-27

### Added

- JSON API routing, controller/service layers, public request validation and
  normalization, CORS, and standard response envelopes.
- SQL Server SELECT generation with fields/aliases, DISTINCT/TOP, CASE,
  arithmetic, allow-listed functions, prepared filters, joins, grouping/HAVING,
  sorting, and compatibility-aware pagination.
- Window functions, filter subqueries, CTEs, UNION/UNION ALL, stored procedures,
  scalar functions, table-valued functions, and metadata actions.
- SQL execution timing, returned-row counts, and request/error logging.

### Fixed

- BETWEEN date strings can be converted to `YYYYMMDD` integers for
  integer-family date columns discovered through metadata.
