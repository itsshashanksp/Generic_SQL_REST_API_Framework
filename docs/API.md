# HTTP API

## Endpoint and transport

The filesystem entry point is `api/index.php`. When the repository root is the
web document root, use:

```http
POST /api/index.php
Content-Type: application/json
```

The bundled/local command uses `-t api`, making that same file available as
`POST /index.php`. The deployed URL therefore depends on web-server document-root
mapping; it is one entry script, not two API routes.

The API accepts `POST` and CORS preflight `OPTIONS`; other methods return 405.
Every operation requires a JSON object with `Content-Type: application/json`.
Exact browser origins and credential behavior come from validated Admin runtime
configuration (or its explicit deployment override); wildcard origins are not
accepted with credentials.

Normal data actions use the configured `none`, `session`, `api_key`, or
`session+api_key` mode. API-key clients send `X-API-Key`; bearer transport is not
part of the contract. Authentication produces a server-owned principal before
authorization evaluates the principal's role permissions. Administrator actions
remain on the loopback Admin API and always require a System Administrator
session regardless of the normal data-API mode.

## Request flow and actions

The body must be one JSON object. The required `action` is one of:

- `select`
- `sql`
- `insert`, `update`, `delete`, `upsert`
- `union`, `unionAll`
- `procedure`, `function`, `tableFunction`
- `metadata.tables`, `metadata.columns`, `metadata.views`, `metadata.procedures`, `metadata.schema`, `metadata.databases`

These 17 actions, including minimal/full requests, validation, responses, and
errors, are documented in [Action reference](Action-Reference.md). The exact
accepted field schema is [JSON request reference](JSON-Request-Reference.md).

For SELECT, `source.table` and a non-empty `fields` array are also required. Refer to [JSON-Request-Reference.md](JSON-Request-Reference.md) for every field and default.

```json
{
  "action": "select",
  "source": { "table": "Items", "alias": "I" },
  "fields": ["I.ItemCode", { "field": "I.Description", "alias": "ItemName" }],
  "sort": [{ "field": "I.ItemCode", "direction": "ASC" }],
  "pagination": { "page": 1, "pageSize": 25 }
}
```

Unknown properties are rejected. Raw SQL, arbitrary SELECT parameters, client-supplied controller names, and internal query-builder keys are not part of the public contract.

### Controlled SQL resource request

A SQL file under the discovery root is addressed by its relative path without
the `.sql` suffix. For example, `queries/reports/item.sql` is:

```json
{"action":"sql","resource":"reports/item"}
```

Runtime controls use validated execution metadata:

```json
{
  "action": "sql",
  "resource": "reports/item",
  "execution": {
    "columns": ["Item_Code", "Item_Desc", "Item_MRP"],
    "defaultSort": [
      { "field": "Item_Code", "direction": "ASC" }
    ]
  },
  "filters": [
    { "field": "Item_Desc", "operator": "LIKE", "value": "%pen%" }
  ],
  "sort": [
    { "field": "Item_Code", "direction": "DESC" }
  ],
  "pagination": { "page": 1, "pageSize": 25 },
  "filterLogic": "AND"
}
```

