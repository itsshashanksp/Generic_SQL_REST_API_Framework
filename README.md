# Framework

**SQL REST API Framework**

Framework is a configurable REST API framework for SQL Server that provides
authenticated database access, JSON queries, SQL Resources, data operations,
and administrative capabilities.

Framework is not application-specific. It sits between authorized clients and
SQL Server. Clients submit documented JSON requests; Framework authenticates
the caller, checks API permissions, validates the request, builds parameterized
SQL where applicable, and returns a standard JSON response. Database object
permissions remain a separate SQL Server boundary.

**Database support:** Microsoft SQL Server through PHP ODBC is the only
database engine. One installation can serve several SQL Server servers and
databases, which clients select by logical database id.

## Release status

| Version | Status |
|---|---|
| v1.0.0 | Completed (2026-07-27) |
| v2.0.0 | Completed (2026-10-05) |
| v2.1.0 | Completed |
| **v3.0.0** | **Completed (2026-10-09) — current version** |

Release history is in [CHANGELOG.md](CHANGELOG.md); planned work is in the
[Roadmap](docs/Roadmap.md). Upgrading a v2.1 installation:
[Upgrading to V3](docs/Upgrading-to-V3.md).

Frontend and backend versions are independent. Framework's current released
version is `3.0.0`; Dashboard currently uses its own `1.0.0-dev` display
version.

## Quick start

Prerequisites are PHP 8.2 or newer, the PHP ODBC extension, Microsoft ODBC
Driver for SQL Server, and access to a non-production SQL Server instance for
live validation.

```bash
git clone https://github.com/sigmasoftwaresolutions/Framework.git
cd Framework
php tests/run.php
./start-linux.sh
```

On Windows, use `start-windows.bat`. The built-in launchers are for local
development only. Complete first-time setup through the loopback Admin Console
before attempting authenticated data requests. See
[Local development](docs/Local-Development.md).

## What it provides

- **REST-style HTTP/JSON API.** One `POST` endpoint (`api/index.php`) accepts a
  JSON object whose `action` selects the operation; every response uses the
  same JSON envelope with a request ID.
- **Authentication:** sessions, managed API keys (`X-API-Key`), a legacy shared
  key, or anonymous mode, configured as `none`, `session`, `api_key`, or
  `session+api_key`.
- **Role-based API permissions:** fixed roles that grant operation permissions.
- **Multiple databases:** a registry of SQL Server server profiles and
  databases; requests name a database by logical id (`"database":
  "inventory"`) or use the default database. Databases of one server profile
  can be combined in one SELECT.
- **JSON queries** (`select`, `union`, `unionAll`): joins, grouping, HAVING,
  CTEs, window functions, CASE, arithmetic, and an allowlist of SQL Server
  functions, over any table or view of a registered database.
- **SQL Resources** (`sql`): server-authored `.sql` files under `queries/`,
  discovered automatically and executed by ID with validated runtime controls.
- **Generic CRUD** (`insert`, `update`, `delete`, `upsert`) on any user table.
- **Routines:** stored procedures (`procedure`), scalar functions (`function`),
  and table-valued functions (`tableFunction`), called by name.
- **Metadata:** tables, columns, views, procedures, and schema listings of a
  selected database, and `metadata.databases` listing the logical databases.
- **Filtering, sorting, and pagination** for queries and SQL Resources.
- **Request validation and SQL safety:** strict request schemas, identifier
  validation, catalog checks, and prepared parameters for every value.
- **Admin Console and Admin API:** a loopback-only management plane for setup,
  server profiles and databases, users, roles, API keys, runtime settings,
  System Health, availability controls, and application configuration backups.
- **Health endpoints:** public `/health/live` and `/health/ready`.
- **SQL Parser:** a separate, non-executing tool that converts supported SQL
  into request JSON.

## Architecture

