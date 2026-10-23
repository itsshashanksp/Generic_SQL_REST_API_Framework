<?php

require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/SqlRepository.php';
require_once __DIR__ . '/support/SqlResourceFixtures.php';

function discoveryAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function discoveryFailure(callable $operation, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }
    throw new RuntimeException($message);
}

class SqlResourceDiscoveryEngine extends QueryEngine
{
    public array $executions = [];
    public function __construct(private int $compatibilityLevel = 150) {}

    public function executePrepared($sql, array $params = [], array $context = [])
    {
        $this->executions[] = compact('sql', 'params', 'context');
        if (str_contains($sql, 'COUNT(*) AS TotalRows')) {
            return ['data' => [['TotalRows' => 4]]];
        }
        if (str_contains($sql, 'compatibility_level')) {
            return ['data' => [['CompatibilityLevel' => $this->compatibilityLevel]]];
        }
        return [
            'executionTime' => 0.1,
            'rowsReturned' => 1,
            'data' => [['Item_Code' => 'A1']],
        ];
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sql-resource-discovery-' . bin2hex(random_bytes(6));
$reports = $root . DIRECTORY_SEPARATOR . 'reports';
$nested = $root . DIRECTORY_SEPARATOR . 'dashboard' . DIRECTORY_SEPARATOR . 'sales';
$system = $root . DIRECTORY_SEPARATOR . 'system';
$files = [];

try {
    mkdir($reports, 0700, true);
    mkdir($nested, 0700, true);
    mkdir($system, 0700, true);
    $itemFile = $reports . DIRECTORY_SEPARATOR . 'item.sql';
    $customerFile = $reports . DIRECTORY_SEPARATOR . 'customer.sql';
    $topFile = $nested . DIRECTORY_SEPARATOR . 'top-products.sql';
    $textFile = $reports . DIRECTORY_SEPARATOR . 'not-sql.txt';
    $systemFile = $system . DIRECTORY_SEPARATOR . 'Tables.sql';
    file_put_contents($itemFile, "SELECT Item_Code, Item_Desc, Item_MRP FROM ItemMasterTable\n");
    file_put_contents($customerFile, "SELECT Cust_Name, COUNT(*) AS TotalCustomers FROM CustomerTable GROUP BY Cust_Name\n");
    file_put_contents($topFile, "SELECT TOP 10 Item_Code, Sales FROM Sales ORDER BY Sales DESC\n");
    file_put_contents($textFile, "SELECT Secret FROM Outside\n");
    file_put_contents($systemFile, "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES\n");
    $files = [$itemFile, $customerFile, $topFile, $textFile, $systemFile];

    $registry = new SqlResourceRegistry([], $root);
    discoveryAssert(
        $registry->discoverResourceIds() === [
            'dashboard/sales/top-products',
            'reports/customer',
            'reports/item',
        ],
        'Recursive SQL resource discovery returned the wrong identities.'
    );
    $item = $registry->resolve('reports/item');
    discoveryAssert(
        $item['file'] === realpath($itemFile)
            && $item['columns'] === []
            && $item['defaultSort'] === [],
        'A discovered resource required execution metadata.'
    );
    discoveryAssert(
        $registry->resolve('item')['file'] === realpath($itemFile),
        'Unique basename compatibility resolution failed.'
    );

    $validator = new QueryRequestValidator();
    $normalizer = new QueryRequestNormalizer();
    foreach ([
        '../../secret',
        '../reports/item',
        '/reports/item',
        'C:/reports/item',
        'reports\\item',
        'reports/item.sql',
        "reports/item\0evil",
        'reports//item',
    ] as $invalidId) {
        $failure = discoveryFailure(
            fn () => $validator->validate(['action' => 'sql', 'resource' => $invalidId]),
            "Invalid resource identifier was accepted: {$invalidId}"
        );
        discoveryAssert(
            $failure instanceof ApiRequestException
                && $failure->getErrorCode() === 'INVALID_REQUEST',
            'Malformed resource identifier returned the wrong error.'
        );
    }
    foreach (['reports/missing', 'reports/not-sql', 'system/Tables'] as $unavailable) {
        $failure = discoveryFailure(
            fn () => $registry->resolve($unavailable),
            "Unavailable resource was accepted: {$unavailable}"
        );
        discoveryAssert(
            $failure instanceof ApiRequestException
                && $failure->getErrorCode() === 'INVALID_SQL_RESOURCE'
                && !str_contains($failure->getMessage(), $root),
            'Unavailable resource did not fail safely.'
        );
    }

    $simpleEngine = new SqlResourceDiscoveryEngine();
    (new SqlRepository($simpleEngine, $registry))->execute(['resource' => 'reports/item']);
    $simpleExecution = end($simpleEngine->executions);
    discoveryAssert(
        str_contains($simpleExecution['sql'], 'FROM ItemMasterTable')
            && $simpleExecution['params'] === [],
        'A simple unregistered SQL resource did not execute.'
    );

    $execution = [
        'columns' => ['Cust_Name', 'TotalCustomers'],
        'filters' => [
            'CustomerName' => [
                'expression' => 'Cust_Name',
                'placement' => 'source',
            ],
            'MinimumCustomers' => [
                'expression' => 'COUNT(*)',
                'placement' => 'having',
            ],
            'StartDate' => [
                'expression' => 'StDate',
                'placement' => 'source',
                'valueType' => 'integer-date',
            ],
        ],
        'defaultSort' => [['field' => 'Cust_Name', 'direction' => 'ASC']],
    ];
    $publicRequest = [
        'action' => 'sql',
        'resource' => 'reports/customer',
        'execution' => $execution,
        'filters' => [
            ['field' => 'CustomerName', 'operator' => 'LIKE', 'value' => 'A%'],
            ['field' => 'StartDate', 'operator' => 'BETWEEN', 'value' => ['2026-01-01', '2026-12-31']],
            ['field' => 'MinimumCustomers', 'operator' => '>=', 'value' => 2],
        ],
        'pagination' => ['page' => 1, 'pageSize' => 25],
    ];
    $validator->validate($publicRequest);
    $normalized = $normalizer->normalize($publicRequest);
    discoveryAssert($normalized['execution'] === $execution, 'Execution metadata was dropped by normalization.');
    $complexEngine = new SqlResourceDiscoveryEngine();
    $complexResult = (new SqlRepository($complexEngine, $registry))->execute($normalized);
    $complexData = end($complexEngine->executions);
    discoveryAssert(
        str_contains($complexData['sql'], 'WHERE (Cust_Name) LIKE ? AND (StDate) BETWEEN ? AND ?')
            && str_contains($complexData['sql'], 'HAVING (COUNT(*)) >= ?')
            && str_contains($complexData['sql'], 'ORDER BY SqlResource.[Cust_Name] ASC')
            && str_contains($complexData['sql'], 'OFFSET 0 ROWS')
            && $complexData['params'] === ['A%', 20260101, 20261231, 2]
            && $complexResult['totalRows'] === 4,
        'Execution metadata did not preserve mapped filters, conversion, sorting, or pagination.'
    );

    $nullEngine = new SqlResourceDiscoveryEngine();
    (new SqlRepository($nullEngine, $registry))->execute($normalizer->normalize([
        'action' => 'sql',
        'resource' => 'reports/item',
        'execution' => [
            'columns' => ['Item_Code', 'Item_Desc', 'Item_MRP'],
            'defaultSort' => [['field' => 'Item_Code', 'direction' => 'ASC']],
        ],
        'filters' => [['field' => 'Item_Desc', 'operator' => 'IS NULL']],
        'sort' => [['field' => 'Item_MRP', 'direction' => 'DESC']],
    ]));
    $nullExecution = end($nullEngine->executions);
    discoveryAssert(
        str_contains($nullExecution['sql'], 'SqlResource.[Item_Desc] IS NULL')
            && str_contains($nullExecution['sql'], 'SqlResource.[Item_MRP] DESC')
            && $nullExecution['params'] === [],
        'Derived output filter/sort metadata failed.'
    );

    foreach ([
        ['filters' => ['Bad' => ['expression' => 'BIL.Id; DROP TABLE Users', 'placement' => 'source']]],
        ['filters' => ['Bad' => ['expression' => 'BIL.Id OR 1=1', 'placement' => 'source']]],
        ['filters' => ['Bad' => ['expression' => 'SUM(BIL.Amount) OR 1=1', 'placement' => 'having']]],
        ['filters' => ['Bad' => ['expression' => 'BIL.Id', 'placement' => 'join']]],
        ['filters' => ['Bad' => ['expression' => 'BIL.Id', 'placement' => 'source', 'valueType' => 'sql']]],
        ['columns' => 'Item_Code', 'defaultSort' => [['field' => 'Item_Code', 'direction' => 'ASC']]],
    ] as $badExecution) {
        $failure = discoveryFailure(
            fn () => $validator->validate([
                'action' => 'sql',
                'resource' => 'reports/customer',
                'execution' => $badExecution,
            ]),
            'Unsafe SQL execution metadata was accepted.'
        );
        discoveryAssert($failure instanceof ApiRequestException, 'Unsafe execution metadata failed incorrectly.');
    }

    $badFilter = discoveryFailure(
        fn () => (new SqlRepository(new SqlResourceDiscoveryEngine(), $registry))->execute(
            $normalizer->normalize([
                'action' => 'sql',
                'resource' => 'reports/item',
                'execution' => ['columns' => ['Item_Code']],
                'filters' => [['field' => 'Secret', 'operator' => '=', 'value' => 1]],
            ])
        ),
        'Unknown runtime filter field was accepted.'
    );
    discoveryAssert(
        $badFilter instanceof ApiRequestException
            && $badFilter->getErrorCode() === 'INVALID_SQL_RUNTIME_FIELD',
        'Unknown runtime filter field returned the wrong error.'
    );
    $badSort = discoveryFailure(
        fn () => (new SqlRepository(new SqlResourceDiscoveryEngine(), $registry))->execute(
            $normalizer->normalize([
                'action' => 'sql',
                'resource' => 'reports/item',
                'execution' => ['columns' => ['Item_Code']],
                'sort' => [['field' => 'Secret', 'direction' => 'ASC']],
            ])
        ),
        'Unknown runtime sort field was accepted.'
    );
    discoveryAssert(
        $badSort instanceof ApiRequestException
            && $badSort->getErrorCode() === 'INVALID_SQL_RUNTIME_FIELD',
        'Unknown runtime sort field returned the wrong error.'
    );
    $unsortedPage = discoveryFailure(
        fn () => (new SqlRepository(new SqlResourceDiscoveryEngine(), $registry))->execute([
            'resource' => 'reports/item',
            'pagination' => ['page' => 1, 'pageSize' => 25],
        ]),
        'Pagination without an approved order was accepted.'
    );
    discoveryAssert(
        $unsortedPage instanceof ApiRequestException
            && $unsortedPage->getErrorCode() === 'INVALID_SQL_PAGINATION',
        'Unsorted pagination returned the wrong error.'
    );

    $topEngine = new SqlResourceDiscoveryEngine();
    $topResult = (new SqlRepository($topEngine, $registry))->execute([
        'resource' => 'dashboard/sales/top-products',
        'execution' => [
            'columns' => ['Item_Code', 'Sales'],
            'defaultSort' => [['field' => 'Sales', 'direction' => 'DESC']],
        ],
        'pagination' => ['page' => 1, 'pageSize' => 10],
    ]);
    discoveryAssert(
        count($topEngine->executions) === 1
            && !str_contains(end($topEngine->executions)['sql'], 'OFFSET 0 ROWS')
            && $topResult['totalRows'] === $topResult['rowsReturned'],
        'Discovered TOP first-page behavior regressed.'
    );

    // A TOP resource must be ranked by its authored ORDER BY before it is
    // limited, on both pagination paths (ROW_NUMBER below compatibility 110).
    $orderedFile = $nested . DIRECTORY_SEPARATOR . 'ordered-products.sql';
    file_put_contents($orderedFile, "SELECT Item_Code, Sales FROM Sales ORDER BY Sales DESC\n");
    $files[] = $orderedFile;
    $rankedTop = "SELECT TOP 10 Item_Code, Sales FROM Sales ORDER BY Sales DESC";
    foreach ([100, 150] as $compatibilityLevel) {
        $rankedEngine = new SqlResourceDiscoveryEngine($compatibilityLevel);
        (new SqlRepository($rankedEngine, $registry))->execute([
            'resource' => 'dashboard/sales/top-products',
            'execution' => ['columns' => ['Item_Code', 'Sales']],
            'filters' => [['field' => 'Sales', 'operator' => '>', 'value' => 0]],
            'sort' => [['field' => 'Item_Code', 'direction' => 'ASC']],
            'pagination' => ['page' => 2, 'pageSize' => 5],
        ]);
        $statements = array_column($rankedEngine->executions, 'sql');
        $countSql = current(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'COUNT(*) AS TotalRows')));
        $dataSql = end($statements);
        discoveryAssert(
            is_string($countSql) && str_contains($countSql, $rankedTop)
                && str_contains($dataSql, $rankedTop)
                && ($compatibilityLevel >= 110
                    ? str_contains($dataSql, 'OFFSET 5 ROWS')
                    : str_contains($dataSql, 'ROW_NUMBER() OVER') && str_contains($dataSql, 'BETWEEN 6')),
            "TOP resource was limited before ranking at compatibility level {$compatibilityLevel}."
        );
    }
    $orderedEngine = new SqlResourceDiscoveryEngine(100);
    (new SqlRepository($orderedEngine, new SqlResourceRegistry([], $root)))->execute([
        'resource' => 'dashboard/sales/ordered-products',
        'execution' => ['columns' => ['Item_Code', 'Sales']],
        'sort' => [['field' => 'Item_Code', 'direction' => 'ASC']],
        'pagination' => ['page' => 1, 'pageSize' => 5],
    ]);
    discoveryAssert(
        !str_contains(end($orderedEngine->executions)['sql'], 'ORDER BY Sales DESC'),
        'A non-TOP authored ORDER BY was kept inside a derived table.'
    );

