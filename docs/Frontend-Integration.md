# Frontend integration guide

The backend accepts one Universal JSON request and returns one stable envelope.
A frontend report model is application-owned; translate it into an accepted API
action rather than sending the report model unchanged.

```text
report/widget configuration
  -> choose JSON Query Mode or SQL Resource Mode
  -> merge validated UI filter/sort/page state
  -> POST /api/index.php
  -> render response.data
  -> update pagination from response.meta
  -> map error.details paths to UI fields
```

## HTTP client

```js
async function callBackend(request) {
  const response = await fetch('/api/index.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(request)
  });

  const payload = await response.json();
  if (!response.ok || !payload.success) {
    const error = new Error(payload.message || 'Backend request failed');
    error.status = response.status;
    error.code = payload.error?.code;
    error.details = payload.error?.details ?? [];
    throw error;
  }
  return payload;
}
```

Set the URL for the hosting layout: `/api/index.php` assumes the repository root
is served, while the bundled `-t api` launcher exposes the same entry script at
`/index.php`.

Do not assume a non-2xx response has `meta`. On success, `data` is always an
array. Use `meta.page`, `meta.pageSize`, and `meta.totalRows` for page controls;
`rowsReturned` is only the size of this response.

## Dashboard JSON Query report

A Dashboard definition keeps request structure at the top-level `request` key:

```json
{
  "id": "active-customers",
  "title": "Active customers",
  "request": {
    "action": "select",
    "source": { "table": "Customers", "alias": "C" },
    "fields": ["C.CustomerCode", "C.Name", "C.Status"]
  },
  "columns": [
    { "field": "CustomerCode", "header": "Customer" },
    { "field": "Name", "header": "Name" },
    { "field": "Status", "header": "Status" }
  ]
}
```

`id`, `title`, columns, labels, and presentation are not API properties.
Dashboard merges applied UI state into `request` and sends:

```json
{
  "action": "select",
  "source": { "table": "Customers", "alias": "C" },
  "fields": ["C.CustomerCode", "C.Name", "C.Status"],
  "filters": [
    { "field": "C.Status", "operator": "=", "value": "Active" },
    { "field": "C.Name", "operator": "LIKE", "value": "%john%" }
  ],
  "filterLogic": "AND",
  "sort": [{ "field": "C.Name", "direction": "ASC" }],
  "pagination": { "page": 1, "pageSize": 50 }
}
```

The backend validates the structure and metadata, generates prepared SQL, and
returns selected rows. Use this mode when frontend configuration legitimately
selects from the public JSON function/join/filter vocabulary.

## Dashboard SQL Resource report

Dashboard configuration stores a path-derived resource ID and top-level
presentation fields. It does not author backend `execution` metadata directly:

```json
{
  "id": "customer-summary",
  "title": "Customer summary",
  "queryDefinition": {
    "format": "sql",
    "resource": "catalog/customer-summary"
  },
  "columns": [
    { "field": "CustomerId", "header": "Customer ID", "dataType": "number" },
    { "field": "CustomerName", "header": "Customer" },
    { "field": "OrderCount", "header": "Orders", "dataType": "number" },
    { "field": "TotalAmount", "header": "Total", "dataType": "currency" },
    { "field": "AverageAmount", "header": "Average", "dataType": "currency" }
  ],
  "filters": [
    { "field": "CustomerName", "label": "Customer", "type": "text", "operator": "startsWith" }
  ],
  "sort": [{ "field": "TotalAmount", "direction": "DESC" }]
}
```

Translate it and the UI date/name controls to:

```json
{
  "action": "sql",
  "resource": "catalog/customer-summary",
  "execution": {
    "columns": ["CustomerId", "CustomerName", "OrderCount", "TotalAmount", "AverageAmount"]
  },
  "filters": [
    { "field": "CustomerName", "operator": "LIKE", "value": "A%" }
  ],
  "sort": [{ "field": "TotalAmount", "direction": "DESC" }],
  "pagination": { "page": 1, "pageSize": 25 },
  "filterLogic": "AND"
}
```

