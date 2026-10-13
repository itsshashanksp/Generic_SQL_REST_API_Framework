# Access scopes

Access scopes limit what an authenticated principal may read, beyond its role
permissions. Role permissions (see
[Authentication and authorization](Authentication-and-Authorization.md)) decide
*which kinds of action* a principal may call; access scopes decide *which
databases, tables, columns, SQL Resources, filter columns, and metadata
operations* those actions may touch. The frontend is never the boundary: every
rule below is enforced by the API, including for direct requests.

## Configuration file

`config/access-scopes.json` (or `GENERIC_RUNTIME_CONFIG_DIR/access-scopes.json`)
is deployment configuration: it is git-ignored, holds no secrets, and is never
created automatically. [`config/access-scopes.example.json`](../config/access-scopes.example.json)
is a minimal safe example.

```json
{
  "version": 1,
  "mode": "log-only",
  "scopes": {
    "frontend": {
      "actions": ["select", "sql"],
      "databases": {
        "reporting": {
          "tables": {
            "Orders": "*",
            "Customers": ["CustomerId", "Name", "City"],
            "sales.Invoices": "*"
          },
          "resources": {
            "widgets/sales-month-wise": { "filters": ["Bill_Date"] },
            "reports/*": { "filters": [] }
          },
          "metadata": []
        }
      }
    },
    "read-only": {
      "actions": ["select", "union", "unionAll", "metadata"],
      "listDatabases": true,
      "databases": {
        "reporting": { "tables": { "Orders": "*" }, "metadata": ["tables"] }
      }
    },
    "system-administrator": { "unrestricted": true }
  }
}
```