    $other = $root . DIRECTORY_SEPARATOR . 'archive';
    mkdir($other, 0700, true);
    $ambiguousItem = $other . DIRECTORY_SEPARATOR . 'item.sql';
    file_put_contents($ambiguousItem, 'SELECT Item_Code FROM ArchivedItems');
    $files[] = $ambiguousItem;
    $ambiguousFailure = discoveryFailure(
        fn () => (new SqlResourceRegistry([], $root))->resolve('item'),
        'Ambiguous basename resource was accepted.'
    );
    discoveryAssert(
        $ambiguousFailure instanceof ApiRequestException
            && $ambiguousFailure->getErrorCode() === 'INVALID_SQL_RESOURCE',
        'Ambiguous basename did not request a full resource identifier.'
    );

    $duplicateA = $reports . DIRECTORY_SEPARATOR . 'Duplicate.sql';
    $duplicateB = $reports . DIRECTORY_SEPARATOR . 'duplicate.sql';
    file_put_contents($duplicateA, 'SELECT 1 AS Value');
    file_put_contents($duplicateB, 'SELECT 2 AS Value');
    $files[] = $duplicateA;
    $files[] = $duplicateB;
    $duplicateFailure = discoveryFailure(
        fn () => (new SqlResourceRegistry([], $root))->discoverResourceIds(),
        'Case-insensitive duplicate resource identities were accepted.'
    );
    discoveryAssert(
        $duplicateFailure instanceof RuntimeException
            && str_contains($duplicateFailure->getMessage(), 'Duplicate SQL resource identity')
            && !str_contains($duplicateFailure->getMessage(), $root),
        'Duplicate resource identities did not fail safely.'
    );

