<?php

require_once __DIR__ . '/../../core/QueryEngine.php';
require_once __DIR__ . '/../Resources/SqlResourceRegistry.php';
require_once __DIR__ . '/Query/PaginationBuilder.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Resources/SqlResourceStatement.php';
require_once __DIR__ . '/MetadataRepository.php';
require_once __DIR__ . '/Query/DatabaseDateValueNormalizer.php';
require_once __DIR__ . '/../Database/DatabaseQueryPlanContext.php';
require_once __DIR__ . '/../Database/MssqlIdentifier.php';
require_once __DIR__ . '/../Security/DatabaseCredentialException.php';

class SqlRepository
{
    private QueryEngine $queryEngine;
    private SqlResourceRegistry $registry;
    private PaginationBuilder $paginationBuilder;
    private Logger $logger;
    private MetadataRepository $metadataRepository;

    public function __construct(
        ?QueryEngine $queryEngine = null,
        ?SqlResourceRegistry $registry = null,
        ?Logger $logger = null,
        ?MetadataRepository $metadataRepository = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->queryEngine = $queryEngine ?? new QueryEngine(null, $this->logger);
        $this->registry = $registry ?? new SqlResourceRegistry();
        $this->paginationBuilder = new PaginationBuilder($this->queryEngine);
        $this->metadataRepository = $metadataRepository ?? new MetadataRepository($this->queryEngine);
    }

