<?php


require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/SqlRepository.php';
require_once __DIR__ . '/../app/Controllers/SQLController.php';

function sqlAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function expectSqlRequestInvalid(QueryRequestValidator $validator, array $request): void
{
    try {
        $validator->validate($request);
    } catch (ApiRequestException $exception) {
        sqlAssert($exception->getErrorCode() === 'INVALID_REQUEST', 'Wrong SQL validation error code.');
        return;
    }
    throw new RuntimeException('Invalid SQL request was accepted.');
}

class SqlTestEngine extends QueryEngine
{
    public array $executions = [];
    public array $resultData = [['Item_Code' => 'A1']];
    public function __construct() {}
    public function executePrepared($sql, array $params = [], array $context = [])
    {
        $this->executions[] = ['sql' => $sql, 'params' => $params, 'context' => $context];
        if (str_contains($sql, 'COUNT(*) AS TotalRows')) return ['data' => [['TotalRows' => 7]]];
        if (str_contains($sql, 'compatibility_level')) return ['data' => [['CompatibilityLevel' => 150]]];
        return ['executionTime' => 0.5, 'rowsReturned' => count($this->resultData), 'data' => $this->resultData];
    }
}

class SqlTestRepository extends SqlRepository
{
    public function __construct() {}
    public function execute(array $request): array
    {
        return ['executionTime' => 0.1, 'rowsReturned' => 1, 'data' => [['ok' => 1]]];
    }
}

class SqlTestService extends SqlService
{
    public function __construct() {}
    public function execute(array $request): array
    {
        return (new SqlTestRepository())->execute($request);
    }
}

class SqlTestController extends SQLController
{
    public array $captured = [];
    protected function success($data = [], $message = 'Success', $code = 200)
    {
        $this->captured = compact('data', 'message', 'code');
    }
}

$validator = new QueryRequestValidator();
$normalizer = new QueryRequestNormalizer();
$itemExecution = ['execution' => [
    'columns' => ['Item_Code', 'Item_Desc', 'Item_MRP'],
    'defaultSort' => [['field' => 'Item_Code', 'direction' => 'ASC']],
]];
$customerExecutionMetadata = ['execution' => [
    'columns' => ['Cust_Name', 'TotalCustomers', 'MinimumBill', 'MaximumBill'],
    'filters' => [
        'Cust_Name' => ['expression' => 'Cust_Name', 'placement' => 'source'],
        'StDate' => ['expression' => 'StDate', 'placement' => 'source', 'valueType' => 'integer-date'],
    ],
    'defaultSort' => [['field' => 'Cust_Name', 'direction' => 'ASC']],
]];
$statsExecutionMetadata = ['execution' => [
    'columns' => ['TotalItems', 'MinimumSP', 'MaximumSP', 'StockValue'],
    'filters' => [
        'Item_Desc' => ['expression' => 'Item_Desc', 'placement' => 'source'],
        'Std_Vat' => ['expression' => 'Std_Vat', 'placement' => 'source'],
    ],
]];
$tableExecution = ['execution' => [
    'columns' => ['Item_Code', 'Item_Desc', 'Sale_Rate', 'Item_MRP', 'Std_Vat', 'cl_stock', 'stock_value'],
    'defaultSort' => [['field' => 'Item_Code', 'direction' => 'ASC']],
]];
$publicRequest = [
    'action' => 'sql',
    'resource' => 'reports/item',
    ...$itemExecution,
    'filters' => [['field' => 'Item_Desc', 'operator' => 'LIKE', 'value' => "%O'Brien%"]],
    'sort' => [['field' => 'Item_Code', 'direction' => 'DESC']],
    'pagination' => ['page' => 2, 'pageSize' => 25],
];
$validator->validate($publicRequest);
$normalized = $normalizer->normalize($publicRequest);
sqlAssert($normalized['controller'] === 'SQL' && $normalized['action'] === 'execute', 'SQLController dispatch failed.');
sqlAssert($normalized['resource'] === 'reports/item', 'SQL resource normalization failed.');
sqlAssert($normalized['execution'] === $publicRequest['execution'], 'SQL execution metadata normalization failed.');

