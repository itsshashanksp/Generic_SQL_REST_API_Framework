# Test fixtures

`sql-resources/` contains historical application SQL retained only where exact
statements are required to verify resource discovery, filtering, CTE placement,
pagination, aliases, and access-scope regressions.

Tests use an explicit fixture registry. The aggregate runner temporarily copies
only missing fixtures into the ignored runtime discovery directories for HTTP
integration coverage and removes exactly what it created. Production startup
never loads this directory.