    public function execute(array $request): array
    {
        $generationStarted = microtime(true);
        $definition = $this->registry->resolve(
            $request['resource'],
            $request['execution'] ?? []
        );
        $sql = trim($this->queryEngine->getQuery($definition['file']));
        $sql = rtrim($sql, "; \t\n\r\0\x0B");
        $statement = $this->withDatabases(SqlResourceStatement::analyze($sql));
        $allowedColumns = [];
        foreach ($definition['columns'] as $column) {
            $allowedColumns[strtolower($column)] = $column;
        }
        $allowedFilters = $definition['filters'];
        $filtersByLocation = ['output' => [], 'source' => [], 'cte' => [], 'where' => [], 'having' => []];
        foreach ($request['filters'] ?? [] as $filter) {
            $filterDefinition = $this->requireAllowedFilterColumn($filter['field'], $allowedFilters);
            $semanticDate = in_array($filter['type'] ?? null, ['date', 'daterange'], true);
            $resolvedFromSource = false;
            if (($filterDefinition['resolveSource'] ?? false) || $semanticDate) {
                $source = $this->resolveSourceColumn(
                    $statement,
                    $filter['field'],
                    !($filterDefinition['resolveSource'] ?? false)
                );
                if ($source === null && ($filterDefinition['resolveSource'] ?? false)) {
                    throw new ApiRequestException(
                        'SQL runtime source field could not be resolved.',
                        'INVALID_SQL_RUNTIME_FIELD',
                        [['path' => 'filter', 'message' => "Source field is unavailable or ambiguous: {$filter['field']}"]]
                    );
                }
                if ($source !== null) {
                    $filterDefinition = array_replace($filterDefinition, [
                        'expression' => $source['expression'],
                        // A source reached through the main SELECT's CTE is
                        // filtered inside that CTE (SqlResourceStatement::sourceCandidates).
                        'location' => $source['cte'] === null ? 'where' : 'cte',
                        'mappedExpression' => true,
                        'physicalDataType' => $source['dataType'],
                    ]);
                    $allowedFilters[strtolower($filter['field'])] = $filterDefinition;
                    $resolvedFromSource = true;
                }
            }
            if ($filterDefinition['mappedExpression'] && !$resolvedFromSource) {
                $this->assertMappedExpressionSource($statement, $filterDefinition['expression']);
            }
            $filtersByLocation[$filterDefinition['location']][] = $filter;
        }
        $usedLocations = array_keys(array_filter($filtersByLocation));
        if (strtoupper((string)($request['filterLogic'] ?? 'AND')) === 'OR'
            && count($usedLocations) > 1) {
            throw new ApiRequestException(
                'Runtime filters with OR must use one SQL location.',
                'INVALID_SQL_RUNTIME_FILTER',
                [['path' => 'filterLogic', 'message' => 'OR cannot span output, CTE, WHERE, and HAVING locations.']]
            );
        }
        [$cteWhereSql, $cteParams] = $this->buildWhere(
            $filtersByLocation['cte'],
            $request['filterLogic'] ?? 'AND',
            $allowedFilters,
            null,
            false
        );
        [$mappedWhereSql, $whereParams] = $this->buildWhere(
            $filtersByLocation['where'],
            $request['filterLogic'] ?? 'AND',
            $allowedFilters,
            null,
            false
        );
        [$mappedHavingSql, $havingParams] = $this->buildWhere(
            $filtersByLocation['having'],
            $request['filterLogic'] ?? 'AND',
            $allowedFilters,
            null,
            false
        );
        [$whereSql, $outputParams] = $this->buildWhere(
            $filtersByLocation['output'],
            $request['filterLogic'] ?? 'AND',
            $allowedFilters
        );
        // The prefix (with its CTEs) precedes the main SELECT in every query.
        $params = array_merge($cteParams, $whereParams, $havingParams, $outputParams);
        $queryPrefix = $statement->prefixWithSourceFilter($cteWhereSql === '' ? null : $cteWhereSql);
        $sql = $statement->injectMappedFilters(
            $mappedWhereSql === '' ? null : $mappedWhereSql,
            $mappedHavingSql === '' ? null : $mappedHavingSql
        );
        $querySuffix = $statement->suffix();
        if ($statement->hasAuthoredPagination()
            && (!empty($request['filters']) || !empty($request['sort']) || isset($request['pagination']))) {
            throw new ApiRequestException(
                'Runtime query controls cannot be combined with authored SQL pagination.',
                'INVALID_SQL_PAGINATION',
                [['path' => 'pagination', 'message' => 'This resource owns its OFFSET/FETCH pagination.']]
            );
        }
        $sort = !empty($request['sort'])
            ? $request['sort']
            : $definition['defaultSort'];
        $orderSql = $this->buildOrderBy($sort, $allowedColumns);
        $topLevelOrderBy = $this->findTopLevelOrderBy($sql);
        if (isset($request['pagination']) && $sort === [] && $topLevelOrderBy === null) {
            throw new ApiRequestException(
                'SQL resource pagination requires an approved sort.',
                'INVALID_SQL_PAGINATION',
                [['path' => 'execution.defaultSort', 'message' => 'Supply an approved runtime or default sort.']]
            );
        }
        $topLimit = $this->getTopLimit($sql);
        // With TOP, the authored ORDER BY decides which rows are kept, so it
        // must stay inside every wrapper (count and ROW_NUMBER pagination).
        $countResourceSql = $topLevelOrderBy === null || $topLimit !== null
            ? $sql
            : rtrim(substr($sql, 0, $topLevelOrderBy));
        $countBaseSql = $whereSql === ''
            ? $countResourceSql
            : "SELECT * FROM (\n{$countResourceSql}\n) AS SqlResource{$whereSql}";
        $topFitsRequestedPage = $topLimit !== null
            && isset($request['pagination'])
            && $request['pagination']['page'] === 1
            && $request['pagination']['pageSize'] >= $topLimit;
        $canPageAuthoredSqlDirectly = $topLevelOrderBy !== null
            && empty($request['filters'])
            && empty($request['sort'])
            && ($topLimit === null || !isset($request['pagination']) || $topFitsRequestedPage);
        $canInferTotalRowsFromData = $canPageAuthoredSqlDirectly && $topFitsRequestedPage;

        if ($canPageAuthoredSqlDirectly) {
            $orderedSql = $sql;
        } else {
            $dataBaseSql = "SELECT * FROM (\n{$countResourceSql}\n) AS SqlResource{$whereSql}";
            $orderedSql = $dataBaseSql . $orderSql;
        }
        $paginationOrderSql = $this->buildOrderBy($sort, $allowedColumns, 'PagedSource');

        $paginationRequest = [];
        if (isset($request['pagination']) && !$canInferTotalRowsFromData) {
            $paginationRequest = [
                'page' => $request['pagination']['page'],
                'pageSize' => $request['pagination']['pageSize'],
            ];
        }
        $this->logger->timing('sql_generation', (microtime(true) - $generationStarted) * 1000, [
            'action' => 'sql',
            'resource' => $request['resource'],
            'page' => $request['pagination']['page'] ?? null,
            'pageSize' => $request['pagination']['pageSize'] ?? null,
        ]);
        $paged = $this->paginationBuilder->apply(
            $orderedSql,
            $countBaseSql,
            $params,
            $paginationRequest,
            $paginationOrderSql !== '' ? trim($paginationOrderSql) : null,
            !($canPageAuthoredSqlDirectly && $topFitsRequestedPage),
            $queryPrefix,
            $querySuffix
        );
        $result = $this->queryEngine->executePrepared($queryPrefix . $paged['sql'], $params, [
            'action' => 'sql',
            'resource' => $request['resource'],
            'queryPhase' => 'data',
            'page' => $request['pagination']['page'] ?? null,
            'pageSize' => $request['pagination']['pageSize'] ?? null,
        ]);
        if ($paged['totalRows'] !== null) {
            $result['totalRows'] = $paged['totalRows'];
        } elseif ($canInferTotalRowsFromData) {
            $result['totalRows'] = $result['rowsReturned'];
        }
        return $result;
    }