$semanticDateRequest = [
    'action' => 'sql',
    'resource' => 'reports/customer',
    'execution' => [
        'columns' => ['Cust_Name'],
        'filters' => ['StDate' => ['placement' => 'source']],
    ],
    'filters' => [[
        'field' => 'StDate',
        'operator' => 'BETWEEN',
        'value' => ['2026-01-01', '2026-12-31'],
        'type' => 'daterange',
    ]],
];
$validator->validate($semanticDateRequest);
sqlAssert(
    $normalizer->normalize($semanticDateRequest)['filters'][0]['type'] === 'daterange',
    'SQL semantic filter type was dropped during normalization.'
);

expectSqlRequestInvalid($validator, ['action' => 'sql', 'resource' => '../../secret']);
expectSqlRequestInvalid($validator, ['action' => 'sql', 'resource' => 'item', 'sql' => 'SELECT * FROM Users']);
expectSqlRequestInvalid($validator, ['action' => 'sql', 'resource' => 'item', 'sort' => [['field' => 'Id', 'direction' => 'DROP']]]);
expectSqlRequestInvalid($validator, ['action' => 'sql', 'resource' => 'item', 'filters' => [[
    'field' => 'Item_Desc]; DROP TABLE Users;--', 'operator' => '=', 'value' => 'x'
]]]);
expectSqlRequestInvalid($validator, ['action' => 'sql', 'resource' => 'item', 'filters' => [[
    'field' => 'Item_Desc', 'operator' => '=', 'value' => 'x', 'type' => 'integer-date'
]]]);

$registry = new SqlResourceRegistry();
sqlAssert(
    $registry->resolve('reports/item')['file'] === realpath(QUERY_PATH . '/reports/item.sql'),
    'Discovered Item resource was not resolved.'
);
try {
    $registry->resolve('not-approved');
    throw new RuntimeException('Unknown SQL resource was accepted.');
} catch (ApiRequestException $exception) {
    sqlAssert($exception->getErrorCode() === 'INVALID_SQL_RESOURCE', 'Wrong resource error code.');
}

$engine = new SqlTestEngine();
$repository = new SqlRepository($engine, $registry);
$result = $repository->execute($normalized);
sqlAssert($result['totalRows'] === 7, 'SQL pagination total was not preserved.');
$execution = end($engine->executions);
sqlAssert(str_contains($execution['sql'], 'FROM ('), 'Approved SQL was not wrapped as a resource query.');
sqlAssert(str_contains($execution['sql'], 'SqlResource.[Item_Desc] LIKE ?'), 'Runtime filter was not applied.');
sqlAssert(str_contains($execution['sql'], 'SqlResource.[Item_Code] DESC'), 'Runtime sort was not applied.');
sqlAssert(str_contains($execution['sql'], 'OFFSET 25 ROWS'), 'Runtime pagination was not applied.');
sqlAssert($execution['params'] === ["%O'Brien%"], 'Runtime filter value was not parameterized.');
sqlAssert(!str_contains($execution['sql'], "O'Brien"), 'Runtime value leaked into SQL text.');
sqlAssert($execution['context']['queryPhase'] === 'data', 'Main query phase was not identified.');
$countExecution = array_values(array_filter(
    $engine->executions,
    fn (array $entry): bool => str_contains($entry['sql'], 'COUNT(*) AS TotalRows')
))[0];
sqlAssert($countExecution['context']['queryPhase'] === 'pagination_count', 'Count query phase was not identified.');
sqlAssert($countExecution['params'] === $execution['params'], 'Count and data query parameters diverged.');