```text
Client application
        |
        v
Framework API
        |
        +-- Authentication       (who is calling?)
        +-- API permissions      (which operation may this principal perform?)
        +-- Request validation   (is the request well-formed?)
        +-- Database planning    (which registered databases, on one server?)
        +-- SQL safety           (identifiers, catalog checks, parameters)
        |
        v
One request-scoped database connection (PHP ODBC)
        |
        v
Database login permissions   (which objects and operations the login may use)
        |
        v
SQL database
```

**The API controls** who is calling, whether the caller is authenticated, which
API operation the principal may perform, whether the request is valid, and
whether the generated SQL is safe.

**The database controls** which databases, schemas, tables, views, columns
(where the database supports column permissions), and routines each server
profile's login can use, and whether it may INSERT, UPDATE, or DELETE.

The API does not keep a second copy of database-object permissions. There are no
application-specific table, write-target, or routine registrations.

Production runs under IIS with PHP FastCGI or Nginx with PHP-FPM. See
[Architecture](docs/Architecture.md).

## Multiple databases

The database registry (`database/config/databases.json`, managed on the Admin
Console's **Databases** page) holds **server profiles** — a SQL Server
connection and its encrypted credentials — and **databases**, each a logical
id, a display name, a server profile, and the SQL Server database name. One
database is the default.

```json
{
  "action": "select",
  "database": "company",
  "source": { "table": "Customer", "alias": "c" },
  "fields": ["c.Name", "p.Name"],
  "joins": [{ "type": "INNER", "source": { "database": "inventory", "table": "Product", "alias": "p" },
              "on": { "left": "c.ProductId", "right": "p.Id" } }]
}
```

- Clients send only logical ids; physical database names, servers, and
  credentials stay server-side. Unregistered databases never resolve.
- Databases of one server profile can be combined in one SELECT or SQL
  Resource (`{{database:id}}` placeholders), executed as one statement on one
  connection. Queries across server profiles, linked servers, and distributed
  transactions are not supported; Azure SQL Database refuses cross-database
  queries.
- Metadata, routines, and writes act on one selected database.
- Authorization is unchanged: role permissions apply to every registered
  database, and each login's grants are the data boundary.

See [JSON request reference](docs/JSON-Request-Reference.md#database-selection)
and [Limitations](docs/Limitations.md#multi-database-v3).

## Authorization

Sessions, API keys, the legacy key, and anonymous mode all resolve to the same
`Principal`, and one authorization step decides every request from that
principal's role permissions. The authentication method never changes the
decision.

| Permission | Allows |
|---|---|
| `data.read` | `select`, `union`, `unionAll` |
| `data.write` | `insert`, `update`, `delete`, `upsert` |
| `sql.execute` | `sql` (SQL Resources) |
| `metadata.read` | `metadata.*` |
| `routine.execute` | `function`, `tableFunction` |
| `routine.execute` + `data.write` | `procedure` (stored procedures may modify data) |
| `frontend.read` | Reads and SQL Resources for frontend users, within the `frontend` [access scope](docs/Access-Scopes.md) |
| `frontend.users.manage` | Frontend user management |
| `admin.manage` | The Admin API |

Roles: Read Only, Data Operator, System Administrator, Application
Administrator (frontend), and the API-key-only API Administrator. See
[Authentication and authorization](docs/Authentication-and-Authorization.md).

The **Admin API** (`admin/api.php`) is a separate management plane: it is
reachable only from loopback, only with `GENERIC_ADMIN_ENABLED=1` on the Admin
worker, and only for a System Administrator session. Data permissions such as
`data.read` or `data.write` never grant access to it.

## The database as the data access boundary

Each server profile's database login is the data access boundary. For example:

```text
API principal (data.read, data.write)
        |
        v
Framework API
        |
        v
DB login: generic
        +-- SELECT Customers   allowed
        +-- SELECT Orders      allowed
        +-- UPDATE Orders      allowed
        +-- DELETE Orders      denied by the database
        +-- SELECT Salaries    denied by the database
        |
        v
SQL database
```

API permissions and database permissions have different jobs. The API decides
which kinds of operation a principal may request; the database decides which
objects that request can actually reach. Grant the login only what client
applications should be able to use.

**Shared database login.** When many API users share one database login, the
database sees one identity. If Alice, Bob, and John all go through
`generic_db_user`, each of them can reach exactly what `generic_db_user` can;
the database cannot tell them apart. When per-user row or object isolation is
required, add a mechanism for it, such as separate database logins for separate
client applications, database row-level security, or application-level
authorization. This is an architectural consideration of the design, not a
defect.

## Queries and SQL Resources

JSON queries work on any table or view that the database catalog confirms in
a registered database; system objects and unregistered databases never
resolve. Filtering, sorting, and
pagination are generic request features validated for safety; they are not
authorization rules.

```json
{
  "action": "select",
  "source": { "table": "Items", "alias": "I" },
  "fields": ["I.ItemCode", "I.Description"],
  "filters": [{ "field": "I.Active", "operator": "=", "value": 1 }],
  "sort": [{ "field": "I.ItemCode", "direction": "ASC" }],
  "pagination": { "page": 1, "pageSize": 50 }
}
```

SQL Resources are server-authored SQL files. `config/sql-resources.php` sets the
discovery root (`queries/`) and the excluded directories (by default `system`).
It is not an authorization registry; which resources a caller may run, and
with which filter columns, is set by [access scopes](docs/Access-Scopes.md). See
[SQL Resource Mode](docs/SQL-Resource-Mode.md).

## CRUD data operations

Write requests name a database table instead of an application-specific
resource:

```json
{
  "action": "insert",
  "table": "dbo.Customers",
  "data": { "CustomerCode": "C001", "Name": "John" }
}
```

- Any user table the login can use in the selected database (`database`,
  default: the default database) is a valid target; unknown tables, system
  schemas, and database-qualified names are rejected. A write has exactly one
  target database.
- Columns, types, nullability, and generated columns come from live database
  metadata; identity columns are detected and returned as `generatedId`.
- UPDATE and DELETE require a non-empty filter.
- UPSERT `keys` must match the table's primary key or an unfiltered unique
  index.
- Only INSERT, UPDATE, DELETE, and MERGE statements are generated; DDL is
  never generated.

See [Write API](docs/Write-API.md).

## Routines

Routines are called by name and must exist as a user routine of the requested
kind in the selected database (`database`, default: the default database). Functions and table-valued functions need
`routine.execute`; stored procedures need `routine.execute` and `data.write`
because they may modify data, and session callers must send a CSRF token. See
[Metadata and routines](docs/Metadata-and-Routines.md).

## Security

- Authentication with hardened sessions and one-time-reveal, hash-only API keys.
- Role-based API permissions through a single authorization path.
- CSRF protection for session-authenticated changes; exact-origin CORS.
- Strict request validation, SQL identifier validation, catalog checks, and
  prepared/parameterized SQL execution.
- Database-level permissions as the data access boundary.
- AES-256-GCM encrypted database registry (each entry bound to its id) with an
  externally supplied key; the registry is the allowlist of databases.
- Login and API rate limits, result-size limits, and production-safe errors.
- A separate, loopback-only management plane.
- Audit and operational logging, and health endpoints.

The security model and verification history are in
[docs/security/](docs/security/Security-Model.md).

## Configuration overview

Runtime configuration is created from the tracked `config/*.example.json`
templates and stored in ignored runtime files. Database credentials are
encrypted in the registry and require `GENERIC_SQL_API_ENCRYPTION_KEY` from the
worker environment. SQL Resources are discovered from the configured `queries/`
root; installing a resource is a trusted backend deployment action.

Do not publish runtime JSON, encryption keys, API keys, session data, logs, or
database connection information. See
[Database configuration](docs/Database-Configuration.md) and
[Production security and deployment](docs/Production-Security-and-Deployment.md).

## Deployment

| Model | Use | Guide |
|---|---|---|
| `start-windows.bat` / `start-linux.sh` with PHP's built-in server | Local development only | [Local development](docs/Local-Development.md) |
| Windows Server, IIS, PHP FastCGI | Production | [Windows Server IIS deployment](docs/Windows-IIS-Deployment.md) |
| Linux, Nginx, PHP-FPM | Production | [Production security and deployment](docs/Production-Security-and-Deployment.md) |

Production requires PHP 8.2 or newer with ODBC, OpenSSL, session, JSON, and
OPcache; a Microsoft ODBC Driver for SQL Server; `GENERIC_APP_ENV=production`;
`GENERIC_RUNTIME_CONFIG_DIR` outside the code tree; and
`GENERIC_SQL_API_ENCRYPTION_KEY` supplied through the worker environment and
preserved across upgrades — a different key cannot read the existing
registry. Never expose the Admin Console or SQL Parser publicly. Application
backups cover configuration only; SQL Server backup is an operator
responsibility. A v2.1 `database.json` keeps working until it is migrated into
the registry; see [Upgrading to V3](docs/Upgrading-to-V3.md).

## Where to start

- **Client developers:** [HTTP API](docs/API.md),
  [Action reference](docs/Action-Reference.md), and
  [Frontend integration](docs/Frontend-Integration.md).
- **Operators:** [Local development](docs/Local-Development.md), the deployment
  guide for your platform, and [Admin Console](docs/Admin-Console.md).
- **Security reviewers:** [Security model](docs/security/Security-Model.md) and
  [Security verification](docs/security/Security-Verification.md).
- **Maintainers:** [AI development guide](docs/AI-Development-Guide.md),
  [Testing](docs/Testing.md), and [CONTRIBUTING.md](CONTRIBUTING.md).

The [documentation map](docs/README.md) lists every document.

## Example library

Tracked examples under `examples/` demonstrate JSON Query, SQL Resource,
metadata, write, and routine request shapes plus generic SQL Server resource
templates. They use illustrative `Demo*` objects and are never installed or
executed automatically. See [Example library](docs/Example-Library.md).

## Tests

```bash
php tests/run.php
php -n tests/run.php
```

The suite is database-independent and needs no SQL Server, ODBC, or credentials;
it does not prove behavior against a real SQL Server, which should be verified
on a dedicated non-production server. See [Testing](docs/Testing.md).

## Repository layout

| Path | Contents |
|---|---|
| `api/`, `admin/`, `sqlparser/` | HTTP entry points and the Admin and SQL Parser UIs |
| `app/` | Middleware, request validation, controllers, services, repositories, builders, security, runtime, health, backup |
| `core/`, `database/` | Query engine, responses, logging, and the SQL Server driver |
| `config/` | Application settings, SQL Resource discovery settings, and `*.example.json` templates |
| `queries/` | SQL Resources and internal metadata SQL (`queries/system/`) |
| `deployment/` | IIS, Nginx, and production PHP templates |
| `scripts/` | Runtime control, backup, database check, and production validation CLIs |
| `runtime/` | Bundled development PHP runtimes and local runtime state |
| `tests/` | Database-independent regression suites |
| `docs/` | Reference, operations, and security documentation |

## Limitations

Framework supports Microsoft SQL Server through PHP ODBC. It does not provide
MySQL, PostgreSQL, SQLite, distributed transactions, client-supplied SQL, bulk
writes, or a public transaction API. Cross-database reads require registered
databases on one server profile and are unavailable on Azure SQL Database.
See [Current limitations](docs/Limitations.md).

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting a change. API,
authorization, SQL generation, deployment, and example changes require focused
regression coverage and exact validation results.

## License

Framework is licensed under the terms in [LICENSE](LICENSE).

## Related repository

[Dashboard](https://github.com/sigmasoftwaresolutions/Dashboard) is the
configuration-driven reporting and analytics frontend. Its validation and UI
controls improve authoring and usability but never replace Framework
authorization or SQL Server permissions.