    private function getTopLimit(string $sql): ?int
    {
        if (preg_match(
            '/^SELECT\s+(?:DISTINCT\s+)?TOP\s*(?:\(\s*([0-9]+)\s*\)|([0-9]+))/i',
            $sql,
            $matches
        ) !== 1) {
            return null;
        }

        return (int)(($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? ''));
    }

    private function findTopLevelOrderBy(string $sql): ?int
    {
        $length = strlen($sql);
        $depth = 0;
        $previousToken = null;
        $previousTokenStart = null;
        $orderByStart = null;

        for ($index = 0; $index < $length;) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($character === "'") {
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] !== "'") continue;
                    if ($index + 1 < $length && $sql[$index + 1] === "'") {
                        $index++;
                        continue;
                    }
                    $index++;
                    break;
                }
                continue;
            }
            if ($character === '"') {
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] !== '"') continue;
                    if ($index + 1 < $length && $sql[$index + 1] === '"') {
                        $index++;
                        continue;
                    }
                    $index++;
                    break;
                }
                continue;
            }
            if ($character === '[') {
                for ($index++; $index < $length; $index++) {
                    if ($sql[$index] !== ']') continue;
                    if ($index + 1 < $length && $sql[$index + 1] === ']') {
                        $index++;
                        continue;
                    }
                    $index++;
                    break;
                }
                continue;
            }
            if ($character === '-' && $next === '-') {
                $newline = strpos($sql, "\n", $index + 2);
                $index = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($character === '/' && $next === '*') {
                $commentEnd = strpos($sql, '*/', $index + 2);
                $index = $commentEnd === false ? $length : $commentEnd + 2;
                continue;
            }
            if ($character === '(') {
                $depth++;
                $index++;
                continue;
            }
            if ($character === ')') {
                $depth = max(0, $depth - 1);
                $index++;
                continue;
            }
            if ($depth === 0 && preg_match('/[A-Za-z_]/', $character) === 1) {
                $tokenStart = $index;
                while ($index < $length
                    && preg_match('/[A-Za-z0-9_]/', $sql[$index]) === 1) {
                    $index++;
                }
                $token = strtoupper(substr($sql, $tokenStart, $index - $tokenStart));
                if ($previousToken === 'ORDER' && $token === 'BY') {
                    $orderByStart = $previousTokenStart;
                }
                $previousToken = $token;
                $previousTokenStart = $tokenStart;
                continue;
            }

            $index++;
        }

        return $orderByStart;
    }

    private function buildWhere(
        array $filters,
        string $logic,
        array $allowedColumns,
        ?string $qualifier = 'SqlResource',
        bool $includeKeyword = true
    ): array
    {
        if ($filters === []) return ['', []];
        $logic = strtoupper($logic);
        $conditions = [];
        $params = [];

        foreach ($filters as $filter) {
            $filterField = $this->requireAllowedFilterColumn($filter['field'], $allowedColumns);
            $operator = strtoupper($filter['operator']);
            if ($filterField['mappedExpression']) {
                $column = $qualifier === null
                    ? '(' . $filterField['expression'] . ')'
                    : $qualifier . '.[' . $filterField['expression'] . ']';
            } else {
                $column = ($qualifier === null ? '' : $qualifier . '.')
                    . '[' . $filterField['expression'] . ']';
            }
            if (in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
                $conditions[] = "{$column} {$operator}";
            } elseif (in_array($operator, ['IN', 'NOT IN'], true)) {
                $values = $this->normalizeFilterValues($filter['value'], $filterField, $filter['type'] ?? null);
                $conditions[] = "{$column} {$operator} ("
                    . implode(', ', array_fill(0, count($values), '?')) . ')';
                array_push($params, ...$values);
            } elseif (in_array($operator, ['BETWEEN', 'NOT BETWEEN'], true)) {
                $values = $this->normalizeFilterValues($filter['value'], $filterField, $filter['type'] ?? null);
                $conditions[] = "{$column} {$operator} ? AND ?";
                array_push($params, ...$values);
            } else {
                $conditions[] = "{$column} {$operator} ?";
                $params[] = $this->normalizeFilterValue($filter['value'], $filterField, $filter['type'] ?? null);
            }
        }

        return [($includeKeyword ? ' WHERE ' : '') . implode(" {$logic} ", $conditions), $params];
    }

    private function buildOrderBy(
        array $sort,
        array $allowedColumns,
        string $qualifier = 'SqlResource'
    ): string
    {
        if ($sort === []) return '';
        $items = [];
        foreach ($sort as $entry) {
            $field = $this->requireAllowedColumn($entry['field'], $allowedColumns, 'sort');
            $direction = strtoupper($entry['direction'] ?? 'ASC');
            $items[] = $qualifier . '.[' . $field . '] ' . $direction;
        }
        return ' ORDER BY ' . implode(', ', $items);
    }

    private function requireAllowedColumn(string $field, array $allowedColumns, string $usage): string
    {
        $canonicalField = $allowedColumns[strtolower($field)] ?? null;
        if ($canonicalField === null) {
            throw new ApiRequestException(
                'Invalid SQL runtime field.',
                'INVALID_SQL_RUNTIME_FIELD',
                [['path' => $usage, 'message' => "Field is not exposed by this SQL resource: {$field}"]]
            );
        }
        return $canonicalField;
    }

    private function requireAllowedFilterColumn(string $field, array $allowedColumns): array
    {
        $definition = $allowedColumns[strtolower($field)] ?? null;
        if ($definition === null) {
            throw new ApiRequestException(
                'Invalid SQL runtime field.',
                'INVALID_SQL_RUNTIME_FIELD',
                [['path' => 'filter', 'message' => "Field is not exposed by this SQL resource: {$field}"]]
            );
        }
        return $definition;
    }

    private function normalizeFilterValues(array $values, array $definition, ?string $semanticType): array
    {
        return array_map(
            fn ($value) => $this->normalizeFilterValue($value, $definition, $semanticType),
            $values
        );
    }

    private function normalizeFilterValue($value, array $definition, ?string $semanticType)
    {
        if (in_array($semanticType, ['date', 'daterange'], true)) {
            try {
                return DatabaseDateValueNormalizer::normalizeSemanticValue(
                    $value,
                    $definition['physicalDataType'] ?? null
                );
            } catch (InvalidArgumentException $exception) {
                $this->rejectInvalidIntegerDate();
            }
        }

        $valueType = $definition['valueType'] ?? null;
        if ($valueType !== 'integer-date' || $value === null) {
            return $value;
        }

        $date = is_int($value) ? (string)$value : $value;
        if (!is_string($date)) {
            $this->rejectInvalidIntegerDate();
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts) === 1) {
            $date = $parts[1] . $parts[2] . $parts[3];
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $parts) !== 1
            || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            $this->rejectInvalidIntegerDate();
        }
        return (int)$date;
    }

    /**
     * Render `{{database:id}}` placeholders as the delimited physical names
     * of the request plan's databases, which the planner resolved from the
     * same placeholders; the registry is the only source of the names.
     */
    private function withDatabases(SqlResourceStatement $statement): SqlResourceStatement
    {
        if ($statement->databaseIds() === []) return $statement;
        $plan = DatabaseQueryPlanContext::current();
        if ($plan === null) throw new LogicException('SQL resources with database placeholders require a database query plan.');
        return $statement->withDatabases(static function (string $id) use ($plan): string {
            $database = $plan->database($id) ?? throw new LogicException('A SQL resource database is not part of the query plan.');
            try {
                return MssqlIdentifier::database($database->physicalName())->quoted();
            } catch (InvalidArgumentException $exception) {
                throw new DatabaseCredentialException('Invalid database configuration.');
            }
        });
    }

    private function resolveSourceColumn(
        SqlResourceStatement $statement,
        string $field,
        bool $requireDirectProjection
    ): ?array
    {
        // Columns are confirmed through the connected database's catalog only,
        // so resources that address other databases do not resolve sources.
        if ($statement->databaseIds() !== []) return null;
        $matches = [];
        $candidates = $statement->sourceCandidates($field, $requireDirectProjection);
        foreach ($candidates as $candidate) {
            // Metadata describes only the connected database, so a column of a
            // database-qualified source cannot be confirmed or placed.
            if ($candidate['database'] !== null) return null;
        }
        foreach ($candidates as $candidate) {
            if (!$this->metadataRepository->columnExists($candidate['table'], $candidate['column'])) {
                continue;
            }
            $candidate['dataType'] = $this->metadataRepository->getColumnDataType(
                $candidate['table'],
                $candidate['column']
            );
            $matches[] = $candidate;
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Client-supplied source/HAVING mappings must reference a top-level source
     * of the authored statement; a qualifier that names no top-level source
     * (for example a derived-table alias) cannot be placed safely.
     */
    private function assertMappedExpressionSource(SqlResourceStatement $statement, string $expression): void
    {
        $identifier = preg_match('/\(\s*([^()]*?)\s*\)$/', $expression, $aggregate) === 1
            ? $aggregate[1]
            : $expression;
        if ($identifier === '*') return;
        $parts = explode('.', $identifier);
        $sources = $statement->topLevelSources();
        if (count($parts) > 1) {
            $qualifier = strtolower($parts[count($parts) - 2]);
            $sources = array_filter($sources, fn (array $source): bool => strtolower($source['qualifier']) === $qualifier);
        }
        if ($sources === []) {
            throw new ApiRequestException(
                'Invalid SQL runtime filter.',
                'INVALID_SQL_RUNTIME_FILTER',
                [['path' => 'execution.filters', 'message' => 'Mapped filter must reference a top-level source of the resource.']]
            );
        }
    }

    private function rejectInvalidIntegerDate(): void
    {
        throw new ApiRequestException(
            'Invalid SQL runtime value.',
            'INVALID_SQL_RUNTIME_VALUE',
            [['path' => 'filter.value', 'message' => 'Expected a valid YYYY-MM-DD or YYYYMMDD date.']]
        );
    }
}