$sqlOperatorCases = [
    ['=', 10, '[Item_MRP] = ?', [10]],
    ['<>', 10, '[Item_MRP] <> ?', [10]],
    ['>', 10, '[Item_MRP] > ?', [10]],
    ['<', 10, '[Item_MRP] < ?', [10]],
    ['>=', 10, '[Item_MRP] >= ?', [10]],
    ['<=', 10, '[Item_MRP] <= ?', [10]],
    ['LIKE', '%ABC%', '[Item_Desc] LIKE ?', ['%ABC%']],
    ['NOT LIKE', '%ABC%', '[Item_Desc] NOT LIKE ?', ['%ABC%']],
    ['IN', [10, 20], '[Item_MRP] IN (?, ?)', [10, 20]],
    ['NOT IN', [10, 20], '[Item_MRP] NOT IN (?, ?)', [10, 20]],
    ['BETWEEN', [10, 20], '[Item_MRP] BETWEEN ? AND ?', [10, 20]],
    ['NOT BETWEEN', [10, 20], '[Item_MRP] NOT BETWEEN ? AND ?', [10, 20]],
    ['IS NULL', null, '[Item_Desc] IS NULL', []],
    ['IS NOT NULL', null, '[Item_Desc] IS NOT NULL', []],
];
foreach ($sqlOperatorCases as [$operator, $value, $fragment, $expectedParams]) {
    $operatorEngine = new SqlTestEngine();
    $filter = [
        'field' => str_contains($fragment, 'Item_Desc') ? 'Item_Desc' : 'Item_MRP',
        'operator' => $operator,
    ];
    if (!in_array($operator, ['IS NULL', 'IS NOT NULL'], true)) {
        $filter['value'] = $value;
    }
    (new SqlRepository($operatorEngine, $registry))->execute($normalizer->normalize([
        'action' => 'sql',
        'resource' => 'item',
        ...$itemExecution,
        'filters' => [$filter],
    ]));
    $operatorExecution = end($operatorEngine->executions);
    sqlAssert(str_contains($operatorExecution['sql'], $fragment), "SQL {$operator} filter was not rendered.");
    sqlAssert($operatorExecution['params'] === $expectedParams, "SQL {$operator} parameters changed.");
}

$emptySortRequest = $normalizer->normalize([
    'action' => 'sql',
    'resource' => 'item',
    ...$itemExecution,
    'filters' => [],
    'sort' => [],
    'pagination' => ['page' => 1, 'pageSize' => 25],
]);
$emptySortEngine = new SqlTestEngine();
$emptySortRepository = new SqlRepository($emptySortEngine, $registry);
$emptySortRepository->execute($emptySortRequest);
$emptySortExecution = end($emptySortEngine->executions);
sqlAssert(
    str_contains($emptySortExecution['sql'], 'ORDER BY SqlResource.[Item_Code] ASC'),
    'An empty runtime sort did not use the registered resource default sort.'
);
sqlAssert(
    strpos($emptySortExecution['sql'], 'ORDER BY') < strpos($emptySortExecution['sql'], 'OFFSET 0 ROWS'),
    'SQL Server pagination was generated without ORDER BY before OFFSET.'
);
sqlAssert(
    str_contains($emptySortExecution['sql'], 'FETCH NEXT 25 ROWS ONLY'),
    'Empty-sort regression request did not retain pagination.'
);

$groupedRequest = $normalizer->normalize([
    'action' => 'sql',
    'resource' => 'bill-category-sales-month-wise',
    'filters' => [],
    'sort' => [],
    'pagination' => ['page' => 1, 'pageSize' => 100],
]);
$groupedEngine = new SqlTestEngine();
$groupedRepository = new SqlRepository($groupedEngine, $registry);
$groupedResult = $groupedRepository->execute($groupedRequest);
$groupedCountExecutions = array_values(array_filter(
    $groupedEngine->executions,
    fn (array $entry): bool => str_contains($entry['sql'], 'COUNT(*) AS TotalRows')
));
$groupedCountSql = $groupedCountExecutions[0]['sql'];
$groupedDataExecution = end($groupedEngine->executions);
sqlAssert(
    str_contains($groupedCountSql, 'GROUP BY') && str_contains($groupedCountSql, 'HAVING'),
    'Grouped count source lost GROUP BY or HAVING.'
);
sqlAssert(
    !str_contains($groupedCountSql, 'ORDER BY Month, Category'),
    'Authored ORDER BY leaked into the grouped count query.'
);
sqlAssert(
    !str_contains($groupedCountSql, ') AS SqlResource'),
    'Unfiltered grouped count retained a redundant resource wrapper.'
);
sqlAssert(
    str_contains($groupedDataExecution['sql'], 'ORDER BY Month, Category'),
    'Authored ORDER BY was not preserved in the data query.'
);
sqlAssert(
    !str_contains($groupedDataExecution['sql'], ') AS SqlResource')
        && substr_count($groupedDataExecution['sql'], 'OFFSET 0 ROWS') === 1,
    'Grouped authored SQL was not paginated directly at its top level.'
);
sqlAssert($groupedResult['rowsReturned'] === 1, 'Grouped SQL-mode data result was lost.');
sqlAssert($groupedResult['totalRows'] === 7, 'Grouped SQL-mode count result was lost.');

