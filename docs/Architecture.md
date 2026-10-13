# Architecture

This document explains how the backend is structured and how a request flows
through it. Security boundaries are detailed in
[Security model](security/Security-Model.md); the public contract is in
[HTTP API](API.md).

## Overview

```text
Client (frontend, API client, operator browser)
  ↓
Web server / PHP runtime
  production: IIS + PHP FastCGI, or Nginx + PHP-FPM
  development: PHP built-in server started by the launchers
  ↓
HTTP boundaries
  api/index.php, api/health.php    public API and health probes
  admin/index.php, admin/api.php   loopback Admin Console and Admin API
  sqlparser/index.php              SQL → JSON converter (no database)
  ↓
Middleware (app/Middleware)
  availability, authentication, rate limiting, CSRF, logging,
  authorization, database availability
  ↓
Request validation and normalization (app/Requests)
  ↓
Database planning (app/Database)
  references → registry contexts → access policy → one server profile
  ↓
Controllers → services → repositories and builders (app/)
  ↓
QueryEngine → DatabaseConnectionManager → Database → SqlServerDriver → PHP ODBC
  (one request-scoped connection, to the plan's primary database)
  ↓
Microsoft SQL Server

Configuration and state (read by every layer above):
  config/*.php                      shipped PHP configuration (read-only in production)
  GENERIC_RUNTIME_CONFIG_DIR        users, roles, API keys, Admin settings, availability
  database/config/databases.json    database registry: server profiles and databases,
                                    each entry AES-256-GCM encrypted
  database/config/database.json     V2 configuration, read only until migrated
  logs/, runtime/, storage/, backups/
```

Microsoft SQL Server through ODBC is the only database engine in V3.
`DriverFactory` creates only `SqlServerDriver`; the other files in
`database/drivers/` are empty stubs.

## Multi-database architecture

- **Registry.** `DatabaseRegistry` stores server profiles (connection,
  credentials, TLS, login timeout) and database contexts (logical id, display
  name, server profile, physical catalog, enabled flag) plus the default
  database. Credentials belong to server profiles; every entry is sealed
  separately with AES-256-GCM bound to its id. The registry is the allowlist of
  databases the API can reach.
- **Resolution.** Clients name databases only by logical id.
  `DatabaseContextResolver` turns an id into a `DatabaseContext` (configured,
  enabled, profile enabled) and checks its availability gate. Physical names
  are trusted registry values and reach SQL only through `QualifiedObject` and
  `MssqlIdentifier` delimited identifiers.
- **Planning.** `DatabaseReferenceCollector` finds the request's database
  references (top-level `database`, source `database`, SQL Resource
  `{{database:id}}` placeholders); `DatabaseAccessPolicy` (V3: allow enabled
  databases) and `DatabaseQueryPlanner` produce a `DatabaseQueryPlan` with one
  primary database and one server profile. Different profiles fail with
  `CROSS_SERVER_QUERY_NOT_SUPPORTED`.
- **Execution.** `DatabaseConnectionManager` opens one request-scoped
  connection to the primary database. Same-profile cross-database SELECTs and
  SQL Resources run as one statement on it; engines without same-instance
  cross-database names (Azure SQL Database, Synapse dedicated pools) refuse
  them first. Writes and routines have exactly one target database. There are
  no persistent connections, pools, linked servers, or distributed
  transactions.
- **Administration.** The Admin Console manages profiles and databases
  through the registry; API and SQL Parser availability are application flags,
  never process control in production, where IIS owns the worker lifecycle.

## Components

| Component | Responsibility |
|---|---|
| `api/index.php` | Public API front controller: CORS and preflight, bounded JSON body, middleware, setup/session/frontend-user actions, validation, normalization, controller dispatch |
| `api/health.php` | Public liveness and readiness probes |
| `admin/` | Admin Console UI and loopback Admin API: setup, configuration, users, API keys, health, availability, backups. See [Admin Console](Admin-Console.md) |
| `sqlparser/` | Independent, non-executing SQL-to-JSON converter. See [SQL Parser](SQL-Parser-Generator.md) |
| `app/Middleware/` | Authentication, rate limiting, frontend-user and Admin authorization, CSRF, logging, authorization, application and database availability |
| `app/Requests/` | Public contract validators and the normalizer that maps public JSON to the private builder model |
| `app/Controllers/`, `app/Services/` | Thin orchestration; no SQL construction |
| `app/Repositories/` | Query, SQL Resource, write, metadata, and configuration repositories |
| `app/Repositories/Query/` | SELECT builders (WHERE, JOIN, GROUP BY, HAVING, ORDER BY, pagination, windows, expressions, routines) |
| `app/Repositories/Write/` | INSERT, UPDATE, DELETE, UPSERT, and write-filter builders |
| `app/Resources/` | SQL Resource discovery and statement analysis, routine resolution, database object-name parsing |
| `app/Security/`, `app/Authorization/` | Password hashing, API keys, CSRF, rate limiters, database encryption, role model, principals |
| `app/Configuration/`, `app/Runtime/` | Runtime configuration bootstrap; development process managers and production availability state |
| `app/Health/`, `app/Backup/`, `app/Deployment/` | Health monitoring, application backup and restore, production validation |
| `core/` | `QueryEngine` (prepared ODBC execution), `Database`, `Response`, `ExceptionHandler`, loggers, JSON file store, request ID |
| `database/` | Driver factory and `SqlServerDriver` |
| `queries/` | Discoverable SQL Resources and internal metadata SQL (`queries/system/`) |
| `scripts/` | Runtime control, backup, bootstrap, database check, and production validation CLIs |

