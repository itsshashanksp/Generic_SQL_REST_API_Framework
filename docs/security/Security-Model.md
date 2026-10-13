# Security model

This document describes how security works in the current release: the trust
boundaries, the controls that enforce them, and the risks that are accepted by
design. It is the authoritative security reference.

- Findings, verification history, and deferred security work are recorded in
  [Security verification](Security-Verification.md).
- Scope and rules for the outstanding external penetration test are in
  [Penetration-test preparation](Penetration-Test-Preparation.md).
- Deployment procedures are in
  [Production security and deployment](../Production-Security-and-Deployment.md)
  and [Windows Server IIS deployment](../Windows-IIS-Deployment.md).

## Architecture and trust boundaries

```text
Browser / API client
    │  HTTPS (TLS terminated by IIS or Nginx; HSTS and browser headers set there)
    ▼
IIS (URL Rewrite, request filtering, ipSecurity) / Nginx (fixed locations)
    │  FastCGI to fixed entry points only
    ├── /api        api/index.php, api/health.php            public
    ├── /admin      admin/index.php, admin/api.php, assets   loopback only
    └── /sqlparser  sqlparser/index.php, assets              loopback / internal only
    ▼
Public API: body checks → logging → Admin/identity actions rejected (404)
            → runtime gate → authentication → rate limit → frontend-user gate → CSRF
            → [setup/session/frontend-user actions dispatched here]
            → authorization → database availability → validation → normalization
    ▼
Services: authentication, sessions, users, API keys, query, write, routines, Admin
    ▼
Storage: runtime JSON (GENERIC_RUNTIME_CONFIG_DIR), encrypted databases.json registry,
         PHP sessions, logs, backups, rate-limit counters
SQL Server: ODBC, one request-owned connection, prepared parameters
```