foreach (['bill-sales-month-wise', 'bill-purchases-month-wise'] as $orderedResource) {
    $orderedEngine = new SqlTestEngine();
    (new SqlRepository($orderedEngine, $registry))->execute($normalizer->normalize([
        'action' => 'sql',
        'resource' => $orderedResource,
        'filters' => [],
        'sort' => [],
        'pagination' => ['page' => 1, 'pageSize' => 12],
    ]));
    $orderedCountExecutions = array_values(array_filter(
        $orderedEngine->executions,
        fn (array $entry): bool => str_contains($entry['sql'], 'COUNT(*) AS TotalRows')
    ));
    sqlAssert(
        !str_contains($orderedCountExecutions[0]['sql'], 'ORDER BY'),
        "Authored ordering leaked into the {$orderedResource} count query."
    );
    $orderedDataExecution = end($orderedEngine->executions);
    sqlAssert(
        str_contains($orderedDataExecution['sql'], "ORDER BY")
            && !str_contains($orderedDataExecution['sql'], ') AS SqlResource')
            && substr_count($orderedDataExecution['sql'], 'OFFSET 0 ROWS') === 1,
        "{$orderedResource} was not paginated directly after its authored ordering."
    );
}

$topRequest = $normalizer->normalize([
    'action' => 'sql',
    'resource' => 'bill-top-10-categories',
    'filters' => [],
    'sort' => [],
    'pagination' => ['page' => 1, 'pageSize' => 10],
]);
$topEngine = new SqlTestEngine();
$topResult = (new SqlRepository($topEngine, $registry))->execute($topRequest);
$topCountExecutions = array_values(array_filter(
    $topEngine->executions,
    fn (array $entry): bool => str_contains($entry['sql'], 'COUNT(*) AS TotalRows')
));
$topDataExecution = end($topEngine->executions);
sqlAssert($topCountExecutions === [], 'A complete first-page TOP resource executed an unnecessary count query.');
sqlAssert(count($topEngine->executions) === 1, 'A complete first-page TOP resource executed more than its data query.');
sqlAssert($topResult['totalRows'] === $topResult['rowsReturned'], 'TOP resource total rows were not inferred from its complete result.');
sqlAssert(str_contains($topDataExecution['sql'], 'ORDER BY Sales DESC'), 'TOP resource data ordering was removed.');
sqlAssert(
    !str_contains($topDataExecution['sql'], ') AS SqlResource')
        && !str_contains($topDataExecution['sql'], 'OFFSET 0 ROWS'),
    'A first-page TOP resource was unnecessarily wrapped or repaginated.'
);

$partialTopEngine = new SqlTestEngine();
(new SqlRepository($partialTopEngine, $registry))->execute($normalizer->normalize([
    'action' => 'sql',
    'resource' => 'bill-top-10-categories',
    'filters' => [],
    'sort' => [],
    'pagination' => ['page' => 1, 'pageSize' => 5],
]));
$partialTopCountExecutions = array_values(array_filter(
    $partialTopEngine->executions,
    fn (array $entry): bool => str_contains($entry['sql'], 'COUNT(*) AS TotalRows')
));
sqlAssert(
    count($partialTopCountExecutions) === 1,
    'A partially displayed TOP resource skipped the count required by pagination.'
);
$partialTopDataExecution = end($partialTopEngine->executions);
sqlAssert(
    str_contains($partialTopDataExecution['sql'], 'FETCH NEXT 5 ROWS ONLY'),
    'A partially displayed TOP resource lost data pagination.'
);

