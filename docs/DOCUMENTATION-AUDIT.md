# Documentation audit inventory

This inventory records the DOCS-0020 review of Framework documentation and
public examples as of 2026-11-02. A document marked **retained** was checked
against the implementation and remains a useful canonical or focused reference;
it was not shortened merely to make the library look uniform.

## Canonical ownership

| Contract | Canonical source |
| --- | --- |
| Endpoint, transport, and request lifecycle | [HTTP API](API.md) |
| Public actions and parameters | [Action reference](Action-Reference.md) and [JSON request reference](JSON-Request-Reference.md) |
| Responses and errors | [Response reference](Response-Reference.md) and [Errors and validation](Errors-and-Validation.md) |
| Authentication and authorization | [Authentication and authorization](Authentication-and-Authorization.md) |
| Access-scope restrictions | [Access scopes](Access-Scopes.md) |
| SQL Resource execution and authoring | [SQL Resource mode](SQL-Resource-Mode.md) and [authoring](SQL-Resource-Authoring.md) |
| Deployment security | [Production security and deployment](Production-Security-and-Deployment.md) |
| Dashboard integration | [Frontend integration](Frontend-Integration.md) |
| Release history and status | [`CHANGELOG.md`](../CHANGELOG.md) and [Roadmap](Roadmap.md) |

## Repository and maintenance documents

| File | Purpose | Audit outcome |
| --- | --- | --- |
| `README.md` | Product overview | Modernized for Framework identity, quick start, configuration, limitations, contribution, license, and Dashboard relationship |
| `CONTRIBUTING.md` | Engineering workflow | Expanded documentation, examples, security reporting, compatibility, review, commit, and release expectations |
| `CHANGELOG.md` | Version history | Retained unchanged; historical product names and released versions preserved |
| `LICENSE` | License terms | Retained unchanged |
| `docs/README.md` | Documentation navigation | Updated for Framework identity and this inventory |
| `docs/AI-Development-Guide.md` | Maintainer invariants and regression risks | Retained; it documents repository boundaries rather than product identity |
| `docs/Testing.md` | Database-independent and live validation | Retained; commands and CI behavior match the repository |
| `docs/Roadmap.md` | Completed, planned, deferred work | Retained; historical release names remain accurate history |
| `docs/Upgrading-to-V3.md` | V2-to-V3 migration | Retained; compatibility, verification, and rollback remain relevant |

## API and query references

| File | Purpose | Audit outcome |
| --- | --- | --- |
| `docs/API.md` | HTTP endpoint and request lifecycle | Retained as the canonical transport contract |
| `docs/Action-Reference.md` | All public actions | Retained; action inventory matches validators and dispatch |
| `docs/JSON-Request-Reference.md` | Accepted JSON shapes | Retained as canonical field-level reference |
| `docs/Response-Reference.md` | Success and error envelopes | Retained |
| `docs/Errors-and-Validation.md` | Validation pipeline and error codes | Retained |
| `docs/Query-Mode.md` | JSON Query behavior | Retained |
| `docs/Query-Functions.md` | Public function allowlist | Retained |
| `docs/Query-Examples.md` | Worked API requests | Retained; examples are illustrative, not runtime fixtures |
| `docs/Filtering-Sorting-Pagination.md` | Shared runtime controls | Retained |
| `docs/Capability-Matrix.md` | Cross-mode comparison | Retained as the canonical capability matrix |
| `docs/SQL-Resource-Mode.md` | Resource execution | Retained |
| `docs/SQL-Resource-Authoring.md` | Trusted resource authoring | Retained |
| `docs/Write-API.md` | CRUD contract | Retained; Dashboard write capability remains disabled |
| `docs/Metadata-and-Routines.md` | Metadata and routine actions | Retained |
| `docs/SQL-Parser-Generator.md` | Non-executing conversion tool | Retained with its limitations |
| `docs/Limitations.md` | Unsupported behavior | Retained; SQL Server-only provider boundary confirmed |

## Architecture, security, and operations

| File | Purpose | Audit outcome |
| --- | --- | --- |
| `docs/Architecture.md` | Components and request flow | Retained |
| `docs/Authentication-and-Authorization.md` | Identities, roles, sessions, keys | Retained |
| `docs/Access-Scopes.md` | Per-role resource restrictions | Retained |
| `docs/Database-Configuration.md` | Registry, encryption, ODBC | Retained |
| `docs/Admin-Console.md` | Loopback management plane | Retained |
| `docs/Runtime-and-Performance-Controls.md` | Limits and timeouts | Retained |
| `docs/Logging.md` | Operational and audit logs | Retained |
| `docs/Monitoring-and-Health.md` | Health endpoints and monitoring | Retained |
| `docs/Backup-and-Recovery.md` | Configuration backup and restore | Retained; SQL Server backup remains operator-owned |
| `docs/Local-Development.md` | Local launchers and diagnostics | Retained |
| `docs/Production-Security-and-Deployment.md` | Production boundary | Retained |
| `docs/Windows-IIS-Deployment.md` | Windows procedure | Product and Dashboard repository names corrected; detailed procedure retained |
| `docs/security/Security-Model.md` | Trust boundaries and accepted risks | Retained as canonical security model |
| `docs/security/Security-Verification.md` | Verification record | Product identity corrected; findings history preserved |
| `docs/security/Penetration-Test-Preparation.md` | External test handoff | Retained; no test completion is implied |

## Integration and examples

| File or directory | Purpose | Audit outcome |
| --- | --- | --- |
| `docs/Frontend-Integration.md` | Dashboard-to-Framework contract | Corrected invalid frontend JSON shapes and clarified read/write ownership |
| `docs/Example-Library.md` | Example activation and SQL ownership | Expanded with a per-file purpose, dependency, authorization, and validation catalog |
| `examples/README.md` | Public example catalog | Retained; generic and security boundaries verified |
| `examples/api/json-query/` | Read contract | JSON syntax and production request validation covered |
| `examples/api/sql-resource/` | SQL Resource request | JSON syntax and request validation covered; matching resource documented |
| `examples/api/metadata/` | Metadata request | JSON syntax and validation covered |
| `examples/api/backend-only/` | Write and routine requests | Clearly separated from Dashboard behavior and never auto-executed |
| `examples/sql-resources/catalog/` | Generic SQL Server templates | Static analyzer coverage; deliberate activation required |
| `tests/fixtures/README.md` | Test-only fixtures | Retained; not part of the public library |
| `runtime/windows/php/README.md` | Upstream bundled PHP documentation | Retained as third-party runtime documentation, not Framework product identity |

## Consolidation decisions

No documents were renamed, merged, or removed. The API reference is intentionally
split by concern because `Action-Reference.md` is a complete action catalog,
`JSON-Request-Reference.md` is the field grammar, and `API.md` is the transport
and lifecycle overview. The documentation map identifies these canonical roles
instead of duplicating their contents.

## Publication and validation boundary

The tracked public library uses illustrative `Demo*` objects and logical
database IDs. Ignored runtime configuration, logs, storage, backups, and local
SQL Resources were not copied into documentation or examples. Structural tests
do not prove live SQL Server compatibility; operators must use a dedicated
non-production database and least-privileged credentials for that validation.

The legacy `Generic SQL API Framework` string in application-backup manifests
is retained as a compatibility identifier: restore validation depends on that
exact value. It is not presented as the current product name or user-facing
branding.
