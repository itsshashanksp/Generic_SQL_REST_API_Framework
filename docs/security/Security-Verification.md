# Security verification

This is the security verification record for Framework
backend: what was verified, how, every finding with its current status, and
the security work that remains. Current controls are described in
[Security model](Security-Model.md); the outstanding external test is scoped in
[Penetration-test preparation](Penetration-Test-Preparation.md).

No password, hash, API key, encryption key, session identifier, or other secret
is reproduced here.

## Current status

- No Critical or High finding is open.
- The completed v2.1.0 architecture replaced the v2.1.2 registry controls for
  SSA-01, SSA-03, and SSA-07 by design: authorization is role permission only,
  and the database login's permissions are the data boundary. Their status
  below is **Superseded**, with the controls that replace them. See
  [Generic authorization model](#generic-authorization-model-v210).
- Every finding below has a recorded status. Open items are design limitations,
  hardening opportunities, or accepted risks, each documented in
  [Security model](Security-Model.md#accepted-risks-and-deployment-responsibilities)
  or [Limitations](../Limitations.md).
- v3.0.0 (multi-database) adds a repository-level isolation sweep
  (`SecurityIsolationRegressionTest`): unregistered and system databases,
  physical-name and qualified-name bypasses, identifier injection in every
  request position, SQL Resource placeholders and remote rowsets,
  cross-server combinations, single-target writes and routines, registry
  tampering and key handling, and secret leakage through errors. It found and
  fixed missing Azure SQL cross-database detection, test writes into live logs
  and state, and Admin acceptance of unrenderable catalog names. See
  [Security model](Security-Model.md#database-isolation).
- All verification so far was performed against the repository and local,
  isolated deployments. **No deployed production-like host has been tested, and
  the external penetration test has not been performed.**

## Verification history

| Release line | Activity | Date | Result |
|---|---|---|---|
| v2.0.0 | Attack-oriented security testing of authentication, sessions, authorization, API keys, CSRF/CORS, SQL/CRUD injection, paths, backups, health, logging, and errors | 2026-09-24 | ST-001 – ST-007; two fixed before release |
| v2.1.0 (v2.1.1) | Dependency security review | 2026-10-06 | No application dependencies; review-environment runtime updated |
| v2.1.0 (v2.1.2) | Manual static security analysis of backend, Admin Console, SQL Parser, templates, CI, and Git history | 2026-10-06 | SSA-01 – SSA-20; SSA-01 – SSA-12 remediated or dispositioned |
| v2.1.0 (v2.1.3) | Authorization and API boundary inventory and 64-test plan | 2026-10-06 | AAPI-01 – AAPI-09; all 64 tests pass (56 over HTTP, 8 at the enforcement layer) |
| v2.1.0 (v2.1.4) | Passive, unauthenticated DAST of a local development deployment and review of the IIS and Nginx templates; penetration-test preparation | 2026-10-06 | DAST-01 – DAST-05; authenticated dynamic testing deferred |
| v2.1.0 (v2.1.5) | Security architecture and operational hardening review of code, templates, and documentation | 2026-10-06 | SAOH-01 – SAOH-08; no Critical or High |
| v2.1.0 (v2.1.6) | Final repository-level verification | 2026-10-07 | No regression and no documentation/code contradiction; all findings dispositioned |

Final verification (v2.1.6) covered:

- the backend regression suite with and without a loaded `php.ini`
  (`php tests/run.php`, `php -n tests/run.php`) and the targeted security suites;
- re-running every mutation check from v2.1.3 – v2.1.5 (each weakened control
  was caught by a test);
- PHP lint, documentation tests, and a secret scan of tracked files, which found
  only synthetic test values, example placeholders, and the fixed login-timing
  placeholder hash;
- a code cross-check of the Admin loopback and `GENERIC_ADMIN_ENABLED` gates, the
  runtime configuration directory, encryption-key handling, query-source and
  routine registries, the result-row limit, readiness, SQL Parser routing,
  session settings, backup signing, and production PHP settings.

Methods and limits that apply across all activities: no external SAST, DAST, or
software-composition scanner was used; no live SQL Server was used, so
database-dependent behavior was reasoned from code; IIS, Nginx/PHP-FPM, real
TLS, and multi-worker load were not executed.

## Findings register

Status terms: **Superseded** (the original control was replaced by a deliberate
design change; residual risk accepted and described), **Fixed** (code or
configuration changed, regression-tested),
**Documented** (resolved by documented operator guidance), **Accepted**
(intended behavior, documented), **Open** (known limitation or hardening
opportunity), **Deferred** (not in an approved remediation scope yet).

### v2.0.0 security testing (ST)

| ID | Severity | Finding | Status | Resolution / regression coverage |
|---|---|---|---|---|
| ST-001 | Medium | Unauthenticated or invalid-key requests returned `401` before the API rate limiter, bypassing the limit | Fixed | The anonymous address identity is consumed before `401`. `SecurityTestingTest` |
| ST-002 | Low | CORS origin validation accepted userinfo, query, or fragment unless all were present | Fixed | Each forbidden URL component is rejected independently. `SecurityTestingTest` |
| ST-003 | Medium | No per-role read-column authorization | **Open — design limitation** | Authorization is role permission only (v2.1.0): a principal with `data.read` can read every column of every table or view the database login can read. Restrict exposure with the login's grants and least-privilege views or SQL Resources; API-level resource or column isolation would be a new public-contract feature and is not planned for v2.2. |
| ST-004 | Low | No independent filter-count limit | **Open — hardening opportunity** | Still no separate limit in the validators. Bounded by the request body limit, API rate limit, query timeout, and worker limits. Measure representative filter usage before adding one. |
| ST-005 | Low | Rate-limit state is single-host | Accepted | Exact only for workers sharing one local filesystem; see SAOH-08 |
| ST-006 | Informational | Plaintext database configuration remains readable for compatibility | Accepted | Production must save or migrate to the encrypted envelope |
| ST-007 | Informational | SQL Parser has no authentication | Accepted | Database-free and non-executing; keep it on a loopback or internal listener (target-host verification) |

### v2.1.2 static security analysis (SSA)

| ID | Severity | Finding | Status | Resolution / regression coverage |
|---|---|---|---|---|
| SSA-01 | High | Routine actions could execute any stored procedure or function | Superseded (v2.1.0) | The v2.1.2 routine registry was removed. Routines must exist as user routines of the requested kind in the configured database; `sys`/`INFORMATION_SCHEMA` schemas, cross-database names, and `sp_`/`xp_` system procedures are rejected; procedures require `routine.execute` plus `data.write` and CSRF; the login's `EXECUTE` grants bound the rest. `StaticSecurityRemediationTest`, `AuthorizationAndApiKeyTest` |
| SSA-02 | High | First-run administrator creation was public on the API | Fixed | `setup.createAdmin` is Admin-API-only and gated. `StaticSecurityRemediationTest` |
| SSA-03 | Medium | JSON Query Mode could read any object the SQL login could read | Superseded (v2.1.0) | The v2.1.2 query-source registry was removed by design: `data.read` reaches every user table or view the login can read. Sources must be confirmed by the database catalog, so system objects and other databases never resolve. Data exposure is bounded by the login's grants (ST-003). `StaticSecurityRemediationTest` |
| SSA-04 | Medium | User and API-key administration exposed on the public API | Fixed | `auth.users.*`, `auth.apiKeys.*`, `auth.roles.list` are Admin-API-only. `StaticSecurityRemediationTest` |
| SSA-05 | High | An administrator password hash was present in pushed Git history | Cleared | The credential was rotated on 2026-10-06 and verified not to match the historical hash. History was not rewritten. |
| SSA-06 | Low | `frontend.read` is not narrowed by SQL Resource scopes | Obsolete (v2.1.0) | Per-role SQL Resource scopes no longer exist; `sql.execute` and `frontend.read` both run any discovered resource. `StaticSecurityRemediationTest`, `AuthorizationApiCoverageTest` |
| SSA-07 | Low | SQL Resource runtime filters could test non-exposed columns | Superseded (v2.1.0) | Runtime mappings must reference a top-level source of the authored statement and catalog-confirmed columns; the query-source check was removed with the registry. A caller with `sql.execute` can filter on columns of the resource's top-level tables. `StaticSecurityRemediationTest`, `SqlResourceFilteringTest` |
| SSA-08 | Low | Loopback and rate-limit identity rely on `REMOTE_ADDR` | Documented | Same-host proxies in front of the entry points are forbidden by the deployment guide |
| SSA-09 | Low | ODBC driver auto-detection could fall back to legacy drivers | Fixed | Production uses only ODBC Driver 18/17 and reports weakened transport. `StaticSecurityRemediationTest` |
| SSA-10 | Informational | Some query literals are inlined with quote doubling | No change | No exploitable path; edge-case tests added. `StaticSecurityRemediationTest` |
| SSA-11 | Low | Unpaginated reads were not size-bounded | Fixed | `GENERIC_MAX_RESULT_ROWS`, `413 RESULT_TOO_LARGE`. `StaticSecurityRemediationTest` |
| SSA-12 | Low | Bundled Windows PHP runtime was outside the dependency review | Documented | See [Runtime dependencies](#runtime-dependencies) |
| SSA-13 | Informational | Password maximum (1,024) exceeds bcrypt's 72-byte input | Deferred | Unchanged |
| SSA-14 | Informational | `health.php` paths other than `/health/live` and `/health/ready` return version, port, and start time; IIS allows direct `/api/health.php` | Deferred | Unchanged; Nginx maps only the two probes |
| SSA-15 | Informational | Login throttling is per address plus username only | Deferred | Unchanged; the API rate limit partly offsets it |
| SSA-16 | Informational | Anonymous `auth.csrf` calls create sessions on demand | Deferred | Unchanged; bounded by the API rate limit |
| SSA-17 | Informational | CSRF did not cover routine actions | Fixed (v2.1.0) | Every stored procedure call requires CSRF for session callers; scalar and table-valued functions cannot modify data and stay unprotected, like `select`. `ApiSecurityHardeningTest` |
| SSA-18 | Informational | AES-GCM envelopes carry no associated data | Deferred | Unchanged |
| SSA-19 | Informational | Dynamic controller dispatch in `api/index.php` | Deferred | Reached only with normalizer-fixed values |
| SSA-20 | Informational | CI uses tag-pinned actions and only PHP 8.2 | Deferred | Unchanged |

### v2.1.3 authorization and API security testing (AAPI)

| ID | Severity | Finding | Status | Resolution / regression coverage |
|---|---|---|---|---|
| AAPI-01 | High | Application Administrators could take over or alter backend-only accounts | Fixed | Frontend user management refuses backend identities for every actor. `AuthorizationBoundaryTest`, `FrontendUserMutationAuthorizationTest` |
| AAPI-02 | Medium | System Administrator identities were manageable through the public API | Fixed | Same control. `AuthorizationBoundaryTest`, `FrontendUserMutationAuthorizationTest` |
| AAPI-03 | Low | Routine actions were not CSRF-protected | Fixed | Write routines required CSRF from v2.1.3; under the final v2.1.0 model every stored procedure does. `ApiSecurityHardeningTest` |
| AAPI-04 | Low | Username enumeration and System Administrator profile disclosure to frontend administrators | Fixed | Minimized profiles and uniform not-found responses; `409` on duplicate create/rename accepted. `ApiSecurityHardeningTest` |
| AAPI-05 | Informational | Password changes do not require the current password | Accepted | No self-service or recovery flow; changes are administrator resets. `ApiSecurityHardeningTest` |
| AAPI-06 | Informational | API key privileges are independent of the owner's role | Accepted | Owner must exist and be enabled. `ApiSecurityHardeningTest` |
| AAPI-07 | Informational | Public API accepts a JSON list body | Accepted | Rejected by authentication or authorization (`401`/`403`); no dispatch path. `ApiSecurityHardeningTest` |
| AAPI-08 | Informational | `publicRoles` and `legacyApiKeyRoles` accepted privileged roles | Fixed | System Administrator is rejected; Data Operator for anonymous or legacy-key principals remains an operator choice. `ApiSecurityHardeningTest` |
| AAPI-09 | Informational | A non-string public `action` emitted a PHP warning | Fixed | Rejected cleanly. `AuthorizationApiCoverageTest` |

### v2.1.4 DAST (DAST)

| ID | Severity | Finding | Status | Resolution / regression coverage |
|---|---|---|---|---|
| DAST-01 | Low | SQL Parser disclosed the PHP version in `X-Powered-By` | Fixed | Header removed; bundled Linux runtime sets `expose_php = Off`. `DastRegressionTest` |
| DAST-02 | Low | SQL Parser development router executed any PHP file in its document root | Fixed | Router serves only the intended assets. `DastRegressionTest` |
| DAST-03 | Informational | Development static assets have no security headers | Accepted | Development only; production headers come from IIS/Nginx |
| DAST-04 | Informational | Development `/health` reports listener metadata | Accepted | Development only; see SSA-14 for the IIS direct path |
| DAST-05 | Informational | API and Admin development routers pass existing files through | Accepted | Development only; document roots contain no other code |

### v2.1.5 architecture and operational hardening (SAOH)

| ID | Severity | Finding | Status | Resolution / regression coverage |
|---|---|---|---|---|
| SAOH-01 | Medium | Runtime state shared a writable directory with executable configuration | Fixed | Runtime configuration lives in `GENERIC_RUNTIME_CONFIG_DIR` outside the code tree; `Backend/config` is read-only; `validate-production.php` reports the location. `OperationalHardeningTest` |
| SAOH-02 | Low | Development PHP runtimes were writable by the production pool | Fixed | Deployment ACLs keep them read-only. `OperationalHardeningTest` |
| SAOH-03 | Low | Readiness at `/api/health/ready` always reported healthy | Fixed | Probes recognized by final path segments. `OperationalHardeningTest` |
| SAOH-04 | Informational | Boundaries share one worker identity | Accepted | Optional dedicated parser pool |
| SAOH-05 | Informational | Default backup-signing key shares host and identity with the archives | Accepted | Supply the key from a vault |
| SAOH-06 | Informational | `db_datareader` grants read on every table | Accepted | Use narrower grants where possible |
| SAOH-07 | Informational | Log rotation, retention, and central collection are external | Accepted | Deployment responsibility |
| SAOH-08 | Informational | Security state is single-host | Accepted | Keep one application host |

### Generic authorization model (v2.1.0)

The completed v2.1.0 architecture replaces the mandatory application-specific
registries with generic authorization. It is a design decision, not a verification activity, and is
covered by the regression suite (`AuthorizationAndApiKeyTest`,
`AuthorizationApiCoverageTest`, `CrudOperationsTest`,
`StaticSecurityRemediationTest`, `ApiSecurityHardeningTest`).

- **Removed:** `config/query-sources.php`, `config/write-resources.php`,
  `config/routine-resources.php`, and per-role `sqlResources`/`writeResources`
  scopes.
- **Kept or added:** one permission-only decision for every authentication
  method; `Name`/`Schema.Name` identifiers with system-schema, cross-database,
  and system-procedure rejection; catalog confirmation of every table, column,
  and routine; prepared parameters; DML-only write generation; CSRF for every
  write and stored procedure; result-size limits; the separate Admin API.
- **Residual risk (accepted):** API permissions are coarse. With a broad or
  shared database login, every `data.read` or `data.write` principal reaches
  everything that login can. Deployments must grant the login only what clients
  should reach.

## Runtime dependencies

The application has no Composer, npm, vendored, or external-include
dependencies. Its dependency surface is the host PHP runtime and extensions,
operating-system libraries, and the ODBC stack (driver manager and Microsoft
ODBC Driver for SQL Server). Production hosts install and patch these
themselves; repeat a version-currency review whenever PHP, the operating
system, or the ODBC stack changes, and before each release. The v2.1.1 review
was a version-currency check against local package metadata, not a
vulnerability scan.

The repository also tracks a portable Windows PHP runtime in
`runtime/windows/php/` for `start-windows.bat` only; IIS deployments use a
separately installed PHP.

| Field | Value |
|---|---|
| Component | PHP 8.5.10, Thread Safe, x64, Visual C++ 2022 (VS17) build |
| Bundled OpenSSL | 3.5.7 |
| Provenance | Build metadata matches the official windows.php.net release pipeline; the original download URL and archive checksum were not recorded |
| Integrity record | [Windows-PHP-Runtime.sha256](Windows-PHP-Runtime.sha256), verified with `sha256sum -c docs/security/Windows-PHP-Runtime.sha256` from the repository root |

Compare the bundle with current PHP 8.5 and OpenSSL 3.5 security releases when
the dependency review is repeated, and record the official archive URL and
checksum on the next update.

## Deferred security work

- **External penetration test**, including authenticated dynamic testing,
  session and rate-limit testing over HTTPS, injection testing against a SQL
  Server test database, dynamic SQL Parser input testing, TLS and deployed IIS
  or Nginx behavior, and the Admin loopback boundary behind production hosting.
  See [Penetration-test preparation](Penetration-Test-Preparation.md).
- **Target-host verification** of ACLs, effective `php.ini`, session storage,
  and live SQL Server behavior (operator-owned).
- **SSA-13 – SSA-16, SSA-18 – SSA-20.**
- **ST-003** column-level read authorization and **ST-004** filter-count limit.
- Optional Admin MFA.

Record future verification and its findings here, and add a regression test for
each remediated finding.
