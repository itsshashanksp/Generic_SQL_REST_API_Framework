<?php

require_once __DIR__ . "/../../../core/QueryEngine.php";
require_once __DIR__ . "/../MetadataRepository.php";
require_once __DIR__ . '/OrderByBuilder.php';
require_once __DIR__ . '/PaginationBuilder.php';
require_once __DIR__ . '/WindowFunctionBuilder.php';
require_once __DIR__ . '/JoinBuilder.php';
require_once __DIR__ . '/WhereBuilder.php';
require_once __DIR__ . '/GroupByBuilder.php';
require_once __DIR__ . '/HavingBuilder.php';
require_once __DIR__ . '/SqlExpressionBuilder.php';
require_once __DIR__ . '/ScopedMetadataRepository.php';
require_once __DIR__ . '/../../Database/SourceResolver.php';
require_once __DIR__ . '/../../Requests/ApiRequestException.php';

class SelectBuilder
{
    private QueryEngine $queryEngine;
    private ScopedMetadataRepository $metadataRepository;
    private OrderByBuilder $orderByBuilder;
    private PaginationBuilder $paginationBuilder;
    private WindowFunctionBuilder $windowFunctionBuilder;
    private JoinBuilder $joinBuilder;
    private WhereBuilder $whereBuilder;
    private GroupByBuilder $groupByBuilder;
    private HavingBuilder $havingBuilder;
    private SqlExpressionBuilder $expressionBuilder;
    private ?Logger $logger;
    private ?SourceResolver $sources;