Dashboard derives the constrained `execution.columns` list from display columns
and adds a source placement when a configured filter is not one of those output
columns. Framework resolves `catalog/customer-summary`, validates the execution
grammar and logical fields, inserts prepared filters at the approved stage, and
runs server-owned SQL.
`queryDefinition` itself is not an API property: Dashboard translates the
reviewed configuration into the direct request shown above. Complex expressions,
HAVING mappings, coercions such as integer-date conversion, and resource default
sorting remain backend-owned. A resource with no UI controls can omit
`execution` entirely.

The canonical Dashboard configuration shapes are documented in its
[query-mode guide](https://github.com/sigmasoftwaresolutions/Dashboard/blob/main/docs/QUERY-MODES.md).

## Write clients

Dashboard currently has `frontendCapabilities.write: false` and does not expose
this workflow. Another authorized client can submit one object for the target
table:

```json
{
  "action": "update",
  "table": "Customers",
  "data": { "Email": "new@example.com", "Status": "Active" },
  "filters": [{ "field": "CustomerCode", "operator": "=", "value": "C001" }]
}
```

On success, use `meta.affectedRows` (or the operation summary's `affectedRows`)
to decide whether the target changed. INSERT and an inserting UPSERT may include
`data[0].generatedId`; code must tolerate its absence. Treat 409 duplicate/
constraint errors differently from 400 form/contract errors.

Never allow an UPDATE or DELETE UI to emit an empty filter list. The backend
rejects it, but preventing the invalid request gives a clearer user experience.

## Metadata-driven builders

Use `metadata.tables`, `metadata.columns`, `metadata.views`,
`metadata.procedures`, and `metadata.schema` only when exposing database catalog
choices is appropriate. Metadata lists only registered query sources and
routines, and a metadata row does not grant permission to write that object. `metadata.columns` returns physical table column type/null
information, not the output schema of an arbitrary report.

## Ownership boundary

Frontend should control:

- report title, labels, formatting, chart/table choice, and layout;
- selected approved values and UI filter/operator controls;
- current page/page size and approved sort state;
- choosing among backend-published resource IDs and reviewed action definitions;
- validation display and retry/user feedback.

Backend controls:

- SQL Resource SQL and discovery root/exclusions;
- allowed actions, columns, filters, keys, identity fields, and filter mappings;
- SQL expressions, WHERE/HAVING/output placement, joins, grouping, and resource
  default ordering;
- database credentials, encryption keys, driver, connection, permissions, logs,
  validation, SQL generation, and execution.

Frontend must never send:

- database credentials, encryption keys, connection strings, or driver options;
- arbitrary SQL, SQL fragments, resource file paths, URLs, or filesystem paths;
- arbitrary SQL Resource filter expressions or placement values;
- private normalized keys such as `controller`, `table`, `columns`, `column`,
  `where`, `top`, `page`, `pageSize`, or `params`;
- client-selected schema/table names for Write actions;
- SQL expressions as Write values or arbitrary modifications to resource SQL.

## Error handling

- 400: do not retry unchanged. Map `details[].path` to controls. A missing backend
  resource/field usually needs deployment/configuration rather than user input.
- 409: refresh state and explain duplicate/constraint conflict.
- 500: show a generic failure and provide the server request correlation context;
  do not expect SQL Server details in the response.
- 504: the query timed out; offer a controlled retry and investigate server-side.

The endpoint enforces its configured session/API-key authentication mode.
Browser session clients must obtain `auth.csrf`, retain cookies, and send the
token for state-changing operations. CORS is not authentication; production
deployments still require HTTPS and appropriate network controls.

Next: [Action reference](Action-Reference.md),
[JSON request reference](JSON-Request-Reference.md), and
[Response reference](Response-Reference.md).