## Request flow

The public API applies its stages in this order. The order is security-relevant.

```text
method, content type, CORS, bounded JSON body
  → LoggingMiddleware (request ID)
  → reject admin.*, setup.createAdmin, auth.users.*, auth.apiKeys.*, auth.roles.list (404)
  → ApplicationRuntimeMiddleware (production availability gate)
  → AuthenticationMiddleware → ApiRateLimitMiddleware
  → FrontendUserAuthorizationMiddleware → CsrfProtectionMiddleware
  → setup.*, auth.*, auth.frontendUsers.* are dispatched here
  → AuthorizationMiddleware → DatabaseAvailabilityMiddleware
  → QueryRequestValidator → QueryRequestNormalizer
  → QueryController | SQLController | WriteController | MetadataController
  → service → repository → builders → QueryEngine
  → Response (success envelope) or ExceptionHandler (error envelope)
```

Three data paths share the same engine, connection handling, and response
envelope:

- **JSON Query Mode.** `QueryRepository` is an execution facade over
  `SelectBuilder`, `RoutineBuilder`, and `SetOperationBuilder`. Builders validate
  identifiers against live metadata, render controlled expressions through
  `SqlExpressionBuilder` and `QueryFunctionRegistry`, and emit positional
  parameters. CTE projections become request-local virtual metadata
  (`ScopedMetadataRepository`), and nested SELECT construction preserves the
  outer alias scope.
- **SQL Resource Mode.** `SqlResourceDiscovery` maps safe IDs to files under
  `queries/`; `SqlResourceRegistry` turns validated `execution` metadata into
  filter and sort definitions; `SqlRepository` loads the trusted SQL, splits a
  top-level `WITH` prefix, applies runtime filters at the output, source, or
  HAVING stage, and reuses pagination. It does not use the JSON Query expression
  allowlists.
- **Write API.** `DatabaseObjectName` parses the client-named table (rejecting
  system schemas and cross-database names), the repository loads its live column
  metadata and identity column, and `WritePayloadValidator` checks columns and
  values against that metadata; the write builders produce bracket-quoted identifiers and
  parameters, capture `OUTPUT` into a table variable for affected-row counts and
  identities, and use one `MERGE ... WITH (HOLDLOCK)` for UPSERT. See
  [Write API](Write-API.md).

Routines follow the same pattern: `RoutineResolver` parses the name, rejects
system procedures, and confirms the routine's kind and declared parameter count
in `INFORMATION_SCHEMA.ROUTINES` before `RoutineBuilder` emits the call.

## Authorization

`AuthorizationMiddleware` makes one decision per request from the authenticated
`Principal`'s role permissions. Sessions, managed API keys, the legacy key, and
anonymous mode all produce a `Principal`, so the authentication method never
changes the outcome:

| Action | Permission |
|---|---|
| `select`, `union`, `unionAll` | `data.read` or `frontend.read` |
| `sql` | `sql.execute` or `frontend.read` |
| `metadata.*` | `metadata.read` or `frontend.read` |
| `insert`, `update`, `delete`, `upsert` | `data.write` |
| `function`, `tableFunction` | `routine.execute` |
| `procedure` | `routine.execute` and `data.write` |

Role permissions select the kinds of action; [access scopes](Access-Scopes.md)
then limit the databases, tables, columns, SQL Resources, filter columns, and
metadata operations each request may use, before any query runs.
After authorization, request validation, catalog checks, and prepared
parameters keep SQL safe, and the database login's permissions bound what any
request can reach. The Admin API is a separate management plane with its own
`admin.manage` check.