| Field | Rule |
|---|---|
| `version` | `1` |
| `mode` | `log-only` or `enforce` (see [Modes](#modes)) |
| `scopes` keys | `frontend` (every principal with frontend access) or a role id: `read-only`, `data-operator`, `api-administrator`, `system-administrator`, `application-administrator` |
| `{"unrestricted": true}` | Explicit full access for that subject; the only way to permit routines and writes. Nothing is unrestricted by omission. |
| `actions` | Non-empty subset of `select`, `union`, `unionAll`, `sql`, `metadata` |
| `listDatabases` | Allows `metadata.databases` (default `false`) |
| `databases` | Keys are logical database ids from the registry — never physical names |
| `tables` | `Table` or `schema.Table` → `"*"` (every column) or a column list. Names match exactly as the request writes them (case-sensitive); `dbo.Orders` and `Orders` are different entries. `sys` and `INFORMATION_SCHEMA` are rejected. |
| `resources` | Exact resource id, or `dir/*` matching exactly one final segment. An exact entry wins over its pattern. |
| `resources.*.filters` | The only columns callers may filter that resource by |
| `metadata` | Subset of `tables`, `columns`, `views`, `procedures`, `schema` for that database |

Unknown keys, unknown subjects, duplicate entries, `*` table names, `**`,
inner wildcards, path separators other than `/`, file extensions, and
traversal are rejected. A malformed file is never partially applied.

Validate a file with `php scripts/validate-access-scopes.php [path]`. It is
read-only and distinguishes a *valid* file from one *ready for enforcement*:

| Exit | Meaning |
|---|---|
| 0 | Valid, complete, and `enforce` |
| 1 | Invalid (malformed); the API fails closed |
| 3 | Valid and complete, but missing or `log-only` |
| 4 | Valid but incomplete: it names an unregistered database or a SQL Resource that does not exist |

Its JSON output lists `blocking` items (exit 4) and `review` items that need an
explicit owner decision before enforcement: `unrestricted` subjects, `"*"`
tables, `dir/*` resource patterns, resource filter columns, metadata grants,
`listDatabases`, and roles that enabled users or API keys rely on but that
have no scope (those principals are denied once enforced). Review items never
change the exit status; signing them off is the owner's responsibility. The
example file is illustrative and reports exit 4 against a real registry.

## Evaluation

The candidate scopes of a request are those of its subjects: `frontend` when
the principal has frontend access, plus its backend and frontend roles. Every
check keeps only the candidates that allow it, so **one scope must allow the
whole request**; grants of different scopes are never combined. A principal
with no candidate is denied.

| Request | Checked |
|---|---|
| Any scoped action | The action is in `actions`; `procedure`, `function`, `tableFunction`, and writes need an unrestricted scope |
| `select`, `union`, `unionAll` | Every database named anywhere in the request; every physical source (base, joins, subqueries, CTE bodies, every set-operation branch); every column the query builders render (projections, function arguments, expressions, CASE, predicates, joins, grouping, HAVING, sorting, windows). `*` needs `"*"` for every source it expands. `COUNT(*)` counts rows of an allowed table and is permitted. |
| `sql` | The resource id; the target database and any `{{database:id}}` placeholder database; every runtime filter field and `execution.filters` key against the resource's `filters`. Client-supplied filter `expression`s and HAVING mappings are refused for restricted scopes. |
| `metadata.*` | The operation for the target database; `metadata.databases` needs `listDatabases` |

Checks run before any query executes, in this order:

1. Before the database availability gate: the action and the one database
   that gate examines (the request's declared primary database, else the
   default). An out-of-scope caller therefore receives the same `403` whether
   that database is unregistered, disabled, unavailable, or simply not
   granted; the protected logs keep the probed id and reason.
2. Before database planning: every database the request names and the SQL
   Resource id, so an out-of-scope caller cannot learn whether a database or
   SQL Resource exists.
3. After planning: every planned database (including SQL Resource
   placeholders), the resource's filter columns, and metadata operations.
4. While the query builders resolve the request (`AuthorizingMetadataRepository`):
   every table and column, before the catalog is consulted.

Callers inside their scope, and unrestricted principals, still receive the
availability gate's diagnostics (`DATABASE_NOT_FOUND`, `DATABASE_DISABLED`,
`DATABASE_UNAVAILABLE`). In `log-only` mode responses are the legacy ones, so
this protection, like every other, applies only once enforced. A denial returns `403 AUTHORIZATION_DENIED` with no
details. Metadata results are not filtered by table scope: grant metadata
operations only where listing every object of that database is acceptable.

Administration, setup, `auth.*`, and frontend user management are not scoped;
their existing authorization is unchanged.

## Modes

| Mode / state | Behavior |
|---|---|
| `enforce` | Out-of-scope requests are denied before execution |
| `log-only` | The same rules run; would-be denials are logged once per request and the legacy response is unchanged |
| File missing, production (`GENERIC_APP_ENV=production`) | Fail closed: every scoped request is denied |
| File missing, other environments | `log-only` with no scopes: legacy behavior, each scoped request logged as `scope_configuration_missing` |
| File invalid, any environment | Fail closed |

Logs use the operational message `Access scope denied` / `Access scope would
deny` and the audit events `authorization.scope_denied` /
`authorization.scope_would_deny` with `errorCode: ACCESS_SCOPE_DENIED`, the
action, logical database, object identifier, and reason (for example
`table_not_in_scope`, `filter_not_in_scope`). They never contain filter values,
query text, credentials, or data, and are distinct from query failures.

## Frontend users

Accounts created on the frontend user-management screen as **Read Only** have
frontend access and **no backend role**; they hold only `frontend.read` and are
governed by the `frontend` scope. They cannot call routines or writes.

Disabling a frontend user, or revoking its frontend access, increments its
authentication version: its live sessions end on their next request, it can no
longer log in, and API keys it owns stop authenticating. Revoking frontend
access from an account without a backend role also disables it; it stays a
role-less frontend account that an Application Administrator can restore
(assign frontend access, then enable). No backend role is granted on restore.

Accounts created before this rule still hold the backend `read-only` role, so
the `read-only` scope also applies to them. List them with
`php scripts/report-frontend-reader-accounts.php` (read-only), then remove the
backend role in the Admin Console while keeping frontend access. The change
ends their current sessions. API keys are unaffected.

## Anonymous mode

Authentication mode `none` is refused in production (`503
AUTHENTICATION_UNAVAILABLE`), and the Admin Console cannot select it there,
unless `GENERIC_ALLOW_ANONYMOUS_PRODUCTION=true` is set for an approved
exceptional deployment. `publicRoles` defaults to an empty list, so an
anonymous principal holds no permission unless a deployment assigns roles, and
it never receives the `frontend` scope.

## Rollout and rollback

1. Generate a draft from the reporting frontend:
   `php scripts/generate-access-scope-draft.php <frontend>/src/config [default-database-id] > draft.json`.
   It grants the columns the frontend's structured requests use (fields,
   grouping, sorting, displayed columns, and filters) and the SQL Resources it
   runs with the filter fields it sends. Its stderr lists what needs explicit
   review: hidden filter columns (filterable but not displayed, including SQL
   Resource filters the frontend maps to source columns) and joined tables
   granted `"*"` because their columns cannot be attributed. A draft is never
   production-ready on its own; validate it and review every entry.