$customerEngine = new SqlTestEngine();
(new SqlRepository($customerEngine, $registry))->execute($normalizer->normalize([
    'action' => 'sql',
    'resource' => 'customer',
    ...$customerExecutionMetadata,
    'filters' => [],
    'sort' => [],
    'pagination' => ['page' => 1, 'pageSize' => 25],
]));
$customerExecution = end($customerEngine->executions);
sqlAssert(str_contains($customerExecution['sql'], 'FROM CustomerTable'), 'Customer SQL resource stopped loading.');
sqlAssert(str_contains($customerExecution['sql'], 'GROUP BY Cust_Name'), 'Customer SQL grouping was changed.');

$dateFilterCases = [
    'equals' => ['=', '2021-04-01', [20210401]],
    'greater than or equal' => ['>=', '2021-04-01', [20210401]],
    'less than or equal' => ['<=', 20220331, [20220331]],
    'date range' => ['BETWEEN', ['2021-04-01', '2022-03-31'], [20210401, 20220331]],
];
foreach ($dateFilterCases as $label => [$operator, $value, $expectedParams]) {
    $dateEngine = new SqlTestEngine();
    (new SqlRepository($dateEngine, $registry))->execute($normalizer->normalize([
        'action' => 'sql',
        'resource' => 'customer',
        ...$customerExecutionMetadata,
        'filters' => [['field' => 'StDate', 'operator' => $operator, 'value' => $value]],
    ]));
    $dateExecution = end($dateEngine->executions);
    $expectedPredicate = $operator === 'BETWEEN'
        ? '(StDate) BETWEEN ? AND ?'
        : "(StDate) {$operator} ?";
    sqlAssert(
        str_contains($dateExecution['sql'], 'FROM CustomerTable')
            && str_contains($dateExecution['sql'], $expectedPredicate)
            && strpos($dateExecution['sql'], '(StDate)') < strpos($dateExecution['sql'], 'GROUP BY'),
        "Report {$label} date filter was not applied before aggregation."
    );
    sqlAssert($dateExecution['params'] === $expectedParams, "Report {$label} date parameters changed.");
    sqlAssert(
        !str_contains($dateExecution['sql'], '2021-04-01')
            && !str_contains($dateExecution['sql'], '20210401'),
        "Report {$label} date value leaked into SQL text."
    );
}

foreach (['AND', 'OR'] as $logic) {
    $logicEngine = new SqlTestEngine();
    (new SqlRepository($logicEngine, $registry))->execute($normalizer->normalize([
        'action' => 'sql',
        'resource' => 'customer',
        ...$customerExecutionMetadata,
        'filterLogic' => $logic,
        'filters' => [
            ['field' => 'Cust_Name', 'operator' => 'LIKE', 'value' => "%O'Brien%"],
            ['field' => 'StDate', 'operator' => '>=', 'value' => '2021-04-01'],
        ],
    ]));
    $logicExecution = end($logicEngine->executions);
    sqlAssert(
        str_contains($logicExecution['sql'], "(Cust_Name) LIKE ? {$logic} (StDate) >= ?"),
        "Report {$logic} filter logic changed."
    );
    sqlAssert(
        $logicExecution['params'] === ["%O'Brien%", 20210401]
            && !str_contains($logicExecution['sql'], "O'Brien"),
        "Report {$logic} filters were not safely parameterized."
    );
}

try {
    (new SqlRepository(new SqlTestEngine(), $registry))->execute($normalizer->normalize([
        'action' => 'sql',
        'resource' => 'customer',
        ...$customerExecutionMetadata,
        'filters' => [['field' => 'StDate', 'operator' => '=', 'value' => '2021-02-30']],
    ]));
    throw new RuntimeException('Invalid integer-backed report date was accepted.');
} catch (ApiRequestException $exception) {
    sqlAssert($exception->getErrorCode() === 'INVALID_SQL_RUNTIME_VALUE', 'Wrong runtime date error code.');
}

