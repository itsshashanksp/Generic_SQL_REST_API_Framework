# Documentation map

Every document here describes the current release unless stated otherwise.
Planned work appears only in the [Roadmap](Roadmap.md); release history is in
the [changelog](../CHANGELOG.md). Start with the repository
[README](../README.md) for an overview.

## Using the API

| Document | Covers |
|---|---|
| [HTTP API](API.md) | Endpoint, transport, request flow, and the Universal JSON Contract |
| [Action reference](Action-Reference.md) | Every public data action with examples |
| [JSON request reference](JSON-Request-Reference.md) | Exact accepted fields and shapes |
| [Response reference](Response-Reference.md) | Success and write envelopes, per-action messages |
| [Errors and validation](Errors-and-Validation.md) | Error envelope, every error code, failure handling |
| [Frontend integration](Frontend-Integration.md) | Turning UI state into requests |
| [Capability matrix](Capability-Matrix.md) | What each mode supports, side by side |
| [Limitations](Limitations.md) | Intentional boundaries and known gaps |

## Query modes

| Document | Covers |
|---|---|
| [JSON Query Mode](Query-Mode.md) | Client-composed SELECT, joins, CTEs, windows, set operations |
| [Query functions](Query-Functions.md) | The complete function allowlist |
| [Filtering, sorting, and pagination](Filtering-Sorting-Pagination.md) | Operators, sort rules, and paging across modes |
| [Query examples](Query-Examples.md) | Worked requests |
| [Metadata and routines](Metadata-and-Routines.md) | Metadata actions and routine calls |
| [SQL Resource Mode](SQL-Resource-Mode.md) | Executing server-owned SQL files by ID |
| [SQL Resource authoring](SQL-Resource-Authoring.md) | Adding and reviewing SQL Resource files |
| [Write API](Write-API.md) | INSERT, UPDATE, DELETE, and UPSERT on any user table |
| [SQL Parser](SQL-Parser-Generator.md) | Converting SQL into request JSON |

## Operating the backend

| Document | Covers |
|---|---|
| [Architecture](Architecture.md) | Components, request flow, and process model |
| [Local development](Local-Development.md) | Launchers, bundled runtimes, local troubleshooting |
| [Production security and deployment](Production-Security-and-Deployment.md) | Production hosting for IIS and Nginx, permissions, secrets, operator checklists |
| [Windows Server IIS deployment](Windows-IIS-Deployment.md) | Step-by-step Windows installation |
| [Database configuration](Database-Configuration.md) | The database registry, encryption, ODBC, key rotation |
| [Upgrading to V3](Upgrading-to-V3.md) | V2 → V3 migration, compatibility changes, verification, rollback |
| [Admin Console](Admin-Console.md) | Pages, availability controls, every Admin API action |
| [Authentication and authorization](Authentication-and-Authorization.md) | Modes, sessions, API keys, roles, user management |
| [Access scopes](Access-Scopes.md) | Per-role database, table, column, SQL Resource, filter, and metadata limits; rollout; least privilege |
| [Runtime and performance controls](Runtime-and-Performance-Controls.md) | Timeouts, rate limits, sessions, request and page limits |
| [Monitoring and health](Monitoring-and-Health.md) | Liveness, readiness, detailed health |
| [Logging](Logging.md) | Operational and audit logs |
| [Backup and recovery](Backup-and-Recovery.md) | Application configuration backups and restore |

## Security

| Document | Covers |
|---|---|
| [Security model](security/Security-Model.md) | Current trust boundaries, controls, and accepted risks |
| [Security verification](security/Security-Verification.md) | Verification history, findings register, deferred work |
| [Penetration-test preparation](security/Penetration-Test-Preparation.md) | Handoff for the outstanding external penetration test |

## Maintaining the repository

| Document | Covers |
|---|---|
| [AI development guide](AI-Development-Guide.md) | Repository boundaries and invariants for maintainers and coding agents |
| [Testing](Testing.md) | Running the suite, coverage, static checks, deployment validation |
| [Contributing](../CONTRIBUTING.md) | Change expectations and pull requests |
| [Roadmap](Roadmap.md) | Current, upcoming, and deferred work |

## Terminology

- **Universal JSON Contract:** the shared request dispatch (`action`) and
  response envelope.
- **JSON Query Mode:** the backend builds a validated SELECT from
  client-supplied structure.
- **SQL Resource Mode:** the client runs a server-owned, read-only SQL file by
  ID, optionally with validated `execution` metadata.
- **Execution metadata:** the request's declaration of approved SQL Resource
  output columns, filter mappings, and default sort; never arbitrary SQL.
- **Write API:** single-object INSERT, UPDATE, DELETE, or UPSERT on a table
  named in the request.
- **Principal:** the authenticated caller (session user, API key, legacy key, or
  anonymous), whose role permissions decide every authorization.
- **Runtime configuration directory:** the directory named by
  `GENERIC_RUNTIME_CONFIG_DIR` holding users, roles, API keys, Admin settings,
  and availability state.