The backend recursively discovers `.sql` resources beneath the fixed
server root and excludes `queries/system` by default. Logical IDs contain safe
slash-separated segments; extensions, absolute paths, `..\`, backslashes, null
bytes, directories, non-SQL files, and escaped real paths are rejected. The
client never supplies a filesystem path or SQL text.

`execution.columns` declares stable output aliases used to validate outer
filters and sorting. It is unnecessary when a simple resource is executed without
runtime controls. The backend deliberately does not parse arbitrary SQL Server
projections. `execution.defaultSort` requires columns and is used when runtime
`sort` is absent. Pagination requires an approved runtime/default sort or an
authored top-level ORDER BY.

`execution.filters` adds logical mappings. Output mappings must resolve to an
execution column. A `source` mapping may omit its expression so the backend can
resolve a unique physical column from the approved SQL and database metadata;
explicit expressions remain limited to identifiers such as `BIL.Bill_Date`.
`having` expressions are limited to COUNT/SUM/AVG/MIN/MAX over one identifier
or `*`. Runtime `date`/`daterange` types activate physical date normalization;
the legacy custom value type is `integer-date`.
Placement, expressions, field names, types, operators, and directions are
validated; values remain prepared parameters.

`config/sql-resources.php` contains only global discovery settings. A unique
basename preserves short IDs such as `item` and `customer`, but the relative ID
is preferred and required when basenames are ambiguous.

See [SQL Resource Mode](SQL-Resource-Mode.md) and
[SQL Resource authoring](SQL-Resource-Authoring.md).


### CRUD write requests

Writes name their target in `table` (`Table` or `Schema.Table`) and, optionally,
its one database in `database` (default: the default database). Any user table
of a registered database can be written by a caller holding `data.write` and an
unrestricted [access scope](Access-Scopes.md); there is no per-table write
registration. System schemas and cross-database names are
rejected, and the table and its columns are validated against live metadata.

```json
{
  "action": "insert",
  "table": "Customers",
  "data": { "customerCode": "C001", "name": "John", "email": null }
}
```

```json
{
  "action": "update",
  "table": "Customers",
  "data": { "email": "new@example.com" },
  "filters": [{ "field": "id", "operator": "=", "value": 10 }]
}
```

```json
{
  "action": "delete",
  "table": "Customers",
  "filters": [{ "field": "id", "operator": "=", "value": 10 }]
}
```

```json
{
  "action": "upsert",
  "table": "Customers",
  "data": { "customerCode": "C001", "name": "John" },
  "keys": ["customerCode"]
}
```

Each request changes one input object; bulk writes are not implemented. INSERT
and UPSERT require every non-nullable, non-generated column that lacks a default.
Identity, computed, timestamp, and rowversion columns cannot be supplied.
UPDATE and DELETE require a non-empty, valid `filters` array and never fall back
to a full-table statement. Write filters support comparisons, LIKE, IN, BETWEEN,
and NULL operators, but not subqueries or EXISTS. Columns, types, nullability,
lengths, defaults, and generated status are checked against SQL Server metadata.
All data, key, and filter values are prepared parameters.

UPSERT `keys` is required, must exactly match the table's primary key or an
unfiltered unique index, and each key value must be present and non-null. The implementation is one SQL Server `MERGE` with
`HOLDLOCK`; a matching unfiltered UNIQUE/PRIMARY KEY index is verified from live
metadata. It does not
open a transaction, and SQL Server MERGE-specific operational caveats still
apply. See [Write API](Write-API.md).

## Success response

All controllers use the same envelope:

```json
{
  "success": true,
  "message": "Data Loaded Successfully",
  "data": [{ "ItemCode": "A001", "ItemName": "Example" }],
  "meta": {
    "requestId": "7f4dd403d84c99e1",
    "page": 1,
    "pageSize": 25,
    "totalRows": 37,
    "rowsReturned": 1,
    "executionTime": 2.41
  }
}
```

See [Response reference](Response-Reference.md) for exact per-action messages and
write response presence rules.

- `data` is always an array.
- `page` and `pageSize` copy the public pagination request, or are `null`.
- Paginated SELECT and SQL-resource requests normally obtain `totalRows` with a separate count query. A complete first-page `TOP` resource can infer it from `rowsReturned`; without pagination it also defaults to `rowsReturned`.
- `executionTime` is elapsed database execution time in milliseconds, rounded to two decimals, or `null` if the underlying result did not supply it.
- `rowsReturned` counts rows collected across the executed result.
- Write responses keep `rowsReturned: 0`, add `meta.affectedRows`, and put one
  operation summary in `data`. INSERT and an inserting UPSERT also include
  `generatedId` when the resource declares a verified identity column.
- Query results do not include a separate column-schema/column-metadata property. The `metadata.columns` action returns column rows as ordinary `data`.
- SELECT/UNION messages are `Data Loaded Successfully`; routine actions use their corresponding executed-successfully message; metadata actions use their loaded-successfully message.
- Write messages are `Data Inserted Successfully`, `Data Updated Successfully`,
  `Data Deleted Successfully`, and `Data Upserted Successfully`.

A successful INSERT with a configured identity is represented as:

```json
{
  "success": true,
  "message": "Data Inserted Successfully",
  "data": [{ "operation": "insert", "affectedRows": 1, "generatedId": 42 }],
  "meta": {
    "requestId": "7f4dd403d84c99e1",
    "page": null,
    "pageSize": null,
    "totalRows": 0,
    "rowsReturned": 0,
    "executionTime": 1.27,
    "affectedRows": 1
  }
}
```

## Error responses

The complete code, status, and handling table is in
[Errors and validation](Errors-and-Validation.md).

Malformed JSON is HTTP 400:

```json
{
  "success": false,
  "message": "Invalid JSON request.",
  "error": { "code": "INVALID_JSON", "details": [] },
  "data": [],
  "meta": { "requestId": "7f4dd403d84c99e1" }
}
```

Contract validation failures are HTTP 400 and include one or more path/message details:

```json
{
  "success": false,
  "message": "Invalid request.",
  "error": {
    "code": "INVALID_REQUEST",
    "details": [
      { "path": "pagination.page", "message": "Must be a positive integer." }
    ]
  },
  "data": [],
  "meta": { "requestId": "7f4dd403d84c99e1" }
}
```

Unhandled builder, metadata, connection, or execution failures are HTTP 500:

```json
{
  "success": false,
  "message": "Query execution failed.",
  "error": { "code": "QUERY_ERROR", "details": [] },
  "data": [],
  "meta": { "requestId": "7f4dd403d84c99e1" }
}
```

The response does not expose the underlying exception. The exception handler writes details to the dated file in `logs/`.

Write validation additionally uses `INVALID_WRITE_TABLE`,
`INVALID_WRITE_COLUMN`, `INVALID_WRITE_VALUE`, `MISSING_REQUIRED_FIELD`,
`INVALID_UPSERT_KEY`, and `UNSAFE_WRITE`, all as HTTP 400. Recognized duplicate
key and other constraint failures are safe HTTP 409 responses with
`DUPLICATE_KEY` or `CONSTRAINT_VIOLATION`. Other database failures remain the
generic HTTP 500 `QUERY_ERROR`; no SQL Server message is returned.

## Pagination and ordering

`pagination` requires a positive integer `page`. `pageSize` is a positive integer when supplied; omission uses the configured default (25 initially), and values above the configured maximum (1,000 initially) are rejected rather than clamped. SQL Server compatibility level 110+ uses `OFFSET/FETCH`; older compatibility levels use a `ROW_NUMBER()` wrapper. The backend normally runs a count query before the page query; the complete-first-page SQL-resource `TOP` optimization described above is the exception. SQL resources with authored OFFSET/FETCH run directly without runtime controls; combining authored and request pagination is rejected explicitly.

Public sorting uses validated logical fields or a selected alias and `ASC`/`DESC`; numeric positions such as `"1"` are rejected. Window functions likewise require a logical sort field. This prevents invalid SQL Server output such as `ROW_NUMBER() OVER (ORDER BY 1)`. If top-level `sort` is omitted, the builder supplies an order based on the first usable projection (or table metadata when needed); grouped requests default to the first group field.

## Capability and limitation references

The authoritative cross-mode comparison is [Capability matrix](Capability-Matrix.md).
Use [Current limitations](Limitations.md) for intentional public boundaries and
[Query function reference](Query-Functions.md) for the exact usable function set,
including the internal-only `TIMEFROMPARTS` mismatch.

The shared source validator accepts `source.alias` for routines and
`metadata.columns`, but normalization ignores it; clients should omit it.
Routine `parameters` should be a JSON list because placeholders are positional.