$statFilterCases = [
    'Item_Desc only' => [
        [['field' => 'Item_Desc', 'operator' => 'LIKE', 'value' => '%ABC%']],
        ['%ABC%'],
    ],
    'Std_Vat only' => [
        [['field' => 'Std_Vat', 'operator' => 'LIKE', 'value' => '%5%']],
        ['%5%'],
    ],
    'Item_Desc and Std_Vat' => [
        [
            ['field' => 'Item_Desc', 'operator' => 'LIKE', 'value' => '%ABC%'],
            ['field' => 'Std_Vat', 'operator' => 'LIKE', 'value' => '%5%'],
        ],
        ['%ABC%', '%5%'],
    ],
];
foreach ($statFilterCases as $label => [$filters, $expectedParams]) {
    $statsEngine = new SqlTestEngine();
    $statsEngine->resultData = [[
        'TotalItems' => 2,
        'MinimumSP' => 10,
        'MaximumSP' => 20,
        'TotalValue' => 30,
    ]];
    $statsResult = (new SqlRepository($statsEngine, $registry))->execute(
        $normalizer->normalize([
            'action' => 'sql',
            'resource' => 'item-dashboard-stats',
            ...$statsExecutionMetadata,
            'filters' => $filters,
        ])
    );
    $statsExecution = end($statsEngine->executions);
    sqlAssert(
        str_contains($statsExecution['sql'], 'FROM ItemMasterTable')
            && str_contains($statsExecution['sql'], '(Item_Desc)') === ($label !== 'Std_Vat only')
            && str_contains($statsExecution['sql'], '(Std_Vat)') === ($label !== 'Item_Desc only'),
        "{$label} was not applied to the statistic source."
    );
    sqlAssert(
        !str_contains($statsExecution['sql'], 'SqlResource.[Item_Desc]')
            && !str_contains($statsExecution['sql'], 'SqlResource.[Std_Vat]'),
        "{$label} was applied after aggregation."
    );
    sqlAssert($statsExecution['params'] === $expectedParams, "{$label} parameters changed.");
    sqlAssert(
        $statsResult['data'][0] === [
            'TotalItems' => 2,
            'MinimumSP' => 10,
            'MaximumSP' => 20,
            'TotalValue' => 30,
        ],
        "{$label} statistic response mapping changed."
    );
}

$unfilteredStatsEngine = new SqlTestEngine();
$unfilteredStatsEngine->resultData = [[
    'TotalItems' => 0,
    'MinimumSP' => null,
    'MaximumSP' => null,
    'TotalValue' => null,
]];
$unfilteredStatsResult = (new SqlRepository($unfilteredStatsEngine, $registry))->execute(
    $normalizer->normalize([
        'action' => 'sql',
        'resource' => 'item-dashboard-stats',
        ...$statsExecutionMetadata,
        'filters' => [],
    ])
);
$unfilteredStatsExecution = end($unfilteredStatsEngine->executions);
sqlAssert(
    !str_contains($unfilteredStatsExecution['sql'], ' WHERE '),
    'An empty statistic filter produced a predicate or unresolved marker.'
);
sqlAssert(
    $unfilteredStatsResult['data'][0]['TotalItems'] === 0
        && $unfilteredStatsResult['data'][0]['MinimumSP'] === null
        && $unfilteredStatsResult['data'][0]['MaximumSP'] === null
        && $unfilteredStatsResult['data'][0]['TotalValue'] === null,
    'Zero-row statistic response values changed.'
);

$tableFilterEngine = new SqlTestEngine();
(new SqlRepository($tableFilterEngine, $registry))->execute($normalizer->normalize([
    'action' => 'sql',
    'resource' => 'item-dashboard-table',
    ...$tableExecution,
    'filters' => [
        ['field' => 'Item_Desc', 'operator' => 'LIKE', 'value' => '%ABC%'],
        ['field' => 'Std_Vat', 'operator' => 'LIKE', 'value' => '%5%'],
    ],
    'sort' => [['field' => 'Item_Code', 'direction' => 'DESC']],
    'pagination' => ['page' => 2, 'pageSize' => 10],
]));
$tableFilterExecution = end($tableFilterEngine->executions);
sqlAssert(
    str_contains($tableFilterExecution['sql'], 'SqlResource.[Item_Desc] LIKE ?')
        && str_contains($tableFilterExecution['sql'], 'SqlResource.[Std_Vat] LIKE ?')
        && str_contains($tableFilterExecution['sql'], 'SqlResource.[Item_Code] DESC')
        && str_contains($tableFilterExecution['sql'], 'OFFSET 10 ROWS'),
    'Item dashboard table filtering, sorting, or pagination changed.'
);

