# AI development guide

This guide is for maintainers and coding agents changing this repository. It
describes repository-specific boundaries; the public contract remains defined by
[API.md](API.md) and [JSON Request Reference](JSON-Request-Reference.md).

## Read before changing code

1. Check `git status` and preserve unrelated work.
2. Read the entry point, validator, normalizer, controller, service, repository,
   and builder involved in the request path.
3. Read the relevant focused documentation and existing regression test.
4. Search for dynamic loading before declaring a configuration or SQL file
   unused.
5. Keep database-independent tests runnable with both normal PHP and `php -n`.

The supported provider is SQL Server over ODBC. Files for other drivers are
stubs, not evidence of multi-database support.

## Repository map

| Path | Change boundary |
| --- | --- |
| `api/index.php` | Public dispatch order and middleware boundary |
| `admin/api.php` | Loopback administrator dispatch; never make normal API auth authoritative here |
| `sqlparser/` | Non-executing SQL-to-JSON parser, isolated from database/runtime auth |
| `app/Requests/` | Public validation and normalization |
| `app/Middleware/` | Authentication, authorization, CSRF, throttling, logging, database/application availability |
| `app/Controllers/`, `app/Services/` | Thin request/application orchestration |
| `app/Repositories/Query/` | Structured SELECT and expression builders |
| `app/Repositories/Write/` | Registered write builders |
| `app/Resources/` | SQL/write resource discovery and lookup |
| `core/QueryEngine.php` | Prepared ODBC execution, timing, timeout, and cleanup |
| `database/drivers/SqlServerDriver.php` | Connection construction and SQL Server driver behavior |
| `config/*.example.json` | Secret-free reference templates for runtime configuration |
| `config/sql-resources.php` | SQL Resource discovery root and exclusions |
| `queries/system/` | Internal metadata SQL; excluded from public discovery but runtime-required |
| `queries/reports/`, `queries/widgets/` | Dynamically discovered reviewed SQL resources |
| `deployment/` | Production examples, not local process managers |
| `runtime/`, `logs/`, `storage/` | Generated local state; do not commit |

## Request flow

The application API order is security-significant:

```text
method/content type/CORS/body checks
  -> LoggingMiddleware
  -> 404 for admin.*, setup.createAdmin, auth.users.*, auth.apiKeys.*, auth.roles.list
  -> ApplicationRuntimeMiddleware
  -> AuthenticationMiddleware
  -> ApiRateLimitMiddleware
  -> FrontendUserAuthorizationMiddleware
  -> CsrfProtectionMiddleware
  -> (setup.*, auth.*, auth.frontendUsers.* dispatch here)
  -> AuthorizationMiddleware
  -> DatabaseAvailabilityMiddleware
  -> QueryRequestValidator
  -> QueryRequestNormalizer
  -> controller -> service -> repository -> builder -> QueryEngine
  -> Response or ExceptionHandler
```

Setup, session, and frontend-user actions branch before query authorization.
Administrator and backend identity actions are rejected by the public API and
dispatched only by `admin/api.php`, whose order is: body checks -> logging -> action allowlist ->
`LocalAdminMiddleware` -> authentication -> rate limit ->
`AdminAuthorizationMiddleware` -> CSRF -> validator and controller.
Moving middleware can change security semantics; update attack-oriented tests
when such a change is intentional.

## Contract and SQL boundaries

- Public names such as `source`, `fields`, `filters`, and `pagination` are not the
  repository's private SQL-builder model. Update validator and normalizer
  together and test both accepted and rejected shapes.
- Never concatenate request identifiers or values into SQL. Identifiers must pass
  the existing allowlists/metadata checks; runtime values remain prepared
  parameters.
- `SqlExpressionBuilder` and `QueryFunctionRegistry` are the one recursive
  expression system. Do not introduce a parallel expression syntax.
- Validated numeric structural arguments may be emitted as numeric SQL tokens
  only through the existing function/expression contract. Strings, identifiers,
  and arbitrary request values must never become raw SQL.
- SQL Resource files are server-owned SQL. A client supplies a resource ID, not a
  path or SQL text. Keep real-path containment, excluded-directory, collision,
  read-only statement, filter-placement, and pagination protections intact.
- Writes require a configured resource and permitted action, columns, filters,
  UPSERT keys, and optional identity. Never infer write permission from read
  metadata.

## Configuration and generated state

`RuntimeConfiguration` creates missing runtime JSON files from built-in
defaults in the directory named by `GENERIC_RUNTIME_CONFIG_DIR` (default
`config/`; production keeps it outside the code tree). The tracked
`config/*.example.json` files are secret-free reference templates. JSON writes
use `JsonFileStore` locking and atomic replacement. Preserve schema validation
and explicit migrations when changing a stored shape.

Do not casually edit or commit:

- `admin.json`, `auth.json`, `authorization.json`, `api-keys.json`,
  `installation.json`, `database-state.json`, or `application-runtime-state.json`;
- `database/config/databases.json` and `database/config/database.json`;
- `runtime/secrets/`, `runtime/api/`, `runtime/sqlparser/`, or `runtime/health/`;
- `logs/`, `storage/`, lock files, backups, temporary files, sessions, or keys.

