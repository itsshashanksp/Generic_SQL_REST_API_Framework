# Public action reference

This is the index of every public `action` accepted by the Universal JSON
Contract. Requests are JSON objects sent to the deployed `api/index.php` entry
script with `Content-Type: application/json`. Unknown properties are rejected.

Common response fields are defined once in [Response reference](Response-Reference.md).
Unless a section says otherwise, request-shape failures are HTTP 400
`INVALID_REQUEST` and execution failures are HTTP 500 `QUERY_ERROR`.

Every action is authenticated according to the configured API mode and
authorized by the caller's role permissions before validation, then limited by
the caller's [access scopes](Access-Scopes.md) before any query runs. See
[Authentication and authorization](Authentication-and-Authorization.md). Session
and frontend-user actions are in that document, and Admin actions are in
[Admin Console](Admin-Console.md).

## `select`

### Purpose

Build and execute a validated SQL Server SELECT from public JSON Query Mode.

### Request

Required: `action`, `source`, `fields`. Optional: `filters`, `joins`, `groupBy`,
`having`, `sort`, `pagination`, `distinct`, `limit`, `filterLogic`, `with`.

### Minimal example

```json
{"action":"select","source":{"table":"Items"},"fields":["ItemCode","Description"]}
```

### Full example

```json
{
  "action": "select",
  "source": { "table": "Items", "alias": "I" },
  "fields": [
    "I.ItemCode",
    { "field": "I.Description", "alias": "ItemName" },
    { "function": "COUNT", "field": "*", "alias": "RowCount" }
  ],
  "filters": [{ "field": "I.Status", "operator": "=", "value": "Active" }],
  "groupBy": ["I.ItemCode", "I.Description"],
  "having": [{ "function": "COUNT", "field": "*", "operator": ">", "value": 0 }],
  "sort": [{ "field": "ItemName", "direction": "ASC" }],
  "pagination": { "page": 1, "pageSize": 25 },
  "filterLogic": "AND"
}
```

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `source` | object | yes | `table` and optional `alias`. |
| `fields` | non-empty array | yes | Identifiers or validated field/function/expression objects. |
| `filters`, `joins`, `having`, `sort` | arrays | no | Validated query clauses. |
| `groupBy` | identifier array | no | Grouping fields. |
| `pagination` | object | no | Positive integer `page` and `pageSize`. |
| `distinct` | boolean | no | Adds DISTINCT; default false. |
| `limit` | positive integer | no | Adds TOP. |
| `filterLogic` | string | no | `AND` or `OR`; default AND. |
| `with` | object | no | One standard or recursive CTE. |

### Validation

Identifiers and expression shapes are allowlisted, and physical tables/columns
are checked against live metadata. See [JSON request reference](JSON-Request-Reference.md).

### Response

Returns selected rows in `data`, message `Data Loaded Successfully`, and query
metadata. Pagination supplies a total count.

### Errors

Invalid shapes/identifiers use `INVALID_REQUEST`; metadata, generation, or SQL
failures use the non-disclosing `QUERY_ERROR`.

### Notes

This is structured query composition, not arbitrary SQL. See [JSON Query Mode](Query-Mode.md).

## `sql`

### Purpose

Discover and execute a server-owned, read-only SQL Resource.

### Request

Required: `action`, `resource`. Optional: `execution`, `filters`, `sort`,
`pagination`, `filterLogic`, and `database`.

### Minimal example

```json
{"action":"sql","resource":"reports/item"}
```

### Full example