try {
    $repository->execute(['resource' => 'item', ...$itemExecution, 'filters' => [[
        'field' => 'Password', 'operator' => '=', 'value' => 'secret'
    ]]]);
    throw new RuntimeException('Non-allowlisted runtime column was accepted.');
} catch (ApiRequestException $exception) {
    sqlAssert($exception->getErrorCode() === 'INVALID_SQL_RUNTIME_FIELD', 'Wrong runtime field error code.');
}

try {
    $repository->execute([
        'resource' => 'item',
        ...$itemExecution,
        'sort' => [['field' => 'Password', 'direction' => 'ASC']],
    ]);
    throw new RuntimeException('Non-output runtime sort field was accepted.');
} catch (ApiRequestException $exception) {
    sqlAssert($exception->getErrorCode() === 'INVALID_SQL_RUNTIME_FIELD', 'Wrong runtime sort field error code.');
}

try {
    (new SqlRepository(new SqlTestEngine(), $registry))->execute([
        'resource' => 'item-dashboard-stats',
        ...$statsExecutionMetadata,
        'filters' => [['field' => 'Item_Code', 'operator' => '=', 'value' => 'A1']],
    ]);
    throw new RuntimeException('Unallowlisted statistic source field was accepted.');
} catch (ApiRequestException $exception) {
    sqlAssert(
        $exception->getErrorCode() === 'INVALID_SQL_RUNTIME_FIELD',
        'Wrong statistic runtime field error code.'
    );
}

$controller = new SqlTestController(new SqlTestService());
$controller->execute($normalized);
sqlAssert($controller->captured['message'] === 'Data Loaded Successfully', 'SQLController response message changed.');
sqlAssert($controller->captured['data']['data'][0]['ok'] === 1, 'SQLController did not return service data.');

Response::setRequestContext($publicRequest);
$response = Response::successPayload($controller->captured['data'], $controller->captured['message']);
sqlAssert($response['success'] === true, 'SQL response was not standardized.');
sqlAssert($response['meta']['page'] === 2 && $response['meta']['pageSize'] === 25, 'SQL response pagination metadata changed.');

// Bill dashboard resources: the selected date range is the only date limit,
// months combine across years, and the TOP-10 month-wise category report,
// shared by the Bill and Sales dashboards, filters inside its CTE.
$billCatalog = new class extends MetadataRepository {
    private array $types = [
        'billdettable' => ['bill_date' => 'int', 'cat_code' => 'int', 'status' => 'char', 'item_value' => 'decimal'],
        'categorytable' => ['cat_code' => 'int', 'cat_desc' => 'varchar'],
        'purmasttable' => ['gin_date' => 'int', 'inv_value' => 'decimal'],
    ];
    public function __construct() {}
    public function columnExists($table, $column) { return isset($this->types[strtolower($table)][strtolower($column)]); }
    public function getColumnDataType($table, $column) { return $this->types[strtolower($table)][strtolower($column)] ?? null; }
};
foreach ($registry->discoverResourceIds() as $resourceId) {
    if (!str_starts_with($resourceId, 'widgets/bill-')) continue;
    sqlAssert(
        !str_contains((string)file_get_contents($registry->resolve($resourceId)['file']), '20210401'),
        "Bill resource {$resourceId} still limits itself to FY 2021-22."
    );
}
$billCases = [
    'widgets/bill-purchases-month-wise' => [['Month', 'Purchases'], 'GIN_Date', 'PurMastTable.GIN_Date'],
    'widgets/sales-top-10-month-wise-category-wise' => [['Month', 'Category', 'Sales'], 'Bill_Date', 'BIL.Bill_Date'],
];
foreach ($billCases as $resource => [$columns, $field, $expression]) {
    $billEngine = new SqlTestEngine();
    (new SqlRepository($billEngine, $registry, null, $billCatalog))->execute($normalizer->normalize([
        'action' => 'sql',
        'resource' => $resource,
        'execution' => ['columns' => $columns, 'filters' => [$field => ['placement' => 'source']]],
        'filters' => [['field' => $field, 'type' => 'daterange', 'operator' => 'BETWEEN', 'value' => ['2021-04-01', '2022-03-31']]],
        'sort' => [['field' => 'Month', 'direction' => 'DESC']],
        'pagination' => ['page' => 1, 'pageSize' => 10],
    ]));
    $billQueries = array_values(array_filter($billEngine->executions, fn (array $entry): bool => !str_contains($entry['sql'], 'compatibility_level')));
    sqlAssert(count($billQueries) === 2, "{$resource} did not run its count and data queries.");
    foreach ($billQueries as $billQuery) {
        $filterAt = strpos($billQuery['sql'], "({$expression}) BETWEEN ? AND ?");
        sqlAssert(
            $filterAt !== false
                && $filterAt < strpos($billQuery['sql'], 'GROUP BY')
                && $billQuery['params'] === [20210401, 20220331],
            "{$resource} did not apply the selected range before aggregation."
        );
        sqlAssert(
            !str_contains($billQuery['sql'], ', 1, 4)'),
            "{$resource} still groups months by year."
        );
    }
}
sqlAssert(
    strpos($billQueries[0]['sql'], '(BIL.Bill_Date) BETWEEN ? AND ?') < strpos($billQueries[0]['sql'], 'RankedData AS'),
    'The TOP-10 range was not placed in its SalesData CTE.'
);