The database configuration may be a plaintext compatibility object or a complete
AES-256-GCM envelope. `DatabaseConfigurationResolver` is the read boundary;
`GENERIC_SQL_API_ENCRYPTION_KEY` must remain external and must not enter responses,
logs, backups without explicit protected handling, fixtures, or commits.

## Authentication and authorization invariants

- Normal API modes are `none`, `session`, `api_key`, and `session+api_key`.
- Managed and legacy API keys are read from `X-API-Key`, never bearer auth.
- Admin/auth management endpoints require a System Administrator session even if
  normal API mode is `none` or accepts API keys.
- Browser mutations require the session-bound `X-CSRF-Token`.
- Managed `gsk_` secrets are revealed once and stored only as hashes.
- Request fields never grant roles, frontend access, or permissions. Resolve the
  current server-side principal.
- Authorization is role permission plus [access scopes](Access-Scopes.md),
  identical for every authentication method. Enforce new data paths through the
  shared scope checks (AccessScopeGate and the scoped metadata lookups); never
  rely on the frontend. The database login's permissions remain a second
  boundary.
- Application Administrator is a frontend role and grants no Backend Admin
  Console authority. Preserve last-enabled-System-Administrator protection.
- Authentication/authorization changes that affect a user must continue to
  invalidate stale sessions through `authVersion`.

## Adding an API action

1. Decide whether it belongs to setup/auth, administration, frontend user
   management, or the normal database API.
2. Add an exact validator contract and reject unknown fields.
3. Add normalization only when a public-to-private model is needed.
4. Add the controller/service/repository operation at the existing layer.
5. Add the action to the correct entry-point allowlist and authorization model.
6. Decide whether database availability and CSRF apply; do not bypass middleware
   by dispatching early without a documented reason.
7. Return the standard `Response` envelope and stable error codes.
8. Add positive, negative, authorization, disclosure, and regression coverage.
9. Update `Action-Reference.md`, the request/response and error docs, the
   capability matrix, and limitations as applicable. Admin actions belong in
   `Admin-Console.md`; session and user actions in
   `Authentication-and-Authorization.md`.

## Adding a SQL resource

1. Place reviewed read-only SQL below `queries/` outside excluded directories.
2. Use a stable path-derived ID; avoid basename collisions.
3. Keep all runtime values out of the SQL file.
4. Add optional `execution` metadata only for approved columns, default sorting,
   and logical filter mappings.
5. Test discovery, execution, filtering stage, and pagination/count behavior.
   Complex CTE/set-operation resources need explicit tests.

Internal files in `queries/system/` are called directly by metadata repositories
and must not be exposed or deleted as apparently undiscovered resources.

## Changing authentication or authorization

Trace both `api/index.php` and `admin/api.php`, then inspect
`AuthenticationMiddleware`, `AdminAuthorizationMiddleware`,
`AuthorizationMiddleware`, `SecurityConfiguration`, `AuthSessionService`, and
the relevant repositories/services. Verify all four normal API modes, session
and API-key principals (which must reach the same decision), CSRF,
disabled/revoked state, last-admin behavior, and that secrets/internal identity
fields stay absent from
responses and logs.

Do not redesign the fixed role model or expose editable permissions as a side
effect of an authentication change.

## Tests and validation

Tests are standalone PHP programs registered explicitly in `tests/run.php`.
Name them after behavior, not roadmap phases. Reuse fakes that avoid constructors
opening ODBC connections. Worker scripts support deterministic concurrency tests
and are not standalone suites.

Run at minimum (details in [Testing](Testing.md)):

```bash
php tests/run.php
php -n tests/run.php
find api admin app config core database scripts sqlparser tests -type f -name '*.php' -exec php -l {} \;
node --check admin/assets/admin.js
node --check sqlparser/assets/js/app.js
bash -n start-linux.sh
git diff --check
```

If deployment templates change, also run `php scripts/validate-production.php`
and the production hosting, HTTPS, validation, and security suites. Validate IIS
examples as XML when an XML parser is available. If frontend repository files
change, use that repository's lint, test, and build commands and preserve any
pre-existing user changes.

## Common regression risks

- confusing public and normalized field names;
- losing parameter order between count and data queries;
- applying SQL Resource filters at the wrong WHERE/HAVING/outer-query stage;
- using a selected alias where SQL Server requires the source expression;
- accepting authored pagination plus runtime pagination;
- dropping write-resource UPSERT keys during config normalization;
- trusting request-supplied roles or database/resource identifiers;
- letting normal API authentication authorize Admin endpoints;
- returning API-key secrets, password hashes, ciphertext, keys, SQL, paths, or
  driver diagnostics;
- treating local PHP servers/process managers as production infrastructure;
- deleting dynamically discovered SQL or generated-state templates based only on
  missing static filename references.

Use [Security model](security/Security-Model.md), [Production security and
deployment](Production-Security-and-Deployment.md), and [Limitations](Limitations.md)
to keep trust boundaries and residual risks explicit. Record new security
findings in [Security verification](security/Security-Verification.md).

## Documentation rules

- Reference documents describe current behavior only. Planned work goes in
  [Roadmap](Roadmap.md); released changes go in `CHANGELOG.md` (under
  `[Unreleased]` until a release is tagged).
- Each topic has one authoritative document; link to it instead of repeating it.
- Verify every technical claim against the code before documenting it.
