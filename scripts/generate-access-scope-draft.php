<?php

/**
 * Prints a DRAFT `frontend` access scope (log-only) derived from a Generic
 * Reporting Framework configuration directory: the tables its structured
 * requests read with the columns they use, and the SQL Resources it runs with
 * the filter fields it sends.
 *
 * Usage: php scripts/generate-access-scope-draft.php <frontend src/config dir> [default-database-id]
 *
 * The draft JSON goes to stdout. A review list goes to stderr: hidden filter
 * columns (filterable but not displayed), and tables granted "*" because a
 * joined request's columns could not be attributed. Review every entry before
 * use — it reflects frontend configuration, not what the business approves.
 */

$directory = $argv[1] ?? null;
$defaultDatabase = $argv[2] ?? null;
if ($directory === null || !is_dir($directory)) {
    fwrite(STDERR, "Usage: php scripts/generate-access-scope-draft.php <frontend src/config dir> [default-database-id]\n");
    exit(2);
}
if ($defaultDatabase === null) {
    require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
    try {
        $defaultDatabase = (new DatabaseContextResolver())->defaultDatabaseId();
    } catch (Throwable $exception) {
        $defaultDatabase = null;
    }
}
if (!is_string($defaultDatabase) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $defaultDatabase) !== 1) {
    fwrite(STDERR, "A default logical database id is required (argument 2) when the registry default is unavailable.\n");
    exit(2);
}

$databases = [];
$actions = [];
$review = [];

/** Column identifiers a request node references (unqualified), and the aliases it defines. */
$references = function (mixed $node, array &$columns, array &$aliases) use (&$references): void {
    if (is_string($node)) {
        if ($node !== '*') $columns[] = substr($node, (int)strrpos('.' . $node, '.'));
        return;
    }
    if (!is_array($node)) return;
    foreach ($node as $key => $value) {
        if ($key === 'alias' && is_string($value)) { $aliases[] = $value; continue; }
        if (in_array($key, ['literal', 'value', 'values', 'query', 'database', 'schema', 'table', 'source', 'direction', 'operator', 'function', 'datatype', 'type'], true)) continue;
        if (in_array($key, ['field', 'fields', 'partitionBy', 'groupBy'], true) || is_int($key)) {
            $references($value, $columns, $aliases);
        } elseif (is_array($value)) {
            $references($value, $columns, $aliases);
        }
    }
};
$sourceName = fn (array $source): string => (is_string($source['schema'] ?? null) ? $source['schema'] . '.' : '') . $source['table'];
$grant = function (string $database, string $table, array|string $columns) use (&$databases): void {
    $existing = $databases[$database]['tables'][$table] ?? [];
    if ($columns === '*' || $existing === '*') {
        $databases[$database]['tables'][$table] = '*';
        return;
    }
    $merged = array_values(array_unique(array_merge($existing, $columns)));
    sort($merged);
    $databases[$database]['tables'][$table] = $merged;
};

/**
 * @param list<string> $runtimeFilters fields the frontend filters this request by at runtime
 * @param list<string> $displayed fields the frontend displays (columns)
 */
$addSelect = function (array $request, string $database, array $runtimeFilters, array $displayed, string $origin)
    use (&$actions, &$review, &$addSelect, $references, $sourceName, $grant): void {
    $actions['select'] = true;
    $database = is_string($request['database'] ?? null) ? $request['database'] : $database;
    foreach ($request['filters'] ?? [] as $filter) {
        if (is_array($filter['query'] ?? null)) $addSelect($filter['query'], $database, [], [], $origin . ' (subquery)');
    }
    if (is_array($request['with']['query'] ?? null)) $addSelect($request['with']['query'], $database, [], [], $origin . ' (CTE)');
    $source = $request['source'] ?? null;
    if (!is_array($source) || !is_string($source['table'] ?? null)) return;
    $sourceDatabase = is_string($source['database'] ?? null) ? $source['database'] : $database;
    if (!empty($request['joins'])) {
        // Unqualified columns of a joined request cannot be attributed to one table.
        $grant($sourceDatabase, $sourceName($source), '*');
        foreach ($request['joins'] as $join) {
            if (!is_array($join['source'] ?? null) || !is_string($join['source']['table'] ?? null)) continue;
            $joinDatabase = is_string($join['source']['database'] ?? null) ? $join['source']['database'] : $database;
            $grant($joinDatabase, $sourceName($join['source']), '*');
            $review[] = "{$origin}: joined table {$joinDatabase}:{$sourceName($join['source'])} granted \"*\"; restrict its columns manually.";
        }
        $review[] = "{$origin}: joined table {$sourceDatabase}:{$sourceName($source)} granted \"*\"; restrict its columns manually.";
        return;
    }
    $columns = [];
    $aliases = [];
    $references($request['fields'] ?? [], $columns, $aliases);
    $projected = $columns;
    $references($request['groupBy'] ?? [], $columns, $aliases);
    foreach (array_merge($request['filters'] ?? [], $request['having'] ?? []) as $filter) {
        if (is_array($filter)) $references(['field' => $filter['field'] ?? null], $columns, $aliases);
    }
    foreach (array_merge($request['sort'] ?? [], array_map(fn ($field) => ['field' => $field], $displayed)) as $sort) {
        if (is_array($sort) && is_string($sort['field'] ?? null)) $references($sort['field'], $columns, $aliases);
    }
    foreach ($runtimeFilters as $field) $references($field, $columns, $aliases);
    $columns = array_values(array_diff(array_unique($columns), $aliases));
    $grant($sourceDatabase, $sourceName($source), $columns);
    $hidden = array_values(array_diff($runtimeFilters, $projected, $displayed, $aliases));
    foreach ($hidden as $field) {
        $review[] = "{$origin}: hidden filter column {$sourceDatabase}:{$sourceName($source)}.{$field} (filterable, not displayed).";
    }
};