// The canonical TOP-10 resource: one calendar-month number per row, ranked
// within that month, with no fixed date window of its own.
$topTenId = 'widgets/sales-top-10-month-wise-category-wise';
$topTenFile = $registry->resolve($topTenId)['file'];
sqlAssert($topTenFile === realpath(QUERY_PATH . "/{$topTenId}.sql"), 'The canonical TOP-10 resource did not resolve to its file.');
$topTenSql = (string)file_get_contents($topTenFile);
sqlAssert(
    preg_match('/\b(?:GETDATE|YEAR)\s*\(|\b20\d{6}\b/i', $topTenSql) === 0,
    'The canonical TOP-10 resource has a fixed date window.'
);
sqlAssert(
    str_contains($topTenSql, 'SUBSTRING(CONVERT(VARCHAR, BIL.Bill_Date), 5, 2) AS Month')
        && preg_match('/GROUP BY\s+SUBSTRING\(CONVERT\(VARCHAR, BIL\.Bill_Date\), 5, 2\),\s+CAT\.Cat_Desc/', $topTenSql) === 1
        && !str_contains($topTenSql, ', 1, 4)')
        && preg_match('/PARTITION BY Month\s+ORDER BY Sales DESC/', $topTenSql) === 1,
    'The canonical TOP-10 resource no longer groups and ranks by calendar month number.'
);
sqlAssert(
    preg_match('/WHERE \(\s+BIL\.Cat_Code = CAT\.Cat_Code\s+\) AND \(\(BIL\.Bill_Date\) BETWEEN \? AND \?\)/', $billQueries[0]['sql']) === 1
        && strpos($billQueries[0]['sql'], '(BIL.Bill_Date) BETWEEN ? AND ?') < strpos($billQueries[0]['sql'], 'DENSE_RANK()'),
    'The TOP-10 range did not limit rows before ranking.'
);

// Removed IDs fail as unavailable resources. The released ID
// widgets/TOP-10-month-Wise-Category-wise is absent pending the compatibility
// decision recorded in CHANGELOG.md; restoring it must change this test.
foreach ([
    'widgets/bill-top-10-month-wise-category-wise', 'bill-top-10-month-wise-category-wise',
    'widgets/TOP-10-month-Wise-Category-wise', 'TOP-10-month-Wise-Category-wise',
] as $removedId) {
    $removedFailure = null;
    try {
        $registry->resolve($removedId);
    } catch (ApiRequestException $exception) {
        $removedFailure = $exception;
    }
    sqlAssert(
        $removedFailure !== null && $removedFailure->getErrorCode() === 'INVALID_SQL_RESOURCE',
        "Removed SQL resource {$removedId} did not fail as INVALID_SQL_RESOURCE."
    );
}

echo "SQL controller tests passed.\n";
