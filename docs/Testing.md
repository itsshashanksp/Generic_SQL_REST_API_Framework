# Testing

The backend has a database-independent regression suite and a set of static
checks. Live SQL Server and deployed-host validation are separate, manual
activities.

## Running the suite

From the `Backend` directory:

```bash
php tests/run.php
php -n tests/run.php
```

Both must pass: `-n` runs without any `php.ini`, which catches tests that depend
on host settings. `tests/run.php` runs the 66 registered suites in order and
stops at the first failure.

Tests never write the deployment's state. Unless the caller sets them,
`tests/run.php` points `GENERIC_LOG_DIR`, `GENERIC_OPERATIONAL_LOG_DIR`, and
`GENERIC_SECURITY_STORAGE_DIR` at a temporary directory that every suite and
every server it starts inherits, and removes it afterwards; suites that need
runtime configuration, a health cache, or process state create their own
temporary copies. `config/`, `database/config/`, `runtime/`, `logs/`, and
`storage/` are unchanged by a run.

Requirements: PHP 8.2 or newer with JSON, OpenSSL, and session support. The
suite needs no SQL Server, ODBC extension, credentials, `database.json`, or
running service. Builders and repositories are exercised through fake
`QueryEngine` and `MetadataRepository` subclasses whose constructors never
connect. HTTP-level suites start temporary PHP built-in servers on loopback
with isolated, temporary runtime state and synthetic identities.

## Coverage

Each suite is a standalone PHP program named for the behavior it tests. At a
high level they cover:

- **API contract and SQL generation:** validation, normalization, JSON Query
  features, expressions, windows, pagination strategies, set operations,
  routines, metadata, and response envelopes.
- **SQL Resources and writes:** discovery, path safety, execution metadata,
  filter placement, CRUD and UPSERT generation, write target and column rules.
- **SQL Parser:** parsing, generation, capability analysis, and asset routing.
- **Security:** authentication, sessions, CSRF, CORS, API keys, authorization
  boundaries (including the 64-test authorization and API coverage plan and a
  check that sessions and API keys reach identical decisions for every role and
  action), system-object and identifier rejection,
  injection attempts, rate limits, HTTPS and header templates, static-analysis
  and DAST regressions, and operational hardening.
- **Operations:** runtime configuration bootstrap and migration, encryption,
  database availability, Admin Console and runtime management, health and
  readiness, backup and restore, logging, production error handling, hosting
  templates, and concurrency of sessions, setup, configuration, and logs.
- **Repository consistency:** documentation links, release metadata, and
  configuration examples.

When fixing a bug, add a regression test to the relevant suite or add a new
suite and register it in `tests/run.php`. Files named `*Worker.php` and
`*Fixture.php`, and `tests/support/`, are helpers, not suites.

## Static checks

```bash
find api admin app config core database scripts sqlparser tests -type f -name '*.php' -exec php -l {} \;
node --check admin/assets/admin.js
node --check sqlparser/assets/js/app.js
bash -n start-linux.sh
git diff --check
```

## Continuous integration

`.github/workflows/backend-tests.yml` runs on every push and pull request with
PHP 8.2: it lints the PHP files and runs `php tests/run.php`. CI never loads
ODBC, starts the application, or uses credentials, and there is no live SQL
Server workflow.

## Beyond the suite

The suite proves application behavior and template structure, not a deployment.
Before production use, validate on the target host:

- `php scripts/validate-production.php`: a non-mutating report of PHP version
  and extensions, production settings, templates, runtime configuration
  location, and database transport warnings. It never connects to SQL Server or
  prints secrets.
- `php scripts/check-database.php` under the worker identity, then real queries,
  writes, constraints, triggers, UPSERT concurrency, timeouts, and execution
  plans against the deployment database.
- IIS/FastCGI or Nginx/PHP-FPM routing, TLS, headers, sessions, CORS/CSRF, and
  file permissions, using the checklists in
  [Production security and deployment](Production-Security-and-Deployment.md#deployment-verification).
- Load and concurrency behavior of the real worker pool.

Security verification history and the outstanding external penetration test
are in [Security verification](security/Security-Verification.md).