$fieldsOf = fn (array $items): array => array_values(array_unique(array_filter(array_map(
    fn ($item) => is_array($item) && is_string($item['field'] ?? null) ? $item['field'] : null,
    $items
))));
$addFilterOptions = function (array $filters, string $origin) use ($addSelect, $defaultDatabase): void {
    foreach ($filters as $index => $filter) {
        if (is_array($filter['dynamicOptions']['request'] ?? null)) {
            $addSelect($filter['dynamicOptions']['request'], $defaultDatabase, [], [], "{$origin} filter {$index} options");
        }
    }
};
$addDefinition = function (array $definition, array $sharedFilters, string $origin)
    use (&$databases, &$actions, &$review, $addSelect, $addFilterOptions, $fieldsOf, $defaultDatabase): void {
    $filters = $fieldsOf(array_merge($definition['filters'] ?? [], $sharedFilters));
    $displayed = $fieldsOf($definition['columns'] ?? []);
    $addFilterOptions($definition['filters'] ?? [], $origin);
    if (is_array($definition['request'] ?? null)) $addSelect($definition['request'], $defaultDatabase, $filters, $displayed, $origin);
    if (is_array($definition['queryDefinition'] ?? null) && is_string($definition['queryDefinition']['resource'] ?? null)) {
        $actions['sql'] = true;
        $database = $definition['queryDefinition']['database'] ?? $defaultDatabase;
        $resource = $definition['queryDefinition']['resource'];
        $existing = $databases[$database]['resources'][$resource]['filters'] ?? [];
        $merged = array_values(array_unique(array_merge($existing, $filters)));
        sort($merged);
        $databases[$database]['resources'][$resource] = ['filters' => $merged];
        // The frontend maps filters that are not output columns to the
        // resource's source tables (execution.filters placement "source").
        $output = array_map('strtolower', $displayed);
        foreach ($filters as $field) {
            if (!in_array(strtolower($field), $output, true)) {
                $review[] = "{$origin}: hidden filter column {$database}:{$resource} → {$field} (source placement, not an output column).";
            }
        }
    }
};

$read = fn (string $file) => json_decode((string)file_get_contents($file), true);
$base = rtrim($directory, '/\\');
foreach (['reports', 'widgets'] as $kind) {
    foreach (glob("{$base}/{$kind}/*.json") ?: [] as $file) {
        $definition = $read($file);
        if (is_array($definition)) $addDefinition($definition, [], "{$kind}/" . basename($file));
    }
}
foreach (glob("{$base}/dashboards/*.json") ?: [] as $file) {
    $dashboard = $read($file);
    if (!is_array($dashboard)) continue;
    $origin = 'dashboards/' . basename($file);
    $shared = $dashboard['filters'] ?? [];
    $addFilterOptions($shared, $origin);
    foreach ($dashboard['widgets'] ?? [] as $index => $widget) {
        if (is_array($widget)) $addDefinition($widget, $shared, "{$origin} widget " . (is_string($widget['id'] ?? null) ? $widget['id'] : $index));
    }
}

ksort($databases);
foreach ($databases as &$database) {
    if (isset($database['tables'])) ksort($database['tables']);
    if (isset($database['resources'])) ksort($database['resources']);
}
unset($database);

$order = ['select', 'union', 'unionAll', 'sql', 'metadata'];
$draft = [
    'version' => 1,
    'mode' => 'log-only',
    'scopes' => [
        'frontend' => [
            'actions' => array_values(array_filter($order, fn (string $action): bool => isset($actions[$action]))),
            'databases' => $databases,
        ],
    ],
];
echo json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
$review = array_values(array_unique($review));
fwrite(STDERR, "DRAFT ONLY — review before use. " . count($review) . " item(s) need explicit review:\n");
foreach ($review as $item) fwrite(STDERR, "  - {$item}\n");
