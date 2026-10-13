# Authentication and authorization

This document describes identities, API authentication modes, sessions, managed
API keys, the fixed role model, and user-management actions. The security
boundaries behind them are in [Security model](security/Security-Model.md).

## Identities

Each local user has one identity and one password hash, with three independent
authorization fields:

- `backendRole`: `read-only`, `data-operator`, `system-administrator`, or `null`;
- `frontendAccess`: whether the identity may use the reporting frontend;
- `frontendRole`: `application-administrator` or `null`.

An enabled identity must have at least one access domain. Passwords are hashed
with `PASSWORD_DEFAULT` (minimum 12 characters). Responses and logs never
contain password hashes, API-key secrets, session data, or internal
authentication metadata.

Every protected request resolves to an internal `Principal` from persisted
storage. Sessions store only the identity and its `authVersion`; a change to
username, password, enabled state, or authorization increments `authVersion`,
so stale sessions fail closed on their next request. Request fields such as
`backendRole`, `frontendRole`, or `frontendAccess` never grant permissions.

## API authentication modes

The normal API's mode is set in **Admin Console → Configuration → Security**:

| Mode | Behavior |
|---|---|
| `none` | Requests run as an anonymous principal with the configured `publicRoles` (default: none). Refused in production unless `GENERIC_ALLOW_ANONYMOUS_PRODUCTION=true` ([details](Access-Scopes.md#anonymous-mode)) |
| `session` | A valid session is required |
| `api_key` | A valid `X-API-Key` is required |
| `session+api_key` | Either a valid session or a valid key |

API keys are accepted only in the `X-API-Key` header; they are not bearer
tokens and never create browser sessions. Actions beginning with `auth.` or
`admin.` always require a session, whatever the mode, and Admin actions also
require the Admin gate (see [Admin Console](Admin-Console.md)).

## Sessions and CSRF

| Action (public API and Admin API) | Purpose | CSRF |
|---|---|:-:|
| `setup.status` | Returns only whether the installation is initialized | no |
| `auth.csrf` | Returns the session-bound CSRF token | no |
| `auth.session` | Reports the current session's username and public authorization fields | no |
| `auth.login` | Authenticates a username and password | yes |
| `auth.logout` | Destroys the session | yes |

Login regenerates the session ID, deletes the prior session, rotates the CSRF
token, and returns only the username, `backendRole`, `frontendAccess`, and
`frontendRole`. Failures use one generic response for known and unknown
accounts and are throttled per source address and username
(`429 LOGIN_RATE_LIMITED`). Sessions expire after the configured idle and
absolute timeouts.

Session-authenticated mutations (writes, stored procedures, frontend
user changes, and Admin mutations) require the token in `X-CSRF-Token`.
API-key requests do not use CSRF. Cookie, CORS, and session-storage details
are in [Security model](security/Security-Model.md#sessions-csrf-and-cors).

## Managed API keys

Keys are created and managed in **Admin Console → API Keys** (Admin API
`auth.apiKeys.*`).

- Creation returns a `gsk_...` secret exactly once. Storage keeps only a hash
  and a short fingerprint; raw keys are never stored, listed, or logged.
- Metadata includes name, owner, role, enabled/revoked state, creation time, and
  last-used time.
- Each key has exactly one role: `read-only`, `data-operator`, or
  `api-administrator`. A key's role is fixed at creation and is independent of
  its owner's role.
- Key and owner state are checked on every request: a disabled or revoked key,
  or a disabled or deleted owner, stops authentication immediately. Disabled
  keys can be re-enabled; revocation is permanent.
- Failures do not disclose whether a key exists.

The legacy shared key `GENERIC_SQL_API_KEY` (at least 32 characters) remains
supported. Its permissions come from `legacyApiKeyRoles` (default `read-only`);
it is never an administrator credential.

## Roles

Roles are fixed. Their permission sets are validated on load and cannot be
edited into unsafe combinations.

| Role | Domain | Assignable to | Read | Write | Administration |
|---|---|---|:-:|:-:|---|
| Read Only (`read-only`) | backend | users, API keys | yes | no | none |
| Data Operator (`data-operator`) | backend | users, API keys | yes | yes | none |
| System Administrator (`system-administrator`) | backend | users | yes | yes | Admin Console, configuration, backend users, API keys, frontend users |
| API Administrator (`api-administrator`, shown as "Admin") | backend | API keys only | yes | yes | none |
| Application Administrator (`application-administrator`) | frontend | users | yes (frontend) | no | Frontend users only |

- **Read** covers `select`, `union`, `unionAll`, `sql`, scalar and table-valued
  functions, and metadata.
- **Write** covers `insert`, `update`, `delete`, `upsert`, and stored
  procedures (which also need `routine.execute`).
- `frontendAccess` grants frontend read capability. The frontend has no write
  screens, so frontend access never grants write permission.
- Application Administrator never grants Admin Console, configuration,
  API-key, runtime-control, or backend-role access.
- Authorization is decided by role permissions and then
  [access scopes](Access-Scopes.md), the same way for sessions, API keys, the
  legacy key, and anonymous mode; request validation, catalog checks, prepared
  parameters, and the database login's permissions still apply.

The permission matrix and per-action minimum privileges are in
[Security model](security/Security-Model.md#roles-and-permissions).

## Backend user administration

Served only by the Admin API (gate, System Administrator session, CSRF on
mutations). The public API returns `404` for these actions.

| Action | Purpose |
|---|---|
| `setup.createAdmin` | First-run creation of one enabled identity with System Administrator and Application Administrator; unavailable after initialization |
| `auth.users.list` | List identities and their assignments |
| `auth.users.create` | Create an identity with backend and frontend assignments |
| `auth.users.update` | Change the username |
| `auth.users.changePassword` | Set a new password (administrator reset) |
| `auth.users.enable`, `.disable`, `.delete` | Identity lifecycle |
| `auth.users.assignAuthorization` | Assign backend role, frontend access, and frontend role |
| `auth.roles.list` | List the fixed roles |

The last enabled System Administrator cannot be demoted, disabled, or deleted,
and an identity cannot delete itself.

## Frontend user administration

Served by the public API to sessions holding `frontend.users.manage`
(Application Administrator or System Administrator). API keys are not accepted.

| Action | Purpose | CSRF |
|---|---|:-:|
| `auth.frontendUsers.list` | List frontend-managed accounts (minimized profiles) | no |
| `auth.frontendUsers.create` | Create a frontend account | yes |
| `auth.frontendUsers.update` | Change the username | yes |
| `auth.frontendUsers.changePassword` | Set a new password | yes |
| `auth.frontendUsers.enable`, `.disable`, `.delete` | Account lifecycle | yes |
| `auth.frontendUsers.assignRole` | Assign or remove Application Administrator | yes |

These actions manage only frontend-managed accounts: accounts with no backend
role, or with a backend role the frontend may assign (currently `read-only`)
together with frontend access. A **Read Only** account created here receives
frontend access and no backend role, so it holds only `frontend.read` within the
`frontend` scope; older accounts keep the backend `read-only` role until
migrated ([Frontend users](Access-Scopes.md#frontend-users)). Accounts with `system-administrator`,
`data-operator`, or backend-only `read-only` are refused for every actor with
`403 AUTHORIZATION_DENIED`, and unknown accounts get a uniform not-found
response. The request contract has no backend-role field. If frontend access is
removed from a frontend `read-only` account, it becomes backend-only and can be
managed again only through the Admin API.

There is no self-service password change and no password-recovery flow.

## Schema migration

Runtime configuration is created with authentication schema version 4 and
authorization schema version 3. Older stores migrate in place, preserving IDs,
usernames, password hashes, enabled state, and creation dates: legacy Admin
becomes System Administrator plus Application Administrator, Data Editor becomes
Data Operator, and Viewer and Developer become Read Only. Existing users keep
frontend access. API keys that held System Administrator become
`api-administrator`.