2. Add scopes for each role that API keys, the legacy key, or backend users
   rely on (see [API-key integrations](#api-key-integrations)). Grant
   `{"unrestricted": true}` only by explicit decision.
3. Install the file with `"mode": "log-only"` and watch for
   `authorization.scope_would_deny` until the expected traffic produces none.
4. Switch to `"mode": "enforce"`. In production, a missing or invalid file
   denies scoped requests, so keep the file under configuration management.
5. Migrate legacy frontend reader accounts (see [Frontend users](#frontend-users)).

Rollback is a configuration release: restore the previous reviewed file (for
example back to `log-only` while a missing grant is added). Do not remove the
file or grant `unrestricted` to restore production access.

## API-key integrations

Managed API keys and the legacy key are governed by the scope of their role
(`read-only` or `data-operator`); keys of the same role share one scope, and
legacy frontend readers that still hold `read-only` share it too. A role with
no scope is denied in `enforce` mode — nothing else stands in for it. Before
enforcing, the owner of each enabled key confirms:

1. The integration and its owner, and whether it is still needed (revoke it
   otherwise).
2. The logical databases it reads.
3. Its structured queries: tables, and the columns each needs (avoid `"*"`).
4. Whether it needs `union`/`unionAll`, metadata operations, or
   `metadata.databases`.
5. Its SQL Resources and the filter columns it sends.
6. That it needs no routines or writes (otherwise only an explicitly approved
   `unrestricted` scope serves it).

Record the approved scope, run it in `log-only` until the key's traffic
produces no `authorization.scope_would_deny`, and only then enforce. Do not
enforce while an in-use key's scope is unknown.

## Adding access safely

- **Database:** register it, then add its logical id under the scopes that need
  it. A scope never reaches an unlisted database.
- **Table or column:** add `Table` (or `schema.Table`) with the narrowest column
  list that serves the report; use `"*"` only when every column is approved.
- **SQL Resource:** review the SQL file, add its exact id with the filter
  columns callers may use, and grant its target database. Prefer exact ids over
  `dir/*`.
- **Metadata:** grant only the needed operation for one database.
- Run `php scripts/validate-access-scopes.php` and the backend tests before
  deploying.

## Database least privilege

Access scopes do not replace database permissions. The application's
reporting logins should be able to read only what reporting needs:

1. Run `php scripts/check-database-privileges.php <database-id> ...` (read-only,
   SQL Server). `ELEVATED` means the login is `sysadmin`, `db_owner`,
   `db_datawriter`, or `db_ddladmin`.
2. Create a dedicated reporting login per server profile with `CONNECT` and
   `SELECT` only on the reporting tables and views (or a reporting schema) of
   each registered database, plus `EXECUTE`/`SELECT` only on functions actually
   used. The SQL Resources in `queries/` and the scoped tables define the
   required objects.
3. Do not grant server roles, `db_owner`, `db_datawriter`, `db_ddladmin`, or
   `CONTROL`. Writes, if ever needed, use a separate profile and login.
4. Update the server profile in the Admin Console (**Databases → Servers**),
   test the connection, and verify reports.

These steps change database security and are performed by the database owner;
the API never alters logins or grants.
