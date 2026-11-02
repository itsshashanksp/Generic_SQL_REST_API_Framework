# Contributing

Framework is the SQL Server API backend. Changes must preserve the public JSON
contract, security boundaries, database-independent testability, and backward
compatibility unless a migration is explicitly documented. Keep Dashboard and
report-presentation changes in the frontend repository.

Read the [AI development guide](docs/AI-Development-Guide.md) before changing
code: it lists the repository boundaries, the security-relevant request flow,
and common regression risks.

## Structure

Public requests enter at `api/index.php`, pass through
`app/Requests/QueryRequestValidator.php` and `QueryRequestNormalizer.php`, then
through controllers and services. `app/Repositories/QueryRepository.php` is an
orchestration and execution facade; keep query logic in the builders under
`app/Repositories/Query/` and in `SetOperationBuilder.php`.

Database connection and execution belong in `core/Database.php`,
`core/QueryEngine.php`, and `database/`. Response envelopes belong in
`core/Response.php`. Public contract changes start at the validator and
normalizer.

## Checks

PHP 8.2 is the CI baseline. Before opening a pull request run:

```bash
php tests/run.php
php -n tests/run.php
find api admin app config core database scripts sqlparser tests -type f -name '*.php' -exec php -l {} \;
git diff --check
```

The suite must run without SQL Server, an ODBC extension, credentials, or
`database/config/database.json`; use fake `QueryEngine` and
`MetadataRepository` subclasses whose constructors do not connect. Do not skip
logic when a database is absent, hide connection failures, or increase timeouts
to make tests pass. See [Testing](docs/Testing.md) for the full set of checks.

Live SQL Server testing is manual: install PHP ODBC and a supported Microsoft
ODBC driver, configure the database through the Admin Console, run
`php scripts/check-database.php`, then exercise the API. Never add credentials
or encryption keys to CI.

## Change expectations

- Preserve public behavior unless the change deliberately revises the contract.
- Reject unknown properties and validate identifiers and operators; never add a
  raw-SQL escape hatch.
- Keep filter, HAVING, routine, and write values as prepared parameters.
- Add a regression test for every bug. Window `ORDER BY` must never emit a bare
  integer position such as `ROW_NUMBER() OVER (ORDER BY 1)`.
- Test both SQL Server pagination paths (`OFFSET/FETCH` and `ROW_NUMBER`) when
  changing pagination.
- Distinguish public JSON names (`fields[].field`, `filters`, `limit`, nested
  `pagination`) from normalized builder keys.
- Do not describe the driver stubs in `database/drivers/` as supported
  providers.
- Keep credentials, `database.json`, `GENERIC_SQL_API_ENCRYPTION_KEY`, runtime
  configuration, logs, backups, and OPcache files out of commits.

## Documentation

- Reference documents describe current behavior. Update the relevant reference
  document in the same change as the code.
- Planned work belongs only in [docs/Roadmap.md](docs/Roadmap.md).
- Add user-visible changes to `CHANGELOG.md` under `[Unreleased]`.
- Record security findings in
  [docs/security/Security-Verification.md](docs/security/Security-Verification.md).
- Keep public examples generic and run `php tests/ExampleLibraryTest.php` when
  examples or their documentation changes.
- Use relative links inside this repository and the published Dashboard URL for
  cross-project references.
- Release preparation must reconcile tags, `CHANGELOG.md`, `README.md`, upgrade
  guidance, and the roadmap. Dashboard versions remain independent.

## Security reporting

Report suspected vulnerabilities privately to the repository maintainers. Do
not publish credentials, exploit payloads, private configuration, or customer
data in issues or pull requests.

## Pull requests

Include a concise description, the motivation, the tests run, whether a live
database test was performed, and any public-contract or deployment impact.
Review `git diff` for unrelated changes. Use focused commits following
`<type>(<scope>): <summary>` where practical. Reviewers should verify API
compatibility, authorization, input validation, SQL parameterization,
documentation, examples, and deployment impact before approval.