```json
{
  "action": "sql",
  "resource": "reports/customer",
  "execution": {
    "columns": [
      "Cust_Name",
      "TotalCustomers",
      "MinimumBill",
      "MaximumBill"
    ],
    "filters": {
      "StDate": {
        "expression": "StDate",
        "placement": "source",
        "valueType": "integer-date"
      },
      "MinimumCustomers": {
        "expression": "COUNT(*)",
        "placement": "having"
      }
    },
    "defaultSort": [
      { "field": "Cust_Name", "direction": "ASC" }
    ]
  },
  "filters": [
    {
      "field": "StDate",
      "operator": "BETWEEN",
      "value": ["2021-04-01", "2022-03-31"]
    },
    {
      "field": "MinimumCustomers",
      "operator": ">=",
      "value": 2
    }
  ],
  "pagination": { "page": 1, "pageSize": 25 },
  "filterLogic": "AND"
}
```

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `resource` | string | yes | Safe relative SQL-file ID without `.sql`. |
| `execution` | object | no | Validated output/filter/default-sort metadata. |
| `execution.columns` | non-empty identifier array | conditional | Output aliases used by runtime output filtering/sorting. |
| `execution.filters` | object | no | Logical mappings with `expression`, `placement`, and optional `valueType`. |
| `execution.defaultSort` | non-empty sort array | no | Default ordering; requires `execution.columns`. |
| `filters` | array | no | Runtime values for permitted output/mapped fields. |
| `sort` | array | no | Runtime order using execution output columns. |
| `pagination` | object | no | Positive page/pageSize; requires an approved sort. |
| `filterLogic` | string | no | AND or OR; default AND. |
| `database` | database id | no | Database for a resource without `{{database:id}}` placeholders; default: the default database. Rejected for resources with placeholders. |

### Validation

The resolver confines discovery to the configured root, excludes internal
directories, accepts only slash-separated logical IDs, and resolves only SQL
files. Execution expressions use a narrow identifier/aggregate grammar. Output
fields, operators, values, directions, placement, and integer dates are checked.

### Response

Returns resource rows with `Data Loaded Successfully` in the standard query envelope.

### Errors

In addition to common errors: `INVALID_SQL_RESOURCE`,
`INVALID_SQL_RUNTIME_FIELD`, `INVALID_SQL_RUNTIME_VALUE`,
`INVALID_SQL_RUNTIME_FILTER`, and `INVALID_SQL_PAGINATION` (HTTP 400).

### Notes

A new file such as `queries/reports/new-report.sql` works as
`reports/new-report` without registration. The backend does not parse general
SQL projections; supply `execution.columns` when runtime controls need
output-name validation. Clients never send SQL text or filesystem paths. See
[SQL Resource Mode](SQL-Resource-Mode.md).


## `insert`

### Purpose

Insert one row into a table.

### Request

Required fields are `action`, `table`, and `data`; optional `database`.

### Minimal example

```json
{"action":"insert","table":"Customers","data":{"CustomerCode":"C001","Name":"John"}}
```

### Full example

```json
{
  "action": "insert",
  "table": "dbo.Customers",
  "data": {
    "CustomerCode": "C001",
    "Name": "John",
    "Email": "john@example.com",
    "Age": 30,
    "Status": "Active"
  }
}
```

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `table` | string | yes | `Table` or `Schema.Table` of a user table. |
| `database` | database id | no | The one target database; default: the default database. |
| `data` | non-empty object | yes | One scalar/null value per column; database-generated columns are rejected. |

### Validation

The table must exist in the target database (`database`, else the default database). Live metadata enforces column
existence, types, lengths, nullability, required fields, defaults, and
generated-column protection.

### Response

`data` contains `operation: "insert"` and `affectedRows`; `generatedId` is
conditional as described in [Response reference](Response-Reference.md).

### Errors

Write-specific validation and conflict codes are documented in
[Write API](Write-API.md).

### Notes

Bulk inserts and client-selected return columns are unsupported.

## `update`

### Purpose

Update one or more matching rows of a table.

### Request

Required fields are `action`, `table`, `data`, and non-empty `filters`;
`filterLogic` and `database` are optional.

### Minimal example

```json
{"action":"update","table":"Customers","data":{"Status":"Inactive"},"filters":[{"field":"Id","operator":"=","value":42}]}
```

### Full example

```json
{
  "action": "update",
  "table": "Customers",
  "data": { "Email": "new@example.com", "Status": "Active" },
  "filters": [
    { "field": "CustomerCode", "operator": "=", "value": "C001" },
    { "field": "Status", "operator": "!=", "value": "Deleted" }
  ],
  "filterLogic": "AND"
}
```

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `table` | string | yes | `Table` or `Schema.Table` of a user table. |
| `database` | database id | no | The one target database; default: the default database. |
| `data` | non-empty object | yes | Columns to change; database-generated columns are rejected. |
| `filters` | non-empty array | yes | Conditions on any existing column. |
| `filterLogic` | string | no | AND or OR; default AND. |