| Boundary | Enforced by | Notes |
|---|---|---|
| Transport | IIS or Nginx TLS; `HTTPS` is passed by the server and never taken from forwarded headers | `Secure` cookies follow production mode or direct HTTPS |
| Authentication | `AuthenticationMiddleware`: session cookie, managed API key, or both, per `authentication.mode` | API keys never authenticate identity management or the Admin API |
| Authorization | `AuthorizationMiddleware` and `AuthorizationService` (role permissions only, one path for every authentication method); `FrontendUserAuthorizationMiddleware` and `UserManagementService` for frontend user management | Client-supplied role, access, or identity fields are never read |
| Admin API | Web-server loopback restriction; `LocalAdminMiddleware` (loopback `REMOTE_ADDR` and `GENERIC_ADMIN_ENABLED=1`); `admin.manage`; CSRF | Uses `REMOTE_ADDR` only, so it must not sit behind a same-host proxy |
| SQL Parser | Loopback or internal binding; fixed routing to `index.php` and its assets | No authentication, database, configuration, file, or network access; never executes SQL |
| Database | Request-scoped ODBC connection; catalog-confirmed user objects only; prepared parameters; the database login's permissions are the data boundary | Credentials are decrypted only in request memory |
| Filesystem | Fixed FastCGI targets; no generic `*.php` routing; state directories outside web roots | See [Filesystem](#filesystem-and-least-privilege) |
| Secrets | Worker environment for keys; per-entry AES-256-GCM `databases.json` registry; hashed passwords and API keys | See [Secrets](#secrets-and-configuration) |
| Sessions | PHP file sessions, cookie-only, strict mode, host-only cookie, idle and absolute timeouts, `authVersion` revocation | See [Sessions](#sessions-csrf-and-cors) |
| Logging | Allowlisted audit fields and redaction; logs are never served | See [Logging](#logging-and-auditing) |

## Request surfaces

Both APIs accept only `POST` (`405 METHOD_NOT_ALLOWED`) with
`Content-Type: application/json` (`415 UNSUPPORTED_MEDIA_TYPE`) and well-formed
JSON (`400 INVALID_JSON`), and dispatch on the `action` property of a JSON
object.

**Public API (`api/index.php`).** Disallowed `Origin` values return
`403 CORS_ORIGIN_DENIED`; `OPTIONS` returns `204`. In production a disabled API
application returns `503 SERVICE_UNAVAILABLE`. Every `admin.*` action,
`setup.createAdmin`, `auth.users.*`, `auth.apiKeys.*`, and `auth.roles.list`
returns `404 NOT_FOUND` before authentication.

| Actions | Authentication | Authorization | CSRF (session callers) |
|---|---|---|---|
| `setup.status`, `auth.csrf`, `auth.session` | None | None | No |
| `auth.login`, `auth.logout` | None / credentials (login rate limit) | None | Yes |
| `auth.frontendUsers.list` | Session only | `frontend.users.manage` | No |
| Other `auth.frontendUsers.*` | Session only | `frontend.users.manage` plus persisted actor/target policy | Yes |
| `select`, `union`, `unionAll` | API mode | `data.read` or `frontend.read` | No |
| `sql` | API mode | `sql.execute` or `frontend.read` | No |
| `function`, `tableFunction` | API mode | `routine.execute` | No |
| `procedure` | API mode | `routine.execute` and `data.write` | Yes |
| `insert`, `update`, `delete`, `upsert` | API mode | `data.write` | Yes |
| `metadata.*` | API mode | `metadata.read` or `frontend.read` | No |

Every action above is then limited by the principal's
[access scopes](../Access-Scopes.md): databases, tables, columns, SQL
Resources, filter columns, and metadata operations, enforced before execution.

*API mode* is `authentication.mode`: `none` creates an anonymous principal with
`publicRoles` (empty by default; refused in production without
`GENERIC_ALLOW_ANONYMOUS_PRODUCTION=true`); `api_key` requires a valid `X-API-Key`; `session` requires a
session; `session+api_key` accepts either. Actions beginning with `auth.` or
`admin.` always require a session.

**Admin API (`admin/api.php`).** An explicit allowlist of 49 actions is checked
first. Five session actions (`setup.status`, `auth.csrf`, `auth.session`,
`auth.login`, `auth.logout`) are reachable without the gate. Every other action
requires the gate (`GENERIC_ADMIN_ENABLED=1` and loopback), a session, and
`admin.manage`; mutations also require CSRF. `setup.createAdmin` needs only the
gate and returns `409` once the installation is initialized. Middleware order:
body checks → logging → allowlist → `LocalAdminMiddleware` → authentication (session) → rate limit →
`AdminAuthorizationMiddleware` → CSRF → validator and controller. The Admin API
has no application-runtime or database-availability gate, so it stays usable
for recovery. The full action list is in [Admin Console](../Admin-Console.md).

**Health (`api/health.php`).** `GET`/`HEAD` only. `/health/live` returns
`status`, `service`, and `version`; `/health/ready` returns an overall status
and four categorical checks and never opens a SQL connection. Probes are
recognized by their final path segments, so `/api/health/ready` behaves like
`/health/ready`. Any other path to `health.php` returns development process
metadata (see SSA-14 in [Security verification](Security-Verification.md)).
Detailed health is the Admin action `admin.health`.

## Roles and permissions

Roles are fixed; their permissions are validated on load and cannot be edited
into unsafe combinations.

| Role | Domain | Assignable to | Permissions |
|---|---|---|---|
| `read-only` (Read Only) | backend | users, API keys, `publicRoles`, `legacyApiKeyRoles` | `data.read`, `metadata.read`, `sql.execute`, `routine.execute` |
| `data-operator` (Data Operator) | backend | users, API keys, `publicRoles`, `legacyApiKeyRoles` | Read Only plus `data.write` |
| `system-administrator` (System Administrator; "Super Admin" in the Admin Console) | backend | users only | Data Operator plus `admin.manage`, `frontend.users.manage` |
| `api-administrator` ("Admin" API key role) | backend | API keys only | Same data permissions as Data Operator; no `admin.manage` or `frontend.users.manage` |
| `application-administrator` (Application Administrator) | frontend | users (`frontendRole`) | `frontend.read`, `frontend.users.manage` |

Any identity with `frontendAccess = true` holds `frontend.read`. Roles carry
permissions only; there are no per-table, per-routine, or per-SQL-Resource
scopes, and the authentication method never changes the decision for the same
role. Validation enforces that only System Administrator
holds `admin.manage`, that Read Only and Application Administrator never hold
`data.write`, and that `publicRoles` and `legacyApiKeyRoles` never contain
System Administrator. An API key that was assigned System Administrator by an
older schema is migrated to `api-administrator`.

Principals are rebuilt from persisted storage on every request. Sessions store
only identity and `authVersion`; a change to a user's password, username,
enabled state, or authorization increments `authVersion` and invalidates
existing sessions. The last enabled System Administrator cannot be demoted,
disabled, or deleted.

**Frontend user management.** `auth.frontendUsers.*` manages only
frontend-managed identities: accounts with no backend role, or with a backend
role the frontend may assign (currently `read-only`) together with frontend
access. Accounts holding `system-administrator`, `data-operator`, or a
backend-only `read-only` role are refused for every actor, including a System
Administrator acting through the public API. Backend identities are managed only
through the Admin API.

## Data access controls

- **Query construction.** Public actions and properties are allowlisted and
  unknown properties are rejected. Identifiers are syntax-restricted and checked
  against live metadata; operators, functions, join shapes, sort directions, and
  expressions are allowlisted. Filter, HAVING, and routine values are prepared
  parameters. Expression depth is limited to 32.
- **Object names.** Table and routine names are `Name` or `Schema.Name` with
  identifier-only parts. Three-part and cross-database names, brackets, the
  `sys` and `INFORMATION_SCHEMA` schemas, and `sp_`/`xp_` system procedures are
  rejected. Every table, view, column, and routine must exist in the configured
  database's catalog before SQL is built, and identifiers are bracket-quoted.
- **Queries.** JSON Query Mode reads any catalog-confirmed table or view, in any
  position (source, join, subquery, set-operation branch, CTE body). Filters,
  sorting, and pagination are generic request controls, validated for safety
  only.
- **Routines.** Routines are called by name and must match an existing user
  routine of the requested kind. Functions take exactly their declared
  parameters, procedures at most that many; values are bound. Procedures
  require `data.write` because they can change data.
- **SQL Resources.** Clients send a path-derived ID, never SQL or a path. Files
  must resolve inside the configured root (`realpath` containment), excluded
  directories are not discoverable, collisions fail closed, and each file must
  be one read-only SELECT/CTE. Runtime filter and sort fields come from
  strictly validated execution metadata, and source mappings must reference a
  top-level source of the authored statement. The SQL inside an authored
  resource is trusted server code.
- **Writes.** Writes target any user table named in the request. Columns,
  types, and generated-column rules come from live metadata. Only INSERT,
  UPDATE, DELETE, and MERGE are generated; UPDATE and DELETE require a
  non-empty filter; UPSERT keys must match a primary key or unique index. The
  data API never generates DDL or administrative statements.
- **Result size.** Unpaginated data reads stop at `GENERIC_MAX_RESULT_ROWS`
  (default 10,000) with `413 RESULT_TOO_LARGE`; results are never silently
  truncated.
- **Data boundary.** Effective access is the API permission **and** the
  database login's permission. The API does not duplicate table permissions, so
  a principal with `data.read` reaches every object the login can read and
  `data.write` every table it can write. Grant the login only what clients
  should reach (ST-003).

## Sessions, CSRF, and CORS

- **Cookies.** Host-only, path `/`, browser-session lifetime, `HttpOnly`,
  `SameSite=Lax`, and `Secure` in production. Local HTTP development omits only
  `Secure`.
- **Session handling.** Cookie-only transport, strict mode, no URL session IDs,
  session-ID regeneration at login with deletion of the prior session, logout
  and expiry destroy server state, and idle and absolute timeouts are enforced
  from server-side timestamps. Garbage collection lifetime is aligned with the
  absolute timeout.
- **CSRF.** A 256-bit per-session token, compared in constant time and rotated
  at login, is required for session-authenticated mutations (`X-CSRF-Token`).
  API-key requests do not use CSRF and never create browser sessions.
- **CORS.** Exact origins only; wildcards, paths, userinfo, queries, and
  fragments are rejected. Browsers send `Origin` on same-origin `POST` too, so
  the production origin must be configured. CORS is not access control.
- **Login throttling** is keyed by source address plus normalized username and
  returns `429 LOGIN_RATE_LIMITED`; responses are identical for known and
  unknown accounts. **API rate limiting** applies per session, API key, or
  anonymous address and returns `429 RATE_LIMIT_EXCEEDED`. Unauthenticated
  requests consume the anonymous limit before `401`.

## Secrets and configuration

| Secret | Where it lives | Controls |
|---|---|---|
| `GENERIC_SQL_API_ENCRYPTION_KEY` | Worker environment: IIS FastCGI registrations for `api` and `admin`, or the PHP-FPM pool/service | Never in `web.config`, the repository, scripts, or command lines; not given to the SQL Parser on IIS; never returned by health or Admin responses |
| Database credentials | Server profiles in `database/config/databases.json`, each an authenticated AES-256-GCM envelope bound to its id | Decrypted in request memory only; Admin returns `passwordConfigured`, never the password, ciphertext, or connection string |
| User passwords | `auth.json`, `password_hash(PASSWORD_DEFAULT)`, rehashed on login | Never logged or returned |
| API keys | `api-keys.json`, hash of the secret plus a short fingerprint | Raw `gsk_` key shown once; logs carry only the ID and fingerprint |
| Session IDs and CSRF tokens | PHP session storage outside web roots | Never logged |
| Backup-signing key | `GENERIC_BACKUP_SIGNING_KEY`, `GENERIC_BACKUP_SIGNING_KEY_FILE`, or owner-restricted `runtime/secrets/backup-signing.key` | Never written to an archive or manifest |
| Legacy shared API key (optional) | `GENERIC_SQL_API_KEY` | Keys shorter than 32 characters are refused; roles come from `legacyApiKeyRoles` |

Only `*.example.json` configuration is tracked; `.gitignore` excludes
`database/config/databases.json`, `database/config/database.json`, `runtime/secrets/`, `storage/`, logs, backups,
and `.env`. The constant `AuthService::DUMMY_PASSWORD_HASH` is a fixed
placeholder that equalizes login timing; it is not a credential. On IIS,
environment variables reach FastCGI workers only when set on the FastCGI
registration.

A V2 `database.json` (plaintext or encrypted) remains readable only until it
is migrated into the registry. Production deployments must use the encrypted
registry and an externally managed key.

## Filesystem and least privilege

| Location | PHP worker access |
|---|---|
| Code: `api/`, `admin/`, `sqlparser/`, `app/`, `core/`, `queries/`, `database/drivers/`, `config/` (shipped PHP configuration) | Read only |
| `runtime/windows/`, `runtime/linux/` (development PHP runtimes) | Read only |
| Runtime configuration directory (`GENERIC_RUNTIME_CONFIG_DIR`, outside the code tree) | Read/write |
| `database/config/` | Read/write (encrypted `databases.json` registry) |
| `runtime/` (other contents), `storage/`, `logs/`, `backups/`, PHP session directory | Read/write |
| `runtime/secrets/` or an external secret store | Narrowly restricted read |
| `.git/`, temporary files | No access |

Keeping runtime state outside `Backend/config` means the worker identity cannot
modify the PHP configuration it executes. `php scripts/validate-production.php`
reports whether the runtime configuration directory is outside the code tree.

## PHP runtime and web server

`deployment/php-production-security.ini` is merged into the production
`php.ini`: displayed errors off, server-side logging on, `expose_php` off,
`zend.exception_ignore_args` on, uploads off, 10 MB POST limit matching the IIS
and Nginx body limits, bounded execution time and memory, cookie-only strict
sessions, and `opcache.validate_timestamps=0` (recycle workers after each
deployment). `disable_functions`, `allow_url_fopen`, and `open_basedir` are left
to host policy.

The IIS and Nginx templates execute only the intended entry points (no generic
`*.php` routing), deny configuration, logs, backups, hidden files, and sensitive
extensions, disable directory listing, restrict Admin to loopback, scope
`GENERIC_ADMIN_ENABLED` and the encryption key per boundary on IIS, and set
HSTS, CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, and
`Permissions-Policy` per boundary. The application does not trust
`X-Forwarded-*` or `Forwarded` headers.

## Database

- The login's grants are the effective data boundary. The baseline is
  `db_datareader`; add `db_datawriter` only when clients must change data and
  `EXECUTE` only when they must call routines. Prefer schema-, table-, view-, or
  procedure-level grants. `db_owner` is never required, and a highly privileged
  or shared login gives every `data.read`/`data.write` principal the same reach.
- Windows authentication through the application-pool identity is recommended
  on a domain; SQL authentication passwords are stored only in the server
  profiles of the encrypted `databases.json` registry.
- ODBC Driver 18 encrypts by default and `trustServerCertificate` defaults to
  off. Production driver auto-selection uses only ODBC Driver 18 or 17, and a TLS
  failure never falls back to another driver. Weakened transport settings are
  reported as health and validation warnings.
- Application backups never contain database data; SQL Server backup is an
  operator responsibility.

### Database isolation

- The registry (`database/config/databases.json`) is the allowlist of
  databases. Clients name a database only by its logical id; an id that is not
  registered fails with `DATABASE_NOT_FOUND`, even for databases the login can
  reach (`master`, `msdb`, `tempdb`, …). Physical names, servers, ports,
  credentials, and connection strings are never accepted from a request, and
  three- or four-part names are rejected in every identifier position.
- Physical database names come only from the decrypted registry and reach SQL
  only as delimited identifiers (`MssqlIdentifier`). Each registry entry is
  encrypted with AES-256-GCM bound to its own id, so swapped, renamed, edited,
  or plaintext entries fail closed; error responses never contain physical
  names or hosts.
- Databases of one server profile may be combined in one SELECT or SQL
  Resource statement on one connection; other combinations fail with
  `CROSS_SERVER_QUERY_NOT_SUPPORTED` before connecting. Engines without
  same-instance cross-database names (Azure SQL Database, Synapse dedicated
  pools) refuse such queries with `CROSS_DATABASE_QUERY_NOT_SUPPORTED` before
  any data statement. There are no linked servers; SQL Resources reject
  literal database-qualified names, four-part names, and `OPENQUERY`,
  `OPENROWSET`, and `OPENDATASOURCE`, and address other databases only through
  `{{database:id}}` placeholders resolved from the registry.
- Writes and routines have exactly one target database; metadata reads only
  the selected database. Database selection never bypasses permission
  authorization, which runs first.

## Logging and auditing

Security audit records (`logs/audit/YYYY-MM-DD.jsonl`) cover login success,
failure, and throttling; logout, session expiry, and invalidation; user and
authorization changes; authorization denials; API-key lifecycle and invalid use;
CSRF and rate-limit rejections; security and database configuration changes;
database connection tests; runtime lifecycle operations; and backup and restore.
Records use allowlisted fields and exclude passwords, hashes, raw API keys,
session IDs, CSRF tokens, encryption keys, connection strings, request bodies,
and SQL parameter values. Operational logs record logical database ids; an
unexpected failure's SQL Server diagnostic text, which may name a database or
object but never a credential or connection string, is kept there (redacted)
for operators and never returned to clients. Logging fails open. Rotation, retention, and central
collection are deployment responsibilities. See [Logging](../Logging.md).

## Backups

Application backups contain only the runtime JSON configuration and the
encrypted `databases.json` registry. Keys, sessions, availability and rate-limit state,
logs, and SQL Server data are excluded by a fixed list that is validated on
restore. SHA-256 integrity and an HMAC-SHA256 manifest signature are verified
before preview and restore. Restore requires the Admin gate, `admin.manage`,
CSRF, and explicit confirmation, and rolls back if activation or its health
check fails. See [Backup and recovery](../Backup-and-Recovery.md).

## Errors

Unexpected, database, credential, and timeout failures map to fixed codes.
Responses never contain paths, SQL, ODBC diagnostics, environment values,
credentials, or stack traces; the request ID correlates them with redacted
server logs. See [Errors and validation](../Errors-and-Validation.md).

## Accepted risks and deployment responsibilities

These are current, documented properties of the design. Their finding records
are in [Security verification](Security-Verification.md).

- **Single-host state.** Sessions, rate-limit counters, lock files, and runtime
  configuration are local files with advisory locks. Run one application host
  on a reliable local filesystem (ST-005, SAOH-08).
- **Shared worker identity.** The IIS boundaries share one application-pool
  identity, and the Nginx example uses one PHP-FPM pool. Give the SQL Parser its
  own pool with no state or database access, or do not deploy it on production
  hosts (SAOH-04).
- **Backup-signing key custody.** The default signing key lives on the same
  host and identity as the archives; supply it from a vault for stronger
  tamper evidence (SAOH-05).
- **Database grants.** `db_datareader` grants read on every table; narrow it
  where possible (SAOH-06).
- **Logs.** Rotation, retention, alerting, and central collection are external
  (SAOH-07).
- **No API-level resource isolation.** Authorization is role permission only;
  per-table, per-column, per-routine, and per-SQL-Resource restrictions are left
  to the database login's permissions (ST-003; SSA-01, SSA-03, SSA-07 superseded
  in v2.1.0).
- **Password change** does not require the current password (there is no
  self-service or recovery flow; changes are administrator resets); a managed
  API key keeps the role it was created with even if its owner is demoted,
  although a disabled or deleted owner stops it; and the public API passes a
  JSON list body to authentication and authorization, which reject it with
  `401` or `403` rather than `400` (AAPI-05 – AAPI-07).
- **Admin MFA** is not implemented.
- **Target-host verification** of ACLs, effective `php.ini`, session storage,
  TLS, and IIS or Nginx behavior is operator-owned, and an external penetration
  test is still outstanding.