    public function __construct(
        QueryEngine $queryEngine,
        MetadataRepository $metadataRepository,
        ?Logger $logger = null,
        ?SourceResolver $sources = null
    ) {
        $this->sources = $sources;
        $this->queryEngine = $queryEngine;
        $this->metadataRepository = new ScopedMetadataRepository($metadataRepository);
        $this->logger = $logger;
        $this->expressionBuilder = new SqlExpressionBuilder();
        $this->orderByBuilder = new OrderByBuilder(
            $this->metadataRepository,
            fn (string $column): array => $this->expressionBuilder->resolveColumn($column),
            $this->expressionBuilder
        );
        $this->paginationBuilder = new PaginationBuilder($queryEngine);
        $this->windowFunctionBuilder = new WindowFunctionBuilder(
            $this->orderByBuilder,
            fn ($value): string => $this->expressionBuilder->buildValue($value),
            $this->metadataRepository,
            fn (string $column): array => $this->expressionBuilder->resolveColumn($column),
            $this->expressionBuilder
        );
        $resolver = fn (string $column): array => $this->expressionBuilder->resolveColumn($column);
        $this->joinBuilder = new JoinBuilder($this->metadataRepository, $resolver);
        $this->whereBuilder = new WhereBuilder(
            $this->metadataRepository,
            $resolver,
            fn (array $request, bool $isUnion): array => $this->buildNested($request, $isUnion)
        );
        $this->groupByBuilder = new GroupByBuilder(
            $this->metadataRepository,
            $resolver,
            $this->expressionBuilder
        );
        $this->havingBuilder = new HavingBuilder(
            $this->metadataRepository,
            $resolver,
            $this->expressionBuilder
        );
    }

/*
 * Execute Prepared SQL
 */
    public function build($request, bool $isUnion = false)
    {
    $this->metadataRepository->setVirtualTables($request['_virtualTables'] ?? []);
    $generationStarted = microtime(true);
    $params = [];
    $totalRows = null;

/*
*Recursive CTE
*/
$cteSql = "";

if (isset($request["recursiveCte"])) {

    $cte = $request["recursiveCte"];

    foreach ([
        "name",
        "anchor",
        "recursive"
    ] as $field) {

        if (empty($cte[$field])) {

            throw new Exception(
                "Recursive CTE requires {$field}."
            );

        }

    }

    $anchor =
        $this->buildNested(
            $cte["anchor"],
            true
        );

    $cteColumns = $this->getOutputColumns($cte['anchor']);
    $recursiveRequest = $cte['recursive'];
    $recursiveRequest['_virtualTables'] = [$cte['name'] => $cteColumns];
    $this->metadataRepository->setVirtualTables($recursiveRequest['_virtualTables']);
    if (count($this->getOutputColumns($recursiveRequest)) !== count($cteColumns)) {
        throw new Exception('Recursive CTE branches must return the same number of columns.');
    }

    $recursive =
        $this->buildNested(
            $recursiveRequest,
            true
        );

    $this->metadataRepository->setVirtualTables([$cte['name'] => $cteColumns]);

    $cteSql =
        "WITH "
        . $cte["name"]
        . " AS ("
        . $anchor["sql"]
        . " UNION ALL "
        . $recursive["sql"]
        . ") ";

    $params = array_merge(
        $params,
        $anchor["params"],
        $recursive["params"]
    );
}

/*
 * CTE Request
 */

if (isset($request['cte'])) {

    $cte = $request['cte'];

    if (
        empty($cte['name'])
        || empty($cte['query'])
    ) {
        throw new Exception(
            "CTE requires name and query."
        );
    }

    $subQuery = $this->buildNested(
        $cte['query'],
        true
    );

    $this->metadataRepository->setVirtualTables([
        $cte['name'] => $this->getOutputColumns($cte['query']),
    ]);

    $cteSql =
        "WITH {$cte['name']} AS ({$subQuery['sql']}) ";

    $params = array_merge(
        $params,
        $subQuery['params']
    );
}

        /*
         * Validate Table
         */
         $isCTE =
         (
             isset($request['cte']) &&
             $request['table'] === $request['cte']['name']
         )
         ||
         (
             isset($request['recursiveCte']) &&
             $request['table'] === $request['recursiveCte']['name']
         );

         $baseSource = $this->resolveSources($request, $isCTE);

         if (
             !$isCTE &&
             !$this->metadataRepository->tableExists(
                 $request['table']
            )
         ) {
             throw new Exception("Invalid table name.");
         }

        /*
        * Register Base Table
        */
        $this->expressionBuilder->resetTables();

        if ($this->isCrossDatabase()) {
            $this->registerCrossDatabaseSources($request, $baseSource);
        } else {

        $this->expressionBuilder->registerTable($request['table'], $request['table']);

        if (!empty($request['alias'])) {
            $this->expressionBuilder->registerTable($request['alias'], $request['table']);
        }

        /*
        * Pre-register JOIN Tables & Aliases
        */
        if (!empty($request['joins'])) {

            foreach ($request['joins'] as $join) {

            $this->expressionBuilder->registerTable($join['table'], $join['table']);

        if (!empty($join['alias'])) {
            $this->expressionBuilder->registerTable($join['alias'], $join['table']);
        }
       }
      }
            if ($baseSource !== null) {
                foreach ([$baseSource, ...array_column($request['joins'] ?? [], '_source')] as $source) {
                    $this->expressionBuilder->registerSource($source->reference(), $source);
                }
            }
        }

        /*
        * Validate Select Columns
        */
        foreach ($request['columns'] as $column) {

        if (is_array($column)) {

if (isset($column['node'])) {
    $this->validateCanonicalExpressionColumns($column['node'], $request);
    continue;
}

/*
 * CASE Expression Validation
 */
if (isset($column['case'])) {

    $case = $column['case'];

    if (
        empty($case['when'])
        || !is_array($case['when'])
    ) {
        throw new Exception(
            "CASE requires at least one WHEN clause."
        );
    }

    foreach ($case['when'] as $when) {

        if (empty($when['condition'])) {
            throw new Exception(
                "CASE WHEN requires a condition."
            );
        }

        $condition = $when['condition'];

        if (empty($condition['column'])) {
            throw new Exception(
                "CASE condition requires column."
            );
        }

        if (empty($condition['operator'])) {
            throw new Exception(
                "CASE condition requires operator."
            );
        }

        if (!array_key_exists("value", $condition)) {
            throw new Exception(
                "CASE condition requires value."
            );
        }

        if (!array_key_exists("then", $when)) {
            throw new Exception(
                "CASE WHEN requires THEN value."
            );
        }

        $resolved =
            $this->expressionBuilder->resolveColumn(
                $condition['column']
            );

        $table =
            $resolved['table']
            ?? $request['table'];

        if (
            !$this->metadataRepository->columnExists(
                $table,
                $resolved['column']
            )
        ) {
            throw new Exception(
                "Invalid CASE column: {$condition['column']}"
            );
        }

        $allowedOperators = [
            "=",
            "!=",
            "<>",
            ">",
            "<",
            ">=",
            "<="
        ];

        if (
            !in_array(
                strtoupper($condition['operator']),
                $allowedOperators
            )
        ) {
            throw new Exception(
                "Invalid CASE operator: {$condition['operator']}"
            );
        }
    }

    continue;
}

/*
 * Arithmetic Expression Validation
 */
if (isset($column['expression'])) {

    $expression = $column['expression'];

    foreach (['left','operator','right'] as $field) {
        if (!isset($expression[$field])) {
            throw new Exception("Expression requires {$field}.");
        }
    }

    if (!in_array($expression['operator'], ['+', '-', '*', '/', '%'], true)) {
        throw new Exception("Invalid expression operator.");
    }

    foreach (['left','right'] as $side) {
        if (!is_numeric($expression[$side])) {
            $resolved = $this->expressionBuilder->resolveColumn($expression[$side]);
            $table = $resolved['table'] ?? $request['table'];
            if (!$this->metadataRepository->columnExists($table, $resolved['column'])) {
                throw new Exception("Invalid column: {$expression[$side]}");
            }
        }
    }

    continue;

}

/*
 * Aggregate Column
 */
if (isset($column['function'])) {

$this->validateExpressionColumns($column, $request);

$functionsWithoutColumn = [
    "GETDATE",
    "COALESCE",
    "CONCAT",
    "DATEDIFF",
    "EOMONTH",
    "DATEFROMPARTS",
    "DATETIMEFROMPARTS",
    "TIMEFROMPARTS",
    "SYSDATETIME",
    "CURRENT_TIMESTAMP",
    "IIF",
    "CHOOSE",
    "ROW_NUMBER",
    "RANK",
    "DENSE_RANK",
    "NTILE",
];

if (
    !in_array(
        strtoupper($column['function']),
        $functionsWithoutColumn
    )
) {

    if (empty($column['column'])) {
        throw new Exception(
            "Function {$column['function']} requires a column."
        );
    }

    if (strtoupper($column['column']) != "*") {

        $resolved =
            $this->expressionBuilder->resolveColumn(
                $column['column']
            );

        $table =
            $resolved['table']
            ?? $request['table'];

        if (
            !$this->metadataRepository->columnExists(
                $table,
                $resolved['column']
            )
        ) {
            throw new Exception(
                "Invalid column: {$column['column']}"
            );
        }
    }
}

/*
 * COALESCE Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "COALESCE"
) {

    if (
        empty($column['columns'])
        || !is_array($column['columns'])
    ) {
        throw new Exception(
            "COALESCE requires columns."
        );
    }

    foreach ($column['columns'] as $coalesceColumn) {

        $resolved =
            $this->expressionBuilder->resolveColumn(
                $coalesceColumn
            );

        $table =
            $resolved['table']
            ?? $request['table'];

        if (
            !$this->metadataRepository->columnExists(
                $table,
                $resolved['column']
            )
        ) {
            throw new Exception(
                "Invalid column: {$coalesceColumn}"
            );
        }
    }

    continue;
    }

/*
 * DATEADD Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "DATEADD"
) {

    if (!isset($column['datepart'])) {

        throw new Exception(
            "DATEADD requires datepart."
        );

    }

    if (!isset($column['number'])) {

        throw new Exception(
            "DATEADD requires number."
        );

    }

    continue;

}

/*
 * DATEDIFF Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "DATEDIFF"
) {

    if (!isset($column['datepart'])) {

        throw new Exception(
            "DATEDIFF requires datepart."
        );

    }

    if (!isset($column['start'])) {

        throw new Exception(
            "DATEDIFF requires start."
        );

    }

    if (!isset($column['end'])) {

        throw new Exception(
            "DATEDIFF requires end."
        );

    }

    continue;

}

}

/*
 * EOMONTH Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "EOMONTH"
) {

    if (!isset($column['start'])) {

        throw new Exception(
            "EOMONTH requires start."
        );

    }

    continue;

}

/*
 * ISDATE Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "ISDATE"
) {

    if (empty($column['column'])) {

        throw new Exception(
            "ISDATE requires column."
        );

    }

    continue;

}

/*
 * DATEFROMPARTS Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "DATEFROMPARTS"
) {

    if (!isset($column['year'])) {

        throw new Exception(
            "DATEFROMPARTS requires year."
        );

    }

    if (!isset($column['month'])) {

        throw new Exception(
            "DATEFROMPARTS requires month."
        );

    }

    if (!isset($column['day'])) {

        throw new Exception(
            "DATEFROMPARTS requires day."
        );

    }

    continue;

}

/*
 * DATETIMEFROMPARTS Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "DATETIMEFROMPARTS"
) {

    $required = [
        "year",
        "month",
        "day",
        "hour",
        "minute",
        "second",
        "millisecond"
    ];

    foreach ($required as $field) {

        if (!isset($column[$field])) {

            throw new Exception(
                "DATETIMEFROMPARTS requires {$field}."
            );

        }

    }

    continue;

}

/*
 * TIMEFROMPARTS Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "TIMEFROMPARTS"
) {

    $required = [
        "hour",
        "minute",
        "second",
        "fractions",
        "precision"
    ];

    foreach ($required as $field) {

        if (!isset($column[$field])) {

            throw new Exception(
                "TIMEFROMPARTS requires {$field}."
            );

        }

    }

    continue;

}

/*
 * SYSDATETIME Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "SYSDATETIME"
) {

    continue;

}

/*
 * CURRENT_TIMESTAMP Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "CURRENT_TIMESTAMP"
) {

    continue;

}

/*
 * IIF Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "IIF"
) {

    foreach (["condition", "true", "false"] as $field) {

        if (!isset($column[$field])) {

            throw new Exception(
                "IIF requires {$field}."
            );

        }

    }

    continue;
}

/*
 * CHOOSE Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "CHOOSE"
) {

    if (!isset($column['index'])) {

        throw new Exception(
            "CHOOSE requires index."
        );

    }

    if (
        empty($column['values'])
        || !is_array($column['values'])
    ) {

        throw new Exception(
            "CHOOSE requires values."
        );

    }

    if (count($column['values']) < 2) {

        throw new Exception(
            "CHOOSE requires at least two values."
        );

    }

    continue;

}

/*
 * ROW_NUMBER Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "ROW_NUMBER"
) {

    if (
        empty($column['orderBy'])
        || !is_array($column['orderBy'])
    ) {

        throw new Exception(
            "ROW_NUMBER requires orderBy."
        );

    }

    continue;

}

/*
 * RANK Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "RANK"
) {

    if (
        empty($column['orderBy']) ||
        !is_array($column['orderBy'])
    ) {

        throw new Exception(
            "RANK requires orderBy."
        );

    }

    continue;

}

/*
 * DENSE_RANK Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "DENSE_RANK"
) {

    if (
        empty($column['orderBy']) ||
        !is_array($column['orderBy'])
    ) {

        throw new Exception(
            "DENSE_RANK requires orderBy."
        );

    }

    continue;

}

/*
 * NTILE Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "NTILE"
) {

    if (!isset($column['buckets'])) {

        throw new Exception(
            "NTILE requires buckets."
        );

    }

    if (
        !is_numeric($column['buckets'])
        || $column['buckets'] <= 0
    ) {

        throw new Exception(
            "NTILE buckets must be greater than zero."
        );

    }

    if (
        empty($column['orderBy'])
        || !is_array($column['orderBy'])
    ) {

        throw new Exception(
            "NTILE requires orderBy."
        );

    }

    continue;

}

/*
 * LAG Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "LAG"
) {

    if (empty($column['column'])) {

        throw new Exception(
            "LAG requires column."
        );

    }

    if (
        empty($column['orderBy']) ||
        !is_array($column['orderBy'])
    ) {

        throw new Exception(
            "LAG requires orderBy."
        );

    }

    if (
        isset($column['offset']) &&
        (
            !is_numeric($column['offset']) ||
            $column['offset'] < 1
        )
    ) {

        throw new Exception(
            "LAG offset must be greater than zero."
        );

    }

    continue;

}

/*
 * LEAD Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "LEAD"
) {

    if (empty($column['column'])) {

        throw new Exception(
            "LEAD requires column."
        );

    }

    if (
        empty($column['orderBy']) ||
        !is_array($column['orderBy'])
    ) {

        throw new Exception(
            "LEAD requires orderBy."
        );

    }

    if (
        isset($column['offset']) &&
        (
            !is_numeric($column['offset']) ||
            $column['offset'] < 1
        )
    ) {

        throw new Exception(
            "LEAD offset must be greater than zero."
        );

    }

    continue;

}

/*
 * FIRST_VALUE Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "FIRST_VALUE"
) {

    if (empty($column['column'])) {

        throw new Exception(
            "FIRST_VALUE requires column."
        );

    }

    if (
        empty($column['orderBy']) ||
        !is_array($column['orderBy'])
    ) {

        throw new Exception(
            "FIRST_VALUE requires orderBy."
        );

    }

    continue;

}

/*
 * LAST_VALUE Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "LAST_VALUE"
) {

    if (empty($column['column'])) {

        throw new Exception(
            "LAST_VALUE requires column."
        );

    }

    if (
        empty($column['orderBy']) ||
        !is_array($column['orderBy'])
    ) {

        throw new Exception(
            "LAST_VALUE requires orderBy."
        );

    }

    continue;

}

/*
 * STRING_AGG Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "STRING_AGG"
) {

    if (empty($column['column'])) {

        throw new Exception(
            "STRING_AGG requires column."
        );

    }

    if (!isset($column['separator'])) {

        throw new Exception(
            "STRING_AGG requires separator."
        );

    }

    if (
        isset($column['orderBy']) &&
        !is_array($column['orderBy'])
    ) {

        throw new Exception(
            "STRING_AGG orderBy must be an array."
        );

    }

    continue;

}

/*
 * CAST / CONVERT Validation
 */
if (
    isset($column['function']) &&
    in_array(
        strtoupper($column['function']),
        [
            "CAST",
            "CONVERT"
        ]
    )
) {

    if (empty($column['datatype'])) {
        throw new Exception(
            strtoupper($column['function'])
            . " requires datatype."
        );
    }

    continue;
}

/*
 * NULLIF Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "NULLIF"
) {

    if (!array_key_exists("value", $column)) {
        throw new Exception(
            "NULLIF requires value."
        );
    }

    continue;
}

/*
 * CONCAT Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "CONCAT"
) {

    if (
        empty($column['columns']) ||
        !is_array($column['columns']) ||
        count($column['columns']) < 2
    ) {

        throw new Exception(
            "CONCAT requires at least two values."
        );

    }

    foreach ($column['columns'] as $value) {

        if (
            is_string($value) &&
            preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $value)
        ) {

            $resolved =
                $this->expressionBuilder->resolveColumn($value);

            $table =
                $resolved['table']
                ?? $request['table'];

            if (
                !$this->metadataRepository->columnExists(
                    $table,
                    $resolved['column']
                )
            ) {

                throw new Exception(
                    "Invalid column: {$value}"
                );

            }

        }

    }

    continue;

}

/*
 * LEFT / RIGHT Validation
 */
if (
    isset($column['function']) &&
    in_array(
        strtoupper($column['function']),
        [
            "LEFT",
            "RIGHT"
        ]
    )
) {

    if (!isset($column['length'])) {

        throw new Exception(
            strtoupper($column['function'])
            . " requires length."
        );

    }

    continue;

}

/*
 * SUBSTRING Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function'])
    == "SUBSTRING"
) {

    if (!isset($column['start'])) {

        throw new Exception(
            "SUBSTRING requires start."
        );

    }

    if (!isset($column['length'])) {

        throw new Exception(
            "SUBSTRING requires length."
        );

    }

    continue;

}

/*
 * REPLACE Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "REPLACE"
) {

    if (!array_key_exists("search", $column)) {

        throw new Exception(
            "REPLACE requires search."
        );

    }

    if (!array_key_exists("replace", $column)) {

        throw new Exception(
            "REPLACE requires replace."
        );

    }

    continue;

}

/*
 * CHARINDEX Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "CHARINDEX"
) {

    if (!array_key_exists("search", $column)) {

        throw new Exception(
            "CHARINDEX requires search."
        );

    }

    continue;

}

/*
 * PATINDEX Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "PATINDEX"
) {

    if (!array_key_exists("pattern", $column)) {

        throw new Exception(
            "PATINDEX requires pattern."
        );

    }

    continue;

}

/*
 * FORMAT Validation
 */
if (
    isset($column['function']) &&
    strtoupper($column['function']) == "FORMAT"
) {

    if (!array_key_exists("format", $column)) {

        throw new Exception(
            "FORMAT requires format."
        );

    }

    continue;

}

        /*
         * Normal Column With Alias
         */
        if (!isset($column['function'])) {

            $resolved = $this->expressionBuilder->resolveColumn($column['column']);

            $table = $resolved['table'] ?? $request['table'];

            $isCTEColumn =
            (
                isset($request['cte']) &&
                $table === $request['cte']['name']
            )
            ||
            (
              isset($request['recursiveCte']) &&
                $table === $request['recursiveCte']['name']
            );

            if ($isCTEColumn) {
                continue;
}

            if (
                !$this->metadataRepository->columnExists(
                    $table,
                    $resolved['column']
                )
            ) {
                throw new Exception(
                    "Invalid column: {$column['column']}"
                );
            }
        }

    } else {

        // Allow SELECT * where every column of every source may be read.
        if ($column === "*") {
            $this->metadataRepository->assertAllColumnsReadable($request['table']);
            foreach ($request['joins'] ?? [] as $join) {
                $this->metadataRepository->assertAllColumnsReadable($join['alias'] ?? $join['table']);
            }
            continue;
        }

        $resolved = $this->expressionBuilder->resolveColumn($column);

        $table = $resolved['table'] ?? $request['table'];

        if (
            !$this->metadataRepository->columnExists(
                $table,
                $resolved['column']
            )
        ) {
            throw new Exception(
                "Invalid column: {$column}"
            );
        }
        }
    }

    /*
     * Build SELECT Columns
     */
    $selectColumns = [];

    foreach ($request['columns'] as $column) {

        /*
         * Plain column
         */
        if (is_string($column)) {

            $selectColumns[] = $column;
            continue;
        }

        if (isset($column['node'])) {
            $alias = $column['alias'] ?? $this->defaultNodeAlias($column['node']);
            $selectColumns[] = $this->renderCanonicalProjection($column['node'], $request, $alias, $params);
            continue;
        }

/*
 * CASE Expression
 */
if (isset($column['case'])) {

    $case = $column['case'];

    $alias =
        $case['alias']
        ?? "CaseValue";

    $caseSql = "CASE ";

    foreach ($case['when'] as $when) {

        $condition =
            $when['condition'];

        $resolved =
            $this->expressionBuilder->resolveColumn(
                $condition['column']
            );

        if (!empty($resolved['table'])) {

            $columnName =
                $resolved['table']
                . "."
                . $resolved['column'];

        } else {

            $columnName =
                $resolved['column'];

        }

        $value = $this->expressionBuilder->buildValue($condition['value']);
        $then = $this->expressionBuilder->buildValue($when['then']);

        $caseSql .=
            "WHEN "
            . $columnName
            . " "
            . $condition['operator']
            . " "
            . $value
            . " THEN "
            . $then
            . " ";
    }

    if (array_key_exists("else", $case)) {

        $else = $this->expressionBuilder->buildValue($case['else']);

        $caseSql .=
            "ELSE "
            . $else
            . " ";
    }

    $caseSql .=
        "END AS [{$alias}]";

    $selectColumns[] =
        $caseSql;

    continue;
}        

/*
 * Arithmetic Expression
 */
if (isset($column['expression'])) {

    $expression = $column['expression'];

    foreach (['left','right'] as $side) {
        if (is_numeric($expression[$side])) {
            $$side = $expression[$side];
        } else {
            $resolved = $this->expressionBuilder->resolveColumn($expression[$side]);
            $$side = !empty($resolved['table'])
                ? $resolved['table'] . "." . $resolved['column']
                : $resolved['column'];
        }
    }

    $alias = $column['alias'] ?? "Expression";
    $selectColumns[] = "(" . $left . " " . $expression['operator'] . " "
        . $right . ") AS [" . $alias . "]";

    continue;

}

/*
 * Aggregate / String / Date Function
 */
if (isset($column['function'])) {

    $aggregateFunctions = [
        "COUNT",
        "SUM",
        "AVG",
        "MIN",
        "MAX",
        "STRING_AGG",
    ];

    $stringFunctions = [
        "UPPER",
        "LOWER",
        "LTRIM",
        "RTRIM",
        "TRIM",
        "LEN",

        "COALESCE",
        "ISNULL",

        "CAST",
        "CONVERT",

        "NULLIF",

        "CONCAT",

        "LEFT",
        "RIGHT",
        "SUBSTRING",

        "REPLACE",

        "CHARINDEX",

        "PATINDEX",

        "FORMAT",

        "CHOOSE",
    ];

    $dateFunctions = [
        "YEAR",
        "MONTH",
        "DAY",
        "DATEPART",
        "DATENAME",
        "GETDATE",
        "DATEADD",
        "DATEDIFF",
        "EOMONTH",
        "ISDATE",
        "DATEFROMPARTS",
        "DATETIMEFROMPARTS",
        "TIMEFROMPARTS",
        "SYSDATETIME",
        "CURRENT_TIMESTAMP",
        "IIF",
    ];

    $mathFunctions = [
        "ABS",
        "ROUND",
        "CEILING",
        "FLOOR",
        "POWER",
        "SQRT",
        "EXP",
        "LOG",
    ];

    $windowFunctions = [
        "ROW_NUMBER",
        "RANK",
        "DENSE_RANK",
        "NTILE",
        "LAG",
        "LEAD",
        "FIRST_VALUE",
        "LAST_VALUE"
    ];

    $function =
        strtoupper($column['function']);

    if (
        !in_array($function,$aggregateFunctions)
        &&
        !in_array($function,$stringFunctions)
        &&
        !in_array($function,$dateFunctions)
        &&
        !in_array($function, $mathFunctions)
        &&
        !in_array($function, $windowFunctions)
    ) {
        throw new Exception(
            "Invalid SQL function: {$column['function']}"
        );
    }

    $alias =
        $column['alias']
        ?? strtolower($column['function']);

/*
 * Resolve column for functions that require one
 */
$resolvedColumn = null;

if (isset($column['column'])) {

    $resolved =
        $this->expressionBuilder->resolveColumn(
            $column['column']
        );

    if (!empty($resolved['table'])) {
        $resolvedColumn =
            $resolved['table']
            . "."
            . $resolved['column'];
    } else {
        $resolvedColumn =
            $resolved['column'];
    }
}

    /*
     * GETDATE()
     */
    if ($function == "GETDATE") {

        $selectColumns[] =
            "GETDATE() AS [{$alias}]";

        continue;
    }

/*
 * DATEPART() / DATENAME()
 */
if (
    $function == "DATEPART"
    ||
    $function == "DATENAME"
) {

    if (empty($column['part'])) {
        throw new Exception(
            "{$function} requires 'part'."
        );
    }

    $dateColumn =
        "CONVERT(date, CAST("
        . $column['column']
        . " AS varchar(8)), 112)";

    $selectColumns[] =
        $function
        . "("
        . strtoupper($column['part'])
        . ", "
        . $dateColumn
        . ") AS ["
        . $alias
        . "]";

    continue;
}

/*
 * YEAR() / MONTH() / DAY()
 */
if (
    in_array(
        $function,
        [
            "YEAR",
            "MONTH",
            "DAY"
        ]
    )
) {

    $dateColumn =
        "CONVERT(date, CAST("
        . $column['column']
        . " AS varchar(8)), 112)";

    $selectColumns[] =
        $function
        . "("
        . $dateColumn
        . ") AS ["
        . $alias
        . "]";

    continue;
}

/*
 * Mathematical Functions
 */
if (
    in_array(
        $function,
        [
            "ABS",
            "CEILING",
            "FLOOR",
            "SQRT",
            "EXP",
            "LOG"
        ]
    )
) {

    $selectColumns[] =
        $function
        . "("
        . $resolvedColumn
        . ") AS ["
        . $alias
        . "]";

    continue;
}

/*
 * ROUND()
 */
if ($function == "ROUND") {

    $precision =
        $column['precision']
        ?? 0;

    $selectColumns[] =
        "ROUND("
        . $resolvedColumn
        . ", "
        . (int)$precision
        . ") AS ["
        . $alias
        . "]";

    continue;
}

/*
 * POWER()
 */
if ($function == "POWER") {

    if (!isset($column['power'])) {
        throw new Exception(
            "POWER requires 'power'."
        );
    }

    $selectColumns[] =
        "POWER("
        . $resolvedColumn
        . ", "
        . (float)$column['power']
        . ") AS ["
        . $alias
        . "]";

    continue;
}

/*
 * COALESCE()
 */
if ($function == "COALESCE") {

    if (
        empty($column['columns'])
        || !is_array($column['columns'])
    ) {
        throw new Exception(
            "COALESCE requires columns."
        );
    }

    $coalesceColumns = [];

    foreach ($column['columns'] as $coalesceColumn) {

        $resolved =
            $this->expressionBuilder->resolveColumn(
                $coalesceColumn
            );

        if (!empty($resolved['table'])) {

            $coalesceColumns[] =
                $resolved['table']
                . "."
                . $resolved['column'];

        } else {

            $coalesceColumns[] =
                $resolved['column'];

        }
    }

    if (isset($column['default'])) {
        $coalesceColumns[] = $this->expressionBuilder->buildValue($column['default']);
    }

    $selectColumns[] =
        "COALESCE("
        . implode(", ", $coalesceColumns)
        . ") AS [{$alias}]";

    continue;
}

/*
 * ISNULL()
 */
if ($function == "ISNULL") {

    if (empty($column['column'])) {
        throw new Exception(
            "ISNULL requires column."
        );
    }

    if (!array_key_exists("default", $column)) {
        throw new Exception(
            "ISNULL requires default."
        );
    }

    $default = $this->expressionBuilder->buildValue($column['default']);

    $selectColumns[] =
        "ISNULL("
        . $resolvedColumn
        . ", "
        . $default
        . ") AS [{$alias}]";

    continue;
}
/*
 * CAST()
 */
if ($function == "CAST") {

    if (empty($column['datatype'])) {
        throw new Exception(
            "CAST requires datatype."
        );
    }

    $selectColumns[] =
        "CAST("
        . $resolvedColumn
        . " AS "
        . strtoupper($column['datatype'])
        . ") AS ["
        . $alias
        . "]";

    continue;
}

/*
 * CONVERT()
 */
if ($function == "CONVERT") {

    if (empty($column['datatype'])) {
        throw new Exception(
            "CONVERT requires datatype."
        );
    }

    $sql =
        "CONVERT("
        . strtoupper($column['datatype'])
        . ", "
        . $resolvedColumn;

    if (isset($column['style'])) {

        $sql .=
            ", "
            . (int)$column['style'];

    }

    $sql .=
        ") AS ["
        . $alias
        . "]";

    $selectColumns[] = $sql;

    continue;
}

/*
 * NULLIF()
 */
if ($function == "NULLIF") {

    if (!array_key_exists("value", $column)) {
        throw new Exception(
            "NULLIF requires value."
        );
    }

    $value = $this->expressionBuilder->buildValue($column['value']);

    $selectColumns[] =
        "NULLIF("
        . $resolvedColumn
        . ", "
        . $value
        . ") AS ["
        . $alias
        . "]";

    continue;
}

/*
 * CONCAT()
 */
if ($function == "CONCAT") {

    $parts = [];

    foreach ($column['columns'] as $value) {

        if (is_numeric($value)) {

            $parts[] = $value;

        } elseif (
            is_string($value) &&
            preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $value)
        ) {

            $resolved =
                $this->expressionBuilder->resolveColumn($value);

            if (!empty($resolved['table'])) {

                $parts[] =
                    $resolved['table']
                    . "."
                    . $resolved['column'];

            } else {

                $parts[] =
                    $resolved['column'];

            }

        } else {

            $parts[] =
                "'" .
                str_replace("'", "''", $value)
                . "'";

        }

    }

    $selectColumns[] =
        "CONCAT("
        . implode(", ", $parts)
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * LEFT()
 */
if ($function == "LEFT") {

    $selectColumns[] =
        "LEFT("
        . $resolvedColumn
        . ", "
        . (int)$column['length']
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * RIGHT()
 */
if ($function == "RIGHT") {

    $selectColumns[] =
        "RIGHT("
        . $resolvedColumn
        . ", "
        . (int)$column['length']
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * SUBSTRING()
 */
if ($function == "SUBSTRING") {

    $selectColumns[] =
        "SUBSTRING("
        . $resolvedColumn
        . ", "
        . (int)$column['start']
        . ", "
        . (int)$column['length']
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * REPLACE()
 */
if ($function == "REPLACE") {

    $search =
        str_replace(
            "'",
            "''",
            $column['search']
        );

    $replace =
        str_replace(
            "'",
            "''",
            $column['replace']
        );

    $selectColumns[] =
        "REPLACE("
        . $resolvedColumn
        . ", '"
        . $search
        . "', '"
        . $replace
        . "') AS ["
        . $alias
        . "]";

    continue;

}

/*
 * CHARINDEX()
 */
if ($function == "CHARINDEX") {

    $search =
        str_replace(
            "'",
            "''",
            $column['search']
        );

    $selectColumns[] =
        "CHARINDEX('"
        . $search
        . "', "
        . $resolvedColumn
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * PATINDEX()
 */
if ($function == "PATINDEX") {

    $pattern =
        str_replace(
            "'",
            "''",
            $column['pattern']
        );

    $selectColumns[] =
        "PATINDEX('"
        . $pattern
        . "', CAST("
        . $resolvedColumn
        . " AS NVARCHAR(MAX))) AS ["
        . $alias
        . "]";

    continue;

}

/*
 * FORMAT()
 */
if ($function == "FORMAT") {

    $format = str_replace(
        "'",
        "''",
        $column['format']
    );

    if (isset($column['style'])) {

        $style = (int)$column['style'];

        $selectColumns[] =
            "FORMAT("
            . "CONVERT(date, CAST("
            . $resolvedColumn
            . " AS varchar(8)), "
            . $style
            . "), '"
            . $format
            . "') AS ["
            . $alias
            . "]";

    } else {

        $selectColumns[] =
            "FORMAT("
            . $resolvedColumn
            . ", '"
            . $format
            . "') AS ["
            . $alias
            . "]";

    }

    continue;

}

/*
 * DATEADD()
 */
if ($function == "DATEADD") {

    $datePart = strtoupper($column['datepart']);

    $allowedParts = [
        "YEAR",
        "MONTH",
        "DAY",
        "HOUR",
        "MINUTE",
        "SECOND"
    ];

    if (!in_array($datePart, $allowedParts)) {

        throw new Exception(
            "Invalid DATEADD datepart."
        );

    }

    /*
     * Legacy YYYYMMDD Support
     */
    if (isset($column['style'])) {

        $style = (int)$column['style'];

        $dateExpression =
            "CONVERT(date, CAST("
            . $resolvedColumn
            . " AS varchar(8)), "
            . $style
            . ")";

    } else {

        $dateExpression = $resolvedColumn;

    }

    $selectColumns[] =
        "DATEADD("
        . $datePart
        . ", "
        . (int)$column['number']
        . ", "
        . $dateExpression
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * DATEDIFF()
 */
if ($function == "DATEDIFF") {

    $datePart = strtoupper($column['datepart']);

    $allowedParts = [
        "YEAR",
        "QUARTER",
        "MONTH",
        "DAYOFYEAR",
        "DAY",
        "WEEK",
        "WEEKDAY",
        "HOUR",
        "MINUTE",
        "SECOND",
        "MILLISECOND"
    ];

    if (!in_array($datePart, $allowedParts)) {

        throw new Exception(
            "Invalid DATEDIFF datepart."
        );

    }

    /*
     * START
     */
    if (isset($column['start']['function'])) {

        $start =
            strtoupper($column['start']['function'])
            . "()";

    } else {

        $start = $column['start']['column'];

        if (isset($column['start']['style'])) {

            $start =
                "CONVERT(date, CAST("
                . $start
                . " AS varchar(8)), "
                . (int)$column['start']['style']
                . ")";
        }
    }

    /*
     * END
     */
    if (isset($column['end']['function'])) {

        $end =
            strtoupper($column['end']['function'])
            . "()";

    } else {

        $end = $column['end']['column'];

        if (isset($column['end']['style'])) {

            $end =
                "CONVERT(date, CAST("
                . $end
                . " AS varchar(8)), "
                . (int)$column['end']['style']
                . ")";
        }
    }

    $selectColumns[] =
        "DATEDIFF("
        . $datePart
        . ", "
        . $start
        . ", "
        . $end
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * EOMONTH()
 */
if ($function == "EOMONTH") {

    /*
     * START
     */
    if (isset($column['start']['function'])) {

        $start =
            strtoupper(
                $column['start']['function']
            ) . "()";

    } else {

        $resolved =
            $this->expressionBuilder->resolveColumn(
                $column['start']['column']
            );

        if (!empty($resolved['table'])) {

            $start =
                $resolved['table']
                . "."
                . $resolved['column'];

        } else {

            $start =
                $resolved['column'];

        }

        if (isset($column['start']['style'])) {

            $start =
                "CONVERT(date, CAST("
                . $start
                . " AS varchar(8)), "
                . (int)$column['start']['style']
                . ")";

        }

    }

    $monthOffset =
        $column['month']
        ?? 0;

    $selectColumns[] =
        "EOMONTH("
        . $start
        . ", "
        . (int)$monthOffset
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * ISDATE()
 */
if ($function == "ISDATE") {

    $value = $resolvedColumn;

    if (isset($column['style'])) {

        $value =
            "CONVERT(varchar(50), "
            . $resolvedColumn
            . ", "
            . (int)$column['style']
            . ")";

    }

    $selectColumns[] =
        "ISDATE("
        . $value
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * DATEFROMPARTS()
 */
if ($function == "DATEFROMPARTS") {

    $year = is_array($column['year'])
        ? $this->expressionBuilder->buildExpression($column['year'])
        : (int)$column['year'];

    $month = is_array($column['month'])
        ? $this->expressionBuilder->buildExpression($column['month'])
        : (int)$column['month'];

    $day = is_array($column['day'])
        ? $this->expressionBuilder->buildExpression($column['day'])
        : (int)$column['day'];

    $selectColumns[] =
        "DATEFROMPARTS("
        . $year
        . ", "
        . $month
        . ", "
        . $day
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * DATETIMEFROMPARTS()
 */
if ($function == "DATETIMEFROMPARTS") {

    $parts = [];

    foreach ([
        "year",
        "month",
        "day",
        "hour",
        "minute",
        "second",
        "millisecond"
    ] as $part) {

        if (is_array($column[$part])) {

            $parts[] =
                $this->expressionBuilder->buildExpression(
                    $column[$part]
                );

        } else {

            $parts[] =
                (int)$column[$part];

        }

    }

    $selectColumns[] =
        "DATETIMEFROMPARTS("
        . implode(", ", $parts)
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * TIMEFROMPARTS()
 */
if ($function == "TIMEFROMPARTS") {

    $parts = [];

    foreach ([
        "hour",
        "minute",
        "second",
        "fractions",
        "precision"
    ] as $part) {

        if (is_array($column[$part])) {

            $parts[] =
                $this->expressionBuilder->buildExpression(
                    $column[$part]
                );

        } else {

            $parts[] =
                (int)$column[$part];

        }

    }

    $selectColumns[] =
        "TIMEFROMPARTS("
        . implode(", ", $parts)
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * SYSDATETIME()
 */
if ($function == "SYSDATETIME") {

    $selectColumns[] =
        "SYSDATETIME() AS ["
        . $alias
        . "]";

    continue;

}

/*
 * CURRENT_TIMESTAMP
 */
if ($function == "CURRENT_TIMESTAMP") {

    $selectColumns[] =
        "CURRENT_TIMESTAMP AS ["
        . $alias
        . "]";

    continue;

}

/*
 * IIF()
 */
if ($function == "IIF") {

    $selectColumns[] =
        "IIF("
        . $this->expressionBuilder->buildCondition($column["condition"])
        . ", "
        . $this->expressionBuilder->buildExpression($column["true"])
        . ", "
        . $this->expressionBuilder->buildExpression($column["false"])
        . ") AS ["
        . $alias
        . "]";

    continue;

}

/*
 * CHOOSE()
 */
if ($function == "CHOOSE") {

    $values = [];

    foreach ($column["values"] as $value) {

        $values[] =
            $this->expressionBuilder->buildExpression($value);

    }

    $selectColumns[] =
        "CHOOSE("
        . (int)$column["index"]
        . ", "
        . implode(", ", $values)
        . ") AS ["
        . $alias
        . "]";

    continue;

}
/* Window Functions */
$windowSql = $this->windowFunctionBuilder->build(
    $function,
    $column,
    $request,
    $alias,
    $resolvedColumn,
    $params
);
if ($windowSql !== null) {
    $selectColumns[] = $windowSql;
    continue;
}

/*
 * STRING_AGG()
 */
if ($function == "STRING_AGG") {

    $separator =
        str_replace(
            "'",
            "''",
            $column["separator"]
        );

    $sql =
        "STRING_AGG("
        . $resolvedColumn
        . ", '"
        . $separator
        . "')";

    if (!empty($column["orderBy"])) {

        $orders = $this->orderByBuilder->buildItems(
            $column["orderBy"],
            $request
        );

        $sql .=
            " WITHIN GROUP (ORDER BY "
            . implode(", ", $orders)
            . ")";

    }

    $sql .=
        " AS ["
        . $alias
        . "]";

    $selectColumns[] = $sql;

    continue;

}

    /*
     * Aggregate & String Functions
     */
    $selectColumns[] =
        $function
        . "("
        . $column['column']
        . ") AS ["
        . $alias
        . "]";

    continue;
}

        /*
         * Normal Column With Alias
         */
        $sqlColumn = $column['column'];

        if (!empty($column['alias'])) {
            $sqlColumn .= " AS [" . $column['alias'] . "]";
        }

        $selectColumns[] = $sqlColumn;
    }

    $columns = implode(", ", $selectColumns);

    $distinct = "";

    if (!empty($request['distinct'])) {
        $distinct = "DISTINCT";
    }

    $top = "";

    if (!empty($request['top'])) {

        if (
            !is_numeric($request['top']) ||
            $request['top'] <= 0
        ) {
            throw new Exception(
                "TOP must be a positive number."
            );
        }

        $top = "TOP " . (int)$request['top'];
    }

    $baseAlias = "";

    if (!empty($request['alias'])) {
        $baseAlias = " " . $request['alias'];
    }

    $from = $baseSource !== null ? $baseSource->renderFrom($this->isCrossDatabase()) : $request['table'] . $baseAlias;

    $sql = "
        SELECT
        {$distinct} {$top} {$columns}
    FROM
        {$from}
    ";
        $sql .= $this->joinBuilder->build($request);

        $where = $this->whereBuilder->build($request, $params);
        $sql .= $where['sql'];
        $params = $where['params'];

        $sql .= $this->groupByBuilder->build($request, $params);

        $having = $this->havingBuilder->build($request, $params);
        $sql .= $having['sql'];
        $params = $having['params'];

     /*
     * ORDER BY
     */
    $sqlWithoutOrderBy = $sql;
    $paramsWithoutOrderBy = $params;
    $paginationOrderBy = null;

    if (!$isUnion) {

    if (!empty($request['sort'])) {

        $orders = $this->orderByBuilder->buildItems(
            $request['sort'],
            $request,
            true,
            false,
            false,
            $params
        );

        $paginationOrders = $this->orderByBuilder->buildItems(
            $request['sort'],
            $request,
            true,
            true,
            true
        );

        $sql .= " ORDER BY " . implode(", ", $orders);
        $paginationOrderBy =
            "ORDER BY " . implode(", ", $paginationOrders);

    }
    elseif (!empty($request['groupBy'])) {

        if (is_array($request['groupBy'][0])) {
            $sql .= " ORDER BY "
                . $this->expressionBuilder->renderNode($request['groupBy'][0], $params)
                . " ASC";
            $paginationOrderBy = "ORDER BY "
                . $this->orderByBuilder->getDefaultColumn($request)
                . " ASC";
        } else {
            $sql .= " ORDER BY " . $request['groupBy'][0];
            $resolved = $this->expressionBuilder->resolveColumn($request['groupBy'][0]);
            $paginationOrderBy =
                "ORDER BY [" . $resolved['column'] . "] ASC";
        }

    }
    else {

        $defaultOrderColumn =
            $this->orderByBuilder->getDefaultColumn($request);

        $sql .= " ORDER BY " . $defaultOrderColumn . " ASC";
        $paginationOrderBy =
            "ORDER BY " . $defaultOrderColumn . " ASC";

    }

    }

if ($this->logger !== null) {
    $this->logger->timing('sql_generation', (microtime(true) - $generationStarted) * 1000, [
        'action' => 'select',
        'pagination' => isset($request['page'], $request['pageSize']),
    ]);
}

$pagination = $this->paginationBuilder->apply(
            $sql,
            $sqlWithoutOrderBy,
            $params,
            $request,
            $paginationOrderBy,
            true,
            $cteSql,
            '',
            $paramsWithoutOrderBy
        );
        $sql = $cteSql . $pagination['sql'];
        $totalRows = $pagination['totalRows'];

        return [
            'sql' => $sql,
            'params' => $params,
            'totalRows' => $totalRows,
            'columnCount' => count($this->getOutputColumns($request)),
        ];
    }

    private function buildNested(array $request, bool $isUnion): array
    {
        $tables = $this->expressionBuilder->getTables();
        $querySources = $this->expressionBuilder->getSources();
        $virtualTables = $this->metadataRepository->getVirtualTables();
        $physicalSources = $this->metadataRepository->getPhysicalSources();
        if (!isset($request['_virtualTables']) && $virtualTables !== []) {
            $request['_virtualTables'] = $virtualTables;
        }
        try {
            return $this->build($request, $isUnion);
        } finally {
            $this->expressionBuilder->setTables($tables);
            $this->expressionBuilder->setSources($querySources);
            $this->metadataRepository->setVirtualTables($virtualTables);
            $this->metadataRepository->setPhysicalSources($physicalSources, $this->primaryDatabaseId());
        }
    }

    /**
     * Resolve the base and JOIN sources of one SELECT body to QuerySources
     * when the request has a database plan, and scope metadata lookups to
     * their physical objects. Each JOIN keeps its QuerySource in `_source`,
     * its FROM text in `_from`, and its metadata key in `_lookup`. Without a plan, sources keep their
     * unresolved V2 handling.
     *
     * A single-database plan keys objects by table name, exactly as V2 looked
     * them up. A cross-database plan keys them by source reference (alias,
     * else name), which expressions resolve to, and also by table name where
     * that is unambiguous; every physical source then renders database-
     * qualified, so each keeps its own database on the one connection.
     */
    private function resolveSources(array &$request, bool $isCTE): ?QuerySource
    {
        $joins = $request['joins'] ?? [];
        if ($this->sources === null) {
            foreach ([$request, ...$joins] as $source) {
                if (isset($source['database']) || isset($source['schema'])) {
                    throw new LogicException('Sources with a database or schema require a database query plan.');
                }
            }
            $this->metadataRepository->setPhysicalSources([]);
            return null;
        }
        $cross = $this->isCrossDatabase();
        $sources = ['source' => $this->querySource($request, $isCTE, 'source')];
        foreach ($joins as $index => $join) {
            $source = $this->querySource($join, false, "joins.{$index}.source");
            $sources["joins.{$index}.source"] = $source;
            $request['joins'][$index]['_source'] = $source;
            $request['joins'][$index]['_from'] = $source->renderFrom($cross);
            $request['joins'][$index]['_lookup'] = $cross && !$source->isVirtual() ? $source->reference() : $join['table'];
        }

        $physical = [];
        foreach ($sources as $path => $source) {
            if ($source->isVirtual()) continue;
            $keys = $cross ? ['reference' => $source->reference(), 'name' => $source->name] : ['name' => $source->name];
            foreach ($keys as $kind => $key) {
                $key = strtolower($key);
                if (isset($physical[$key]) && !$physical[$key]->equals($source->object)) {
                    // In a cross-database query a shared table name is reached through aliases.
                    if ($cross && $kind === 'name') continue;
                    throw new ApiRequestException('Invalid request.', 'INVALID_REQUEST', [
                        ['path' => $path, 'message' => $cross
                            ? 'Sources that share a table name or alias need distinct aliases.'
                            : 'Sources with the same table name must name the same object.'],
                    ]);
                }
                $physical[$key] = $source->object;
            }
        }
        $this->metadataRepository->setPhysicalSources($physical, $this->primaryDatabaseId());
        return $sources['source'];
    }

    private function querySource(array $source, bool $isCTE, string $path): QuerySource
    {
        $virtual = $isCTE;
        foreach (array_keys($this->metadataRepository->getVirtualTables()) as $name) {
            $virtual = $virtual || strcasecmp($name, (string)$source['table']) === 0;
        }
        if ($virtual) {
            return QuerySource::virtual($source['table'], $source['alias'] ?? null);
        }
        // The plan holds the only resolved databases: any of them, never another.
        return $this->sources->resolve($source, $path);
    }

    /**
     * Expressions resolve `alias.column` (or `name.column`) to the source's
     * reference, which keys its physical object; the alias, never the physical
     * name, stays the qualifier in generated SQL.
     */
    private function registerCrossDatabaseSources(array $request, QuerySource $base): void
    {
        $sources = [$base, ...array_map(fn (array $join): QuerySource => $join['_source'], $request['joins'] ?? [])];
        foreach ($sources as $source) {
            // Physical sources are keyed by reference; CTEs keep their name.
            $key = $source->isVirtual() ? $source->name : $source->reference();
            $this->expressionBuilder->registerSource($source->reference(), $source);
            if (!isset($this->expressionBuilder->getTables()[$source->name])) {
                $this->expressionBuilder->registerTable($source->name, $key);
            }
            if ($source->alias !== null) {
                $this->expressionBuilder->registerTable($source->alias, $key);
            }
        }
    }

    private function isCrossDatabase(): bool
    {
        return $this->sources !== null && $this->sources->plan()->isCrossDatabase;
    }

    private function primaryDatabaseId(): ?string
    {
        return $this->sources?->plan()->primaryDatabase->id;
    }

    private function getOutputColumns(array $request): array
    {
        $columns = [];
        foreach ($request['columns'] as $column) {
            if ($column === '*') {
                $metadata = $this->metadataRepository->getColumns($request['table']);
                foreach ($metadata['data'] ?? [] as $entry) {
                    if (!empty($entry['COLUMN_NAME'])) {
                        $columns[] = $entry['COLUMN_NAME'];
                    }
                }
                continue;
            }
            if (is_string($column)) {
                $parts = explode('.', $column);
                $columns[] = end($parts);
                continue;
            }
            if (!empty($column['alias'])) {
                $columns[] = $column['alias'];
            } elseif (isset($column['node'])) {
                $columns[] = $this->defaultNodeAlias($column['node']);
            } elseif (isset($column['case'])) {
                $columns[] = $column['case']['alias'] ?? 'CaseValue';
            } elseif (isset($column['expression'])) {
                $columns[] = 'Expression';
            } elseif (isset($column['function'])) {
                $columns[] = strtolower($column['function']);
            } elseif (!empty($column['column'])) {
                $parts = explode('.', $column['column']);
                $columns[] = end($parts);
            }
        }
        return $columns;
    }

    private function validateExpressionColumns($value, array $request): void
    {
        if (!is_array($value)) {
            return;
        }
        if (array_is_list($value)) {
            foreach ($value as $item) {
                $this->validateExpressionColumns($item, $request);
            }
            return;
        }
        if (isset($value['column']) && $value['column'] !== '*') {
            $resolved = $this->expressionBuilder->resolveColumn($value['column']);
            $table = $resolved['table'] ?? $request['table'];
            if (!$this->metadataRepository->columnExists($table, $resolved['column'])) {
                throw new Exception("Invalid expression column: {$value['column']}");
            }
        }
        foreach ($value as $key => $item) {
            if ($key === 'orderBy') {
                continue;
            }
            if (is_array($item)) {
                $this->validateExpressionColumns($item, $request);
            }
        }
    }

    private function validateCanonicalExpressionColumns(array $node, array $request): void
    {
        foreach ($this->expressionBuilder->fieldNames($node) as $column) {
            $resolved = $this->expressionBuilder->resolveColumn($column);
            $table = $resolved['table'] ?? $request['table'];
            if (!$this->metadataRepository->columnExists($table, $resolved['column'])) {
                throw new Exception("Invalid expression column: {$column}");
            }
        }
    }

    private function defaultNodeAlias(array $node): string
    {
        if (($node['type'] ?? null) === 'function') {
            return strtolower((string)$node['name']);
        }
        if (($node['type'] ?? null) === 'case') {
            return 'CaseValue';
        }
        if (($node['type'] ?? null) === 'field') {
            $parts = explode('.', (string)$node['name']);
            return (string)end($parts);
        }
        return 'Expression';
    }

    private function renderCanonicalProjection(
        array $node,
        array $request,
        string $alias,
        array &$params
    ): string {
        if (($node['type'] ?? null) !== 'function'
            || !QueryFunctionRegistry::isWindow((string)($node['name'] ?? ''))) {
            return $this->expressionBuilder->renderNode($node, $params) . ' AS [' . $alias . ']';
        }

        $column = [
            'orderBy' => $node['orderBy'] ?? [],
            'partitionBy' => $node['partitionBy'] ?? [],
        ] + ($node['options'] ?? []);
        $input = isset($node['input'])
            ? $this->expressionBuilder->renderNode($node['input'], $params)
            : null;
        $sql = $this->windowFunctionBuilder->build(
            strtoupper((string)$node['name']),
            $column,
            $request,
            $alias,
            $input,
            $params
        );
        if ($sql === null) {
            throw new Exception('Unsupported canonical window function.');
        }
        return $sql;
    }
}