### Validation

The table, columns, and value types must be valid against live metadata.
Missing/empty targeting is HTTP 400 `UNSAFE_WRITE`.

### Response

Success returns operation `update` and the actual `affectedRows`.

### Errors

See [Write API](Write-API.md) for write validation and conflict codes.

### Notes

No full-table UPDATE fallback exists; filters may legitimately match multiple rows.

## `delete`

### Purpose

Delete matching rows of a table.

### Request

Required fields are `action`, `table`, and non-empty `filters`; `filterLogic`
and `database` are optional. `data` is not accepted.

### Minimal example

```json
{"action":"delete","table":"Customers","filters":[{"field":"Id","operator":"=","value":42}]}
```

### Full example

```json
{
  "action": "delete",
  "table": "Customers",
  "filters": [
    { "field": "Status", "operator": "=", "value": "Inactive" },
    { "field": "Age", "operator": ">=", "value": 18 }
  ],
  "filterLogic": "AND"
}
```

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `table` | string | yes | `Table` or `Schema.Table` of a user table. |
| `database` | database id | no | The one target database; default: the default database. |
| `filters` | non-empty array | yes | Required targeting conditions. |
| `filterLogic` | string | no | AND or OR; default AND. |

### Validation

The table, filter columns, and values must be valid against live metadata.
Missing/empty targeting is `UNSAFE_WRITE`.

### Response

Success returns operation `delete` and `affectedRows`.

### Errors

See [Write API](Write-API.md) for write validation and conflict codes.

### Notes

No full-table DELETE fallback exists; filters may match multiple rows.

## `upsert`

### Purpose

Update an existing row or insert a new row, matched by a unique key.

### Request

Required fields are `action`, `table`, `data`, and `keys`; `database` is
optional, and no filters or other properties are accepted.

### Minimal example

```json
{"action":"upsert","table":"Customers","data":{"CustomerCode":"C001","Name":"John"},"keys":["CustomerCode"]}
```

### Full example

```json
{
  "action": "upsert",
  "table": "Customers",
  "data": {
    "CustomerCode": "C001",
    "Name": "John",
    "Email": "john@example.com",
    "Age": 30,
    "Status": "Active"
  },
  "keys": ["CustomerCode"]
}
```

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `table` | string | yes | `Table` or `Schema.Table` of a user table. |
| `database` | database id | no | The one target database; default: the default database. |
| `data` | non-empty object | yes | Includes every key and at least one non-key value. |
| `keys` | non-empty string list | yes | Must exactly match the table's primary key or an unfiltered unique index. |

### Validation

Keys must be unique existing columns, present and non-null in `data`, and
exactly match a PRIMARY KEY or unfiltered UNIQUE index discovered from live
metadata.

### Response

Returns operation `upsert` and affected count. An inserting path includes the
table's identity value as `generatedId` when the table has an identity column.

### Errors

Key failures use `INVALID_UPSERT_KEY`; see [Write API](Write-API.md) for other write codes.

### Notes

The implementation is one SQL Server `MERGE ... WITH (HOLDLOCK)` statement; it
does not open an API transaction and retains SQL Server MERGE operational
considerations.

## `union` and `unionAll`

### Purpose

Combine JSON Query Mode SELECT bodies with UNION (deduplicating) or UNION ALL.

### Request

Both actions accept only `action` and required non-empty `queries`.

### Minimal example

```json
{"action":"union","queries":[{"source":{"table":"Items"},"fields":["ItemCode"]}]}
```

### Full example

```json
{
  "action": "unionAll",
  "queries": [
    { "source": { "table": "Items" }, "fields": ["ItemCode", "Description"] },
    { "source": { "table": "ArchivedItems" }, "fields": ["ItemCode", "Description"] }
  ]
}
```

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `queries` | non-empty array | yes | SELECT bodies without `action`. |

### Validation

Branches must have compatible projection counts. Branch actions, sort,
pagination, and CTEs are rejected.

### Response

Results use `Data Loaded Successfully` and the standard query envelope.

### Errors

Invalid branch shape/count uses `INVALID_REQUEST`; execution uses `QUERY_ERROR`.

### Notes