`OrderByBuilder` distinguishes top-level positional ordering from window
`ORDER BY`: SQL Server rejects integer positions inside `OVER (...)`, so window
and legacy-pagination paths resolve positions to real projections. Public
clients cannot send numeric sort fields.

## Database and metadata

Each request constructs its own controllers, repositories, `QueryEngine`, and
ODBC connection; there is no persistent connection, pool, global queue, or
shared cancellation state. Metadata and data queries in one request share that
request's connection. Statements are freed in `finally` on every path.

`MetadataRepository` reads `INFORMATION_SCHEMA` for query validation and
integer-backed date handling; writes also read `sys.columns`, `sys.tables`,
`sys.schemas`, and `sys.types` for length, precision, nullability, identity,
computed, generated, and default flags.

A SELECT or set-operation request is planned before any connection opens:
its database references are collected, resolved through the registry,
checked by the access policy and availability gate, and required to share
one server profile (`DatabaseQueryPlanner`). `QueryEngine` connects to the
plan's primary database. When the plan names several databases, the builders
render each physical source as a `QualifiedObject`
(`[Database].[schema].[object] AS [alias]`), metadata for other databases is
read from their own `INFORMATION_SCHEMA` views on the same connection, and the
query runs as one statement; nothing is merged in PHP and no second
connection or linked server is used.

Metadata actions are planned the same way: their optional `database` selects
the primary database, so their catalog queries read the connected database.
Routines and writes are planned the same way and run in their one selected
database; a write's target is a `QualifiedObject` of that database. A
SQL Resource's `{{database:id}}` placeholders are collected from the trusted
file during planning; at execution they are rendered, by token position, as
the delimited physical names of the plan's databases.
Structured lookups (`objectExists`, `objectColumns`, …) take a
`QualifiedObject` and read another database's `[Database].INFORMATION_SCHEMA`
on the same connection. `metadata.databases` is answered by `DatabaseDirectory`
from registry metadata and availability state only: it is neither planned nor
gated and opens no connection.

Pagination runs a count query, then checks the database compatibility level:
110 or newer uses `OFFSET/FETCH`; older levels use a `ROW_NUMBER()` wrapper. A
complete first-page SQL Resource whose authored `TOP` fits the page skips the
count.

Database configuration is resolved through the registry:

```text
database/config/databases.json
  → per-entry AES-256-GCM envelopes decrypted in memory with GENERIC_SQL_API_ENCRYPTION_KEY
  → DatabaseContext (server profile + physical catalog)
  → DatabaseConnectionManager → SqlServerDriver → odbc_connect
```

Until a V2 `database.json` is migrated, it is read as the `default` server
profile and `default` database; see [Upgrading to V3](Upgrading-to-V3.md).

See [Database configuration](Database-Configuration.md).

## Timeouts and cancellation

The statement timeout (default 45 seconds, `query.timeoutSeconds`, overridable by
`DB_QUERY_TIMEOUT_SECONDS`) is applied through ODBC `SQL_QUERY_TIMEOUT`. Drivers
that report the option as unsupported are logged once per engine and execution
continues. PHP's `max_execution_time` (60 seconds in the production INI) is the
portable request guard; the shutdown handler converts it to `504 QUERY_ERROR`.

A browser abort does not cancel SQL Server work: PHP exposes no safe
cancellation while `odbc_execute` is blocked. The request finishes or times out,
and the client must discard obsolete results.

Each request has a request ID. Logs record request phases (validation,
normalization, SQL generation, connection, preparation, execution, fetch,
response) with SQL literals redacted and only parameter counts and types. See
[Logging](Logging.md).

## Process model and state

In production, IIS/FastCGI or Nginx/PHP-FPM own listeners, workers, and
concurrency. Setting `GENERIC_APP_ENV=production` turns the Admin lifecycle
actions into application availability controls stored in
`application-runtime-state.json`; disabled API or SQL Parser requests return
`503 SERVICE_UNAVAILABLE`. In development, the launchers and Admin Console run
real local processes with PHP's built-in server. See [Admin Console](Admin-Console.md).

Runtime JSON files are written through `JsonFileStore` with I/O locks and atomic
replacement; read-modify-write operations take additional operation locks.
Sessions, rate-limit counters, and runtime state are local files, so all workers
must share one host and a reliable local filesystem. Same-session requests can
serialize on PHP's session lock.

In production, IIS or Nginx terminates TLS and sets HSTS and browser security
headers. PHP owns content type, cache control, CORS, cookies, CSRF, and response
bodies. Forwarded protocol and host headers are never trusted.