    $realRegistry = sqlResourceFixtureRegistry();
    $realIds = $realRegistry->discoverResourceIds();
    discoveryAssert(
        $realRegistry->resolve('reports/item')['file'] === realpath(sqlResourceFixtureRoot() . '/reports/item.sql')
            && $realRegistry->resolve('item')['file'] === realpath(sqlResourceFixtureRoot() . '/reports/item.sql')
            && in_array('reports/customer', $realIds, true)
            && in_array('widgets/item-dashboard-stats', $realIds, true),
        'Repository resource discovery or unique basename compatibility failed.'
    );
    // Resources referenced by the frontend Sales, Sales Receipts, Purchase,
    // Item, and Bill dashboards must resolve to their exact files.
    foreach ([
        'widgets/sales-month-wise', 'widgets/top-10-categories-sales', 'widgets/category-sales-month-wise',
        'widgets/sales-top-10-month-wise-category-wise',
        'widgets/sales-day-wise', 'widgets/date-wise-gst-wise-sales', 'widgets/gst-wise-sales',
        'widgets/top-50-item-wise-sales', 'widgets/total-sales', 'widgets/daily-collection-summary',
        'widgets/payment-mode-wise-collection', 'widgets/monthly-collection-trend',
        'widgets/user-wise-day-wise-collection', 'widgets/top-10-largest-receipts',
        'widgets/purchases-month-wise', 'widgets/purchases-day-wise', 'widgets/total-purchases',
        'widgets/item-stock-value-by-category',
        'widgets/bill-sales-month-wise', 'widgets/bill-purchases-month-wise', 'widgets/bill-top-10-categories',
        'widgets/bill-category-sales-month-wise', 'widgets/bill-total-sales', 'widgets/bill-total-purchases',
    ] as $dashboardResource) {
        discoveryAssert(
            in_array($dashboardResource, $realIds, true)
                && $realRegistry->resolve($dashboardResource)['file'] === realpath(sqlResourceFixtureRoot() . "/{$dashboardResource}.sql"),
            "Dashboard SQL resource {$dashboardResource} did not resolve to its file."
        );
    }
    foreach ($realIds as $realId) {
        discoveryAssert(
            !str_contains((string)file_get_contents($realRegistry->resolve($realId)['file']), '/*__RUNTIME_FILTERS__*/'),
            "Obsolete runtime-filter marker remains in {$realId}."
        );
    }
    // Byte-identical copies drift apart when only one is fixed, so dashboards
    // share one resource instead. A deliberate copy (for example a
    // compatibility ID that must keep its own behaviour) is listed here, with
    // the reason, as a sorted pair of resource IDs.
    $allowedDuplicateResources = [];
    $resourcesByContent = [];
    foreach ($realIds as $realId) {
        $resourcesByContent[hash_file('sha256', $realRegistry->resolve($realId)['file'])][] = $realId;
    }
    foreach ($resourcesByContent as $sameContent) {
        if (count($sameContent) < 2) continue;
        sort($sameContent, SORT_STRING);
        discoveryAssert(
            in_array($sameContent, $allowedDuplicateResources, true),
            'SQL resources have byte-identical files: ' . implode(', ', $sameContent) . '. Share one resource or justify the copy.'
        );
    }

    echo "SQL resource discovery/execution metadata tests passed.\n";
} finally {
    foreach (array_reverse($files) as $file) {
        if (is_file($file)) @unlink($file);
    }
    @rmdir($system);
    @rmdir($nested);
    @rmdir(dirname($nested));
    @rmdir($root . DIRECTORY_SEPARATOR . 'archive');
    @rmdir($reports);
    @rmdir($root);
}