There is no top-level sorting/pagination and no public INTERSECT/EXCEPT. See
[JSON Query Mode](Query-Mode.md#set-operations).

## `procedure`

### Purpose

Execute a named SQL Server stored procedure with positional parameters.

### Request

Required: `action`, `source.procedure`. Optional: `parameters` and `database`.

### Minimal example

```json
{"action":"procedure","source":{"procedure":"dbo.RefreshReport"}}
```

### Full example

```json
{"action":"procedure","source":{"procedure":"dbo.RunReport"},"parameters":[2026,true]}
```

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `source.procedure` | identifier | yes | Procedure name, optionally schema-qualified. |
| `parameters` | array | no | Positional values; default empty. Use a JSON list. |
| `database` | database id | no | Database whose routine runs; default: the default database. |

### Validation

The name must be `Name` or `Schema.Name`, outside the `sys` and
`INFORMATION_SCHEMA` schemas, and must not start with `sp_` or `xp_`. It must
name an existing user stored procedure of the selected database (`database`, else the default database). At most the
declared number of parameters may be sent (trailing parameters use their
defaults). Values are always prepared.

Requires `routine.execute` and `data.write`, because a procedure can change
data; session callers must also send `X-CSRF-Token`.

### Response

Returned result rows use `Procedure Executed Successfully`.

### Errors

Invalid shape is `INVALID_REQUEST`; an unsafe, unknown, or wrong-type name is
`INVALID_ROUTINE`; too many parameters is `INVALID_ROUTINE_PARAMETERS`; database
failures are `QUERY_ERROR`.

### Notes

There is no routine registration. The API does not support named or output
parameters.

## `function`

### Purpose

Execute a SQL Server scalar function as `SELECT function(...) AS Result`.

### Request

Required: `action`, `source.function`. Optional: `parameters` and `database`.

### Minimal example

```json
{"action":"function","source":{"function":"dbo.CurrentScore"}}
```

### Full example

```json
{"action":"function","source":{"function":"dbo.Score"},"parameters":[42]}
```

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `source.function` | identifier | yes | Function name, optionally schema-qualified. |
| `parameters` | array | no | Positional prepared values; default empty. |
| `database` | database id | no | Database whose routine runs; default: the default database. |

### Validation

The name follows the same rules as `procedure` and must name an existing user
scalar function. Exactly the declared number of parameters is required. Values
are always prepared. Requires `routine.execute`; functions cannot modify data,
so no CSRF token is needed.

### Response

Returns a row with database column `Result` and message
`Function Executed Successfully`.

### Errors

Invalid shape is `INVALID_REQUEST`; an unsafe, unknown, or wrong-type name is
`INVALID_ROUTINE`; a wrong parameter count is `INVALID_ROUTINE_PARAMETERS`;
database failures are `QUERY_ERROR`.

### Notes

See [Metadata and routines](Metadata-and-Routines.md#routines).

## `tableFunction`

### Purpose

Execute a table-valued SQL Server function as `SELECT * FROM function(...)`.

### Request

Required: `action`, `source.function`. Optional: `parameters` and `database`.

### Minimal example

```json
{"action":"tableFunction","source":{"function":"dbo.ActiveRows"}}
```

### Full example

```json
{"action":"tableFunction","source":{"function":"dbo.RowsForYear"},"parameters":[2026]}
```

### Parameters

The fields are the same as `function`: required `source.function`, optional
positional `parameters` list, and optional `database`.

### Validation

The name must name an existing user table-valued function; otherwise the rules
are the same as `function`.

### Response

Returns function rows with `Table Function Executed Successfully`.

### Errors

As for `function`.

### Notes

No runtime filter, sort, or pagination fields are accepted. See
[Metadata and routines](Metadata-and-Routines.md).

## `metadata.tables`

### Purpose

List SQL Server base-table names.

### Request

`action` and an optional `database`.

### Minimal example

```json
{"action":"metadata.tables"}
```

### Full example

The full request is identical to the minimal request; there are no options.

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `action` | string | yes | Exactly `metadata.tables`. |
| `database` | database id | no | Registered database to read; default: the default database. |

### Validation

Any additional property is rejected.

### Response

Rows are shaped as `{"TABLE_NAME":"Items"}`; message:
`Tables Loaded Successfully`.

### Errors

Invalid shape is `INVALID_REQUEST`; database failure is `QUERY_ERROR`.

### Notes

Only `INFORMATION_SCHEMA.TABLES` rows with type `BASE TABLE` are returned.

## `metadata.columns`

### Purpose

List a named table's columns in ordinal order.

### Request

Required: `action` and `source.table`. Optional: `database` and `source.schema`.

### Minimal example

```json
{"action":"metadata.columns","source":{"table":"Items"}}
```

### Full example

The full request is identical; there are no output-selection options.

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `source.table` | identifier | yes | Table name bound to the metadata query. |
| `source.schema` | identifier | no | Schema of the table; one non-system identifier. Without it, columns of every table with that name are listed, as before. |
| `database` | database id | no | Registered database to read; default: the default database. |

### Validation

The source must have a valid table identifier. `source.alias` technically
passes shared validation but is discarded and should be omitted.

### Response

Rows contain `COLUMN_NAME`, `DATA_TYPE`, and `IS_NULLABLE`; message:
`Columns Loaded Successfully`.

### Errors

Invalid/missing source is `INVALID_REQUEST`; database failure is
`QUERY_ERROR`.

### Notes

This describes a physical table, not an arbitrary query result.

## `metadata.views`

### Purpose

List SQL Server view names.

### Request

`action` and an optional `database`.

### Minimal example

```json
{"action":"metadata.views"}
```

### Full example

The full request is identical.

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `action` | string | yes | Exactly `metadata.views`. |
| `database` | database id | no | Registered database to read; default: the default database. |

### Validation

Any additional property is rejected.

### Response

Rows contain `TABLE_NAME`; message: `Views Loaded Successfully`.

### Errors

Invalid shape is `INVALID_REQUEST`; database failure is `QUERY_ERROR`.

### Notes

View definitions and columns are not returned.

## `metadata.procedures`

### Purpose

List SQL Server stored-procedure names.

### Request

`action` and an optional `database`.

### Minimal example

```json
{"action":"metadata.procedures"}
```

### Full example

The full request is identical.

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `action` | string | yes | Exactly `metadata.procedures`. |
| `database` | database id | no | Registered database to read; default: the default database. |

### Validation

Any additional property is rejected.

### Response

Rows contain `ROUTINE_NAME`; message:
`Stored Procedures Loaded Successfully`.

### Errors

Invalid shape is `INVALID_REQUEST`; database failure is `QUERY_ERROR`.

### Notes

Signatures and parameters are not returned.

## `metadata.schema`

### Purpose

List all table/column/type/ordinal rows.

### Request

`action` and an optional `database`.

### Minimal example

```json
{"action":"metadata.schema"}
```

### Full example

The full request is identical.

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `action` | string | yes | Exactly `metadata.schema`. |
| `database` | database id | no | Registered database to read; default: the default database. |

### Validation

Any additional property is rejected.

### Response

Rows contain `TABLE_NAME`, `COLUMN_NAME`, `DATA_TYPE`, and
`ORDINAL_POSITION`; message: `Schema Loaded Successfully`.

### Errors

Invalid shape is `INVALID_REQUEST`; database failure is `QUERY_ERROR`.

### Notes

The result is a flat ordered row list, not a nested schema document. Metadata
actions require `metadata.read` (or frontend access) and list the user objects of
the selected database (`database`, else the default database) that its login
can see. See [Metadata and routines](Metadata-and-Routines.md).

## `metadata.databases`

### Purpose

List the logical databases clients can name in `database`.

### Request

Only `action` is accepted.

### Minimal example

```json
{"action":"metadata.databases"}
```

### Full example

The full request is identical.

### Parameters

| Field | Type | Required | Description |
|---|---|---:|---|
| `action` | string | yes | Exactly `metadata.databases`. |

### Validation

Any additional property is rejected, including `database` and any connection
setting.

### Response

Rows contain `id`, `name`, `default`, `enabled`, `available`, and
`crossDatabaseGroup`; message: `Databases Loaded Successfully`.

### Errors

Invalid shape is `INVALID_REQUEST`; an unreadable registry is
`DATABASE_CONFIGURATION_ERROR`.

### Notes

Answered from the registry without a SQL Server connection, so it also works
while a database is disconnected. See
[Metadata and routines](Metadata-and-Routines.md#databases).
