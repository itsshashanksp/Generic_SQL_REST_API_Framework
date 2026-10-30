# Public examples

This directory contains generic, tracked examples only. The API request bodies
use illustrative logical database IDs and `Demo*` objects; adapt them to IDs and
objects authorized by the target deployment. Files under `sql-resources/` are
templates and are not discovered until an operator deliberately installs them
in the ignored runtime `queries/` tree.

- `api/json-query/` demonstrates the read-only JSON Query contract.
- `api/sql-resource/` demonstrates an installed SQL Resource request and its
  trusted execution metadata.
- `api/metadata/` demonstrates metadata discovery.
- `api/backend-only/` contains write and routine operations that the frontend
  does not issue and that require the corresponding backend authorization.
- `sql-resources/catalog/` contains generic SQL Server resource templates.

The examples contain no credentials, API keys, server names, or private
connection information. Private runtime configuration belongs in the ignored
configuration and storage paths documented by the deployment guide. Test-only
historical SQL belongs in `tests/fixtures/`, not here.
