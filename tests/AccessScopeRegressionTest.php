<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanner.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanContext.php';
require_once __DIR__ . '/../app/Database/DatabaseDirectory.php';
require_once __DIR__ . '/../app/Database/DatabaseReferenceCollector.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/QueryRepository.php';
require_once __DIR__ . '/../app/Repositories/SqlRepository.php';
require_once __DIR__ . '/../app/Repositories/MetadataRepository.php';
require_once __DIR__ . '/../app/Services/MetadataService.php';
require_once __DIR__ . '/../app/Middleware/AuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/DatabaseAvailabilityMiddleware.php';
require_once __DIR__ . '/../app/Authorization/AccessScope/AccessScopeGate.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';
require_once __DIR__ . '/../core/OperationalLogger.php';

/*
 * Access-scope regression coverage from the Phase 9 coverage review:
 * column positions not covered elsewhere (G2), principal types (G3), equal
 * denials for existing and missing objects (G4), statement execution for
 * every action (G5), and logging failures (G6).
 *
 * Statements are observed where SQL leaves the application: QueryEngine runs
 * every statement through a private runStatement() reached only from its
 * public execute(), executePrepared(), and executePreparedQuery(). ScopeSpyEngine
 * overrides those three and serves a small catalog, so the real
 * MetadataRepository (INFORMATION_SCHEMA lookups), QueryRepository,
 * SqlRepository, and MetadataService run unchanged on top of it.
 */

function regressionAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class ScopeSpyEngine extends QueryEngine
{
    public array $statements = [];

    /** @param array<string, list<string>|string> $catalog table → columns, or '*' for any column */
    public function __construct(private array $catalog = []) {}

    public function execute($sql, array $context = []) { return $this->record((string)$sql, []); }
    public function executePrepared($sql, array $params = [], array $context = []) { return $this->record((string)$sql, $params); }
    public function executePreparedQuery($sql, array $params = [], array $context = []) { return $this->record((string)$sql, $params); }

    /** Statements other than catalog lookups: the request's own queries. */
    public function dataStatements(): array
    {
        return array_values(array_filter($this->statements, fn (array $statement): bool => !self::isCatalog($statement['sql'])));
    }

    /** Whether any statement names the identifier in its text or parameters. */
    public function mentions(string $identifier): bool
    {
        foreach ($this->statements as $statement) {
            if (stripos($statement['sql'], $identifier) !== false) return true;
            foreach ($statement['params'] as $param) if (is_string($param) && strcasecmp($param, $identifier) === 0) return true;
        }
        return false;
    }

    public static function isCatalog(string $sql): bool
    {
        return str_contains($sql, 'INFORMATION_SCHEMA') || str_contains($sql, 'compatibility_level');
    }

    private function record(string $sql, array $params): array
    {
        $this->statements[] = ['sql' => $sql, 'params' => $params];
        if (str_contains($sql, 'compatibility_level')) return ['data' => [['CompatibilityLevel' => 150]]];
        if (str_contains($sql, 'TotalRows')) return ['data' => [['TotalRows' => 0]]];
        $strings = array_values(array_filter($params, 'is_string'));
        $table = null;
        foreach ($strings as $value) if ($this->table($value) !== null) { $table = $this->table($value); break; }
        if (str_contains($sql, 'INFORMATION_SCHEMA.TABLES')) {
            if (str_contains($sql, 'COUNT(')) return ['data' => [['Total' => $table === null ? 0 : 1]]];
            return ['data' => array_map(fn (string $name): array => ['TABLE_NAME' => $name], array_keys($this->catalog))];
        }
        if (str_contains($sql, 'INFORMATION_SCHEMA.COLUMNS')) {
            $column = null;
            foreach ($strings as $value) if ($table !== null && $this->column($table, $value)) $column = $value;
            if (str_contains($sql, 'COUNT(')) return ['data' => [['Total' => $column === null ? 0 : 1]]];
            if (str_contains($sql, 'COLUMN_NAME = ?')) return ['data' => $column === null ? [] : [['DATA_TYPE' => 'int']]];
            $columns = $table === null || $this->catalog[$table] === '*' ? [] : $this->catalog[$table];
            return ['data' => array_map(fn (string $name): array => ['COLUMN_NAME' => $name, 'DATA_TYPE' => 'int', 'IS_NULLABLE' => 'YES'], $columns)];
        }
        return ['data' => [], 'rowsReturned' => 0];
    }

    private function table(string $name): ?string
    {
        foreach (array_keys($this->catalog) as $table) if (strcasecmp($table, $name) === 0) return $table;
        return null;
    }

    private function column(string $table, string $name): bool
    {
        $columns = $this->catalog[$table];
        if ($columns === '*') return true;
        foreach ($columns as $column) if (strcasecmp($column, $name) === 0) return true;
        return false;
    }
}

/** An audit sink that is unavailable: every write fails. */
final class FailingAuditLogger extends Logger
{
    public int $attempts = 0;
    public function __construct() {}
    public function audit(string $event, string $outcome, string $severity = 'INFO', array $context = []): void
    {
        $this->attempts++;
        throw new RuntimeException('Audit log unavailable.');
    }
}

function regressionRemove(string $path): void
{
    if (!is_dir($path) || is_link($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') regressionRemove($path . DIRECTORY_SEPARATOR . $entry);
    @rmdir($path);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-access-scope-regression-' . bin2hex(random_bytes(8));
$previous = [];
foreach (['GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_LOG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_SECURITY_STORAGE_DIR', 'GENERIC_APP_ENV',
    DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE] as $name) $previous[$name] = getenv($name);

try {
    foreach (['config', 'database/config', 'logs', 'operational', 'security'] as $path) mkdir($directory . '/' . $path, 0700, true);
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory . '/config');
    putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    putenv('GENERIC_SECURITY_STORAGE_DIR=' . $directory . '/security');
    putenv('GENERIC_APP_ENV');
    RuntimeConfiguration::ensure();

    $registry = DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json');
    $registry->saveServer('sql01', 'SQL Server 01', true, ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server',
        'server' => 'sql01.scope.invalid', 'port' => '1433', 'authentication' => 'sql', 'username' => 'scope_user', 'password' => 'scope-secret',
        'options' => ['encrypt' => true, 'trustServerCertificate' => false]]);
    foreach (['company' => 'CompanyDB', 'reporting' => 'ReportingDB', 'inventory' => 'InventoryDB'] as $id => $catalog) {
        $registry->saveDatabase($id, ucfirst($id), 'sql01', true, $catalog);
    }
    $registry->saveDatabase('archive', 'Archive', 'sql01', false, 'ArchiveDB');
    $availability = new DatabaseAvailabilityManager($directory . '/config/database-state.json', $registry);
    foreach (['company', 'reporting', 'archive'] as $id) $availability->setAvailable(true, $id);
    $availability->setAvailable(false, 'inventory');
    $resolver = new DatabaseContextResolver($registry, $availability);
    $planner = new DatabaseQueryPlanner($resolver);
    regressionAssert($resolver->defaultDatabaseId() === 'company', 'The isolated default database is not company.');

    $catalog = [
        'Customers' => ['Id', 'Name', 'City', 'Secret'],
        'Orders' => ['Id', 'CustomerId', 'Total'],
        'Payroll' => ['Id', 'Salary'],
        'BillMastTable' => '*',
    ];
    $customerColumns = ['Id', 'Name', 'City'];
    $frontendScope = ['actions' => ['select', 'sql'], 'databases' => ['company' => [
        'tables' => ['Customers' => $customerColumns, 'Orders' => '*'],
        'resources' => ['widgets/sales-month-wise' => ['filters' => ['Bill_Date']]],
    ]]];
    $readOnlyScope = ['actions' => ['select', 'union', 'unionAll', 'metadata'], 'databases' => ['company' => [
        'tables' => ['Customers' => $customerColumns, 'Orders' => '*'], 'metadata' => ['tables'],
    ]]];
    $configuration = fn (string $mode, array $extra = []): AccessScopeConfiguration => AccessScopeConfiguration::fromArray(
        ['version' => 1, 'mode' => $mode, 'scopes' => ['frontend' => $frontendScope, 'read-only' => $readOnlyScope] + $extra]);
    $enforce = $configuration('enforce');
    $logOnly = $configuration('log-only');

    $principals = [
        'frontend reader' => new Principal('u1', 'Frontend.Reader', 'session', null, true, null, true),
        'read-only key' => new Principal('u2', 'Key.Owner', 'api_key', RoleModel::READ_ONLY, false, null, true),
        'application administrator' => new Principal('u3', 'App.Admin', 'session', null, true, RoleModel::APPLICATION_ADMINISTRATOR, true),
        'data-operator key' => new Principal('u4', 'Operator.Owner', 'api_key', RoleModel::DATA_OPERATOR, false, null, true),
        'api-administrator key' => new Principal('u5', 'Api.Admin', 'api_key', RoleModel::API_ADMINISTRATOR, false, null, true),
    ];
    $validator = new QueryRequestValidator();
    $normalizer = new QueryRequestNormalizer();

    /**
     * Role check, scope phases 0–2 and the availability gate in api/index.php
     * order, then the repository the controller would use, on a spy engine.
     * With $injected, phase 0 is skipped (it builds its own authorizer) and
     * phases 1–2 and the builders use the injected authorizer.
     *
     * @return array{0: bool, 1: int, 2: ?array, 3: ScopeSpyEngine}
     */
    $run = function (array $request, Principal $principal, AccessScopeConfiguration $scopes, ?AccessScopeAuthorizer $injected = null)
        use ($validator, $normalizer, $planner, $resolver, $catalog): array {
        $engine = new ScopeSpyEngine($catalog);
        AccessScopeContext::clear();
        DatabaseQueryPlanContext::clear();
        PrincipalContext::set($principal);
        try {
            (new AuthorizationMiddleware())->handle($request);
            $authorizer = $injected ?? AccessScopeGate::beforeAvailability($request, $principal, $scopes, $resolver);
            (new DatabaseAvailabilityMiddleware(null, $resolver))->handle($request);
            $validator->validate($request);
            $normalized = $normalizer->normalize($request);
            AccessScopeGate::beforePlanning($request, $principal, $scopes, $resolver, $authorizer);
            if (!DatabaseDirectory::isRegistryOnly($request['action'])) {
                $plan = $planner->plan($request, $principal);
                DatabaseQueryPlanContext::set($plan);
                AccessScopeGate::afterPlanning($request, $plan);
            }
            $catalogRepository = new MetadataRepository($engine);
            match (true) {
                in_array($request['action'], ['select', 'union', 'unionAll'], true) => (new QueryRepository($engine, $catalogRepository))->select($normalized),
                $request['action'] === 'sql' => (new SqlRepository($engine, null, null, $catalogRepository))->execute($normalized),
                $request['action'] === 'metadata.tables' => (new MetadataService($catalogRepository))->getTables(),
                $request['action'] === 'metadata.columns' => (new MetadataService($catalogRepository))->getColumns($normalized['table'], $normalized['schema'] ?? null),
                default => throw new LogicException('Unsupported action in the regression pipeline: ' . $request['action']),
            };
            return [true, 200, null, $engine];
        } catch (Throwable $exception) {
            [$status, $payload] = ExceptionHandler::responseFor($exception);
            unset($payload['meta']['requestId']);
            if (!$exception instanceof ApiRequestException) $payload['unexpected'] = get_class($exception) . ': ' . $exception->getMessage();
            return [false, $status, $payload, $engine];
        } finally {
            AccessScopeContext::clear();
            DatabaseQueryPlanContext::clear();
            PrincipalContext::clear();
        }
    };
    $failures = [];
    $check = function (bool $condition, string $message) use (&$failures): void {
        if (!$condition) $failures[] = $message;
    };
    /** A denial: 403 AUTHORIZATION_DENIED and no request query; $strict also requires no statement at all. */
    $denied = function (string $label, array $request, string $who, ?AccessScopeConfiguration $scopes = null, bool $strict = true) use ($run, $principals, $enforce, $check): array {
        [$ok, $status, $payload, $engine] = $run($request, $principals[$who], $scopes ?? $enforce);
        $code = $payload['error']['code'] ?? null;
        $check(!$ok && $status === 403 && $code === 'AUTHORIZATION_DENIED', "{$label}: not denied ({$who}: {$status} {$code} " . ($payload['unexpected'] ?? '') . ').');
        $check($engine->dataStatements() === [], "{$label}: a request query ran before the denial.");
        if ($strict) $check($engine->statements === [], "{$label}: " . count($engine->statements) . ' statement(s) ran before the denial.');
        return [$payload, $engine];
    };
    $allowed = function (string $label, array $request, string $who, ?AccessScopeConfiguration $scopes = null) use ($run, $principals, $enforce, $check): ScopeSpyEngine {
        [$ok, $status, $payload, $engine] = $run($request, $principals[$who], $scopes ?? $enforce);
        $check($ok, "{$label}: not allowed ({$who}: {$status} " . ($payload['error']['code'] ?? '') . ' ' . json_encode($payload['error']['details'] ?? []) . ' ' . ($payload['unexpected'] ?? '') . ').');
        if ($ok && !str_starts_with($request['action'], 'metadata.databases')) $check($engine->statements !== [], "{$label}: the allowed request executed nothing, so the spy proves nothing.");
        return $engine;
    };
    // Sections report together, so one regression shows every section it affects.
    $sectionFailures = [];
    $section = function (string $name) use (&$failures, &$sectionFailures): void {
        if ($failures !== []) $sectionFailures[] = "{$name} failed:\n - " . implode("\n - ", $failures);
        $failures = [];
    };

    // Spy completeness: QueryEngine executes SQL only through these public methods.
    $publicMethods = array_map(fn (ReflectionMethod $method): string => $method->getName(),
        array_filter((new ReflectionClass(QueryEngine::class))->getMethods(ReflectionMethod::IS_PUBLIC), fn (ReflectionMethod $method): bool => $method->class === QueryEngine::class));
    sort($publicMethods);
    regressionAssert($publicMethods === ['__construct', '__destruct', 'close', 'databaseContextId', 'execute', 'executeFile', 'executePrepared', 'executePreparedQuery', 'getQuery'],
        'QueryEngine has a new public method; extend ScopeSpyEngine if it executes SQL: ' . implode(', ', $publicMethods));
    $engineSource = (string)file_get_contents(__DIR__ . '/../core/QueryEngine.php');
    regressionAssert(substr_count($engineSource, '$this->runStatement(') === 3 && str_contains($engineSource, 'return $this->execute($this->getQuery($file)'),
        'QueryEngine reaches runStatement() from a path ScopeSpyEngine does not override.');

    // ---- G2: hidden columns in positions the authorization suite does not cover.
    // Each shape runs twice: with an approved column it must be allowed and run its
    // query (so the shape is valid and executable); with the hidden column it must
    // be denied before any request query runs and before the catalog is asked about it.
    $customers = ['action' => 'select', 'source' => ['table' => 'Customers', 'alias' => 'c']];
    $plus = fn (string $column): array => ['expression' => ['left' => ['field' => $column], 'operator' => '+', 'right' => ['literal' => 1]]];
    $positions = [
        'ORDER BY expression' => ['frontend reader', fn (string $c): array => $customers + ['fields' => ['Id'], 'sort' => [['expression' => $plus($c), 'direction' => 'ASC']]]],
        'HAVING expression' => ['frontend reader', fn (string $c): array => $customers + ['fields' => ['City'], 'groupBy' => ['City'],
            'having' => [['expression' => ['function' => 'MAX', 'field' => ['field' => $c]], 'operator' => '>', 'value' => 0]]]],
        'GROUP BY expression' => ['frontend reader', fn (string $c): array => $customers + ['fields' => [['function' => 'COUNT', 'field' => '*', 'alias' => 'Total']],
            'groupBy' => [$plus($c)]]],
        'window input expression' => ['frontend reader', fn (string $c): array => $customers + ['fields' => ['Id', ['function' => 'LAG', 'field' => $plus($c),
            'offset' => 1, 'default' => 0, 'sort' => [['field' => 'Id']], 'alias' => 'x']]]],
        'unary operand' => ['frontend reader', fn (string $c): array => $customers + ['fields' => ['Id', ['unary' => ['operator' => '-', 'operand' => ['field' => $c]], 'alias' => 'x']]]],
        'CHOOSE values' => ['frontend reader', fn (string $c): array => $customers + ['fields' => [['function' => 'CHOOSE', 'index' => 1, 'values' => [['field' => 'Id'], ['field' => $c]], 'alias' => 'x']]]],
        'join condition, left side' => ['frontend reader', fn (string $c): array => $customers + ['fields' => ['c.Id'], 'joins' => [['type' => 'INNER',
            'source' => ['table' => 'Orders', 'alias' => 'o'], 'on' => ['left' => 'c.' . $c, 'operator' => '=', 'right' => 'o.CustomerId']]]]],
        'CTE body on an approved table' => ['frontend reader', fn (string $c): array => ['action' => 'select', 'source' => ['table' => 'Recent'], 'fields' => ['Id'],
            'with' => ['name' => 'Recent', 'query' => ['source' => ['table' => 'Customers'], 'fields' => ['Id', $c]]]]],
        'recursive CTE anchor' => ['frontend reader', fn (string $c): array => ['action' => 'select', 'source' => ['table' => 'Tree'], 'fields' => ['Id'],
            'with' => ['name' => 'Tree', 'anchor' => ['source' => ['table' => 'Customers'], 'fields' => ['Id', $c]],
                'recursive' => ['source' => ['table' => 'Tree'], 'fields' => ['Id', 'Name']]]]],
        'recursive CTE recursive branch' => ['frontend reader', fn (string $c): array => ['action' => 'select', 'source' => ['table' => 'Tree'], 'fields' => ['Id'],
            'with' => ['name' => 'Tree', 'anchor' => ['source' => ['table' => 'Customers'], 'fields' => ['Id', 'Name']],
                'recursive' => ['source' => ['table' => 'Customers'], 'fields' => ['Id', $c]]]]],
        'union branch on an approved table' => ['read-only key', fn (string $c): array => ['action' => 'union', 'queries' => [
            ['source' => ['table' => 'Customers'], 'fields' => ['Id']], ['source' => ['table' => 'Customers'], 'fields' => [$c]]]]],
        'unionAll branch on an approved table' => ['read-only key', fn (string $c): array => ['action' => 'unionAll', 'queries' => [
            ['source' => ['table' => 'Customers'], 'fields' => ['Id']], ['source' => ['table' => 'Customers'], 'fields' => [$c]]]]],
    ];
    foreach ($positions as $position => [$who, $shape]) {
        $allowed("G2 {$position} (approved column)", $shape('Name'), $who);
        [, $engine] = $denied("G2 {$position}", $shape('Secret'), $who, strict: false);
        $check(!$engine->mentions('Secret'), "G2 {$position}: the catalog was asked about the hidden column.");
    }
    // A subquery cannot reference the outer query's alias: the builder rejects
    // the request before any catalog lookup, so a hidden column there is never
    // resolved. Both variants must fail the same way with nothing executed.
    $correlated = fn (string $c): array => $customers + ['fields' => ['c.Id'], 'filters' => [['operator' => 'EXISTS',
        'query' => ['source' => ['table' => 'Orders', 'alias' => 'o'], 'fields' => ['o.Id'], 'filters' => [['field' => 'c.' . $c, 'operator' => '=', 'value' => 1]]]]]];
    $correlatedResults = [];
    foreach (['Name', 'Secret'] as $column) {
        [$ok, $status, $payload, $engine] = $run($correlated($column), $principals['frontend reader'], $enforce);
        $check(!$ok && $engine->dataStatements() === [] && !$engine->mentions('Secret'), "G2 correlated subquery ({$column}): not rejected before execution.");
        $correlatedResults[] = [$status, $payload['error']['code'] ?? null];
    }
    $check($correlatedResults[0] === $correlatedResults[1], 'G2 correlated subquery: the hidden and approved variants fail differently: ' . json_encode($correlatedResults));
    // A join accepts a single equality only; a condition list is rejected as invalid input, before any statement.
    [$ok, $status, $payload, $engine] = $run($customers + ['fields' => ['c.Id'], 'joins' => [['type' => 'INNER', 'source' => ['table' => 'Orders', 'alias' => 'o'],
        'on' => [['left' => 'o.CustomerId', 'operator' => '=', 'right' => 'c.Id'], ['left' => 'o.Id', 'operator' => '=', 'right' => 'c.Secret']]]]], $principals['frontend reader'], $enforce);
    $check(!$ok && $status === 400 && $engine->statements === [], "G2 join condition list: not rejected before execution ({$status}).");
    $section('G2 hidden-column positions');

    // ---- G3: principal types, under the documented role permissions
    // (RuntimeConfiguration::authorizationDefaults) and one-scope-per-request rule.
    $applicationScope = ['application-administrator' => ['actions' => ['select'], 'databases' => ['company' => ['tables' => ['Payroll' => ['Id']]]]]];
    $operatorScope = ['data-operator' => ['actions' => ['select'], 'databases' => ['company' => ['tables' => ['Orders' => '*']]]]];
    $apiAdminScope = ['api-administrator' => ['actions' => ['select'], 'databases' => ['company' => ['tables' => ['Orders' => ['Id']]]]]];
    $name = $customers + ['fields' => ['Name']];
    $payroll = ['action' => 'select', 'source' => ['table' => 'Payroll'], 'fields' => ['Id']];
    $orders = ['action' => 'select', 'source' => ['table' => 'Orders'], 'fields' => ['Id']];
    $ordersUnion = ['action' => 'union', 'queries' => [['source' => ['table' => 'Orders'], 'fields' => ['Id']], ['source' => ['table' => 'Orders'], 'fields' => ['Id']]]];
    $roleDenied = function (string $label, array $request, string $who) use ($run, $principals, $enforce, $check): void {
        [$ok, $status, $payload, $engine] = $run($request, $principals[$who], $enforce);
        $check(!$ok && $status === 403 && ($payload['error']['code'] ?? null) === 'AUTHORIZATION_DENIED' && $engine->statements === [], "{$label}: the role check did not deny ({$status}).");
    };
    // Application Administrator without a backend role: frontend.read only, frontend scope plus its own scope, never combined.
    $allowed('G3 application administrator: frontend-scope columns', $name, 'application administrator');
    $denied('G3 application administrator: table outside the frontend scope', $payroll, 'application administrator');
    $denied('G3 application administrator: union (read-only scope does not apply)', $ordersUnion, 'application administrator');
    $denied('G3 application administrator: metadata (read-only scope does not apply)', ['action' => 'metadata.tables'], 'application administrator');
    $roleDenied('G3 application administrator: write', ['action' => 'insert', 'source' => ['table' => 'Orders'], 'values' => ['Id' => 1]], 'application administrator');
    $roleDenied('G3 application administrator: procedure', ['action' => 'procedure', 'source' => ['procedure' => 'AnyProcedure']], 'application administrator');
    $withApplication = $configuration('enforce', $applicationScope);
    $allowed('G3 application administrator: its own scope', $payroll, 'application administrator', $withApplication);
    $allowed('G3 application administrator: frontend scope still applies', $name, 'application administrator', $withApplication);
    $denied('G3 application administrator: two scopes never combine', $customers + ['fields' => ['c.Name'], 'joins' => [['type' => 'INNER',
        'source' => ['table' => 'Payroll', 'alias' => 'p'], 'on' => ['left' => 'p.Id', 'operator' => '=', 'right' => 'c.Id']]]], 'application administrator', $withApplication, false);
    $denied('G3 frontend reader: application-administrator scope does not apply', $payroll, 'frontend reader', $withApplication);
    // data-operator and api-administrator keys: no scope ⇒ denied when enforced, legacy in log-only; no other scope stands in.
    foreach (['data-operator key' => $operatorScope, 'api-administrator key' => $apiAdminScope] as $who => $ownScope) {
        foreach (['select' => $orders, 'sql' => ['action' => 'sql', 'resource' => 'widgets/sales-month-wise'], 'union' => $ordersUnion,
            'metadata' => ['action' => 'metadata.tables']] as $label => $request) {
            $denied("G3 {$who} without a scope: {$label}", $request, $who);
        }
        $allowed("G3 {$who} without a scope: log-only keeps the legacy response", $orders, $who, $logOnly);
        $withOwn = $configuration('enforce', $ownScope);
        $allowed("G3 {$who}: its own scope", $orders, $who, $withOwn);
        $denied("G3 {$who}: outside its own scope", $name, $who, $withOwn, false);
        $authorizer = new AccessScopeAuthorizer($withOwn, $principals[$who]);
        try {
            $authorizer->authorizeRequest(['action' => 'insert'], ['company']);
            $check(false, "G3 {$who}: a restricted scope allowed a write.");
        } catch (ApiRequestException $exception) {
            $check($exception->getStatusCode() === 403, "G3 {$who}: write denial used the wrong status.");
        }
        $roleName = $who === 'data-operator key' ? 'data-operator' : 'api-administrator';
        (new AccessScopeAuthorizer($configuration('enforce', [$roleName => ['unrestricted' => true]]), $principals[$who]))->authorizeRequest(['action' => 'insert'], ['company']);
    }
    $denied('G3 data-operator key: the api-administrator scope does not apply', $orders, 'data-operator key', $configuration('enforce', $apiAdminScope));
    $denied('G3 api-administrator key: the data-operator scope does not apply', $orders, 'api-administrator key', $configuration('enforce', $operatorScope));
    $section('G3 principal types');

    // ---- G4: an out-of-scope caller receives the same response whether the object exists.
    $same = function (string $label, array $responses) use ($check): void {
        $bodies = array_map(fn (?array $payload): string => json_encode($payload), $responses);
        $check(count(array_unique($bodies)) === 1, "{$label}: responses differ: " . implode(' | ', $bodies));
        foreach ($bodies as $body) $check(!preg_match('/Payroll|Ghost|Secret|sales-day-wise|no-such|reporting|payrolldb|archive|inventory/i', $body), "{$label}: the response names the object: {$body}");
    };
    $same('G4 table', [
        $denied('G4 existing table', $payroll, 'frontend reader')[0],
        $denied('G4 missing table', ['action' => 'select', 'source' => ['table' => 'Ghost'], 'fields' => ['Id']], 'frontend reader')[0],
    ]);
    $same('G4 column', [
        $denied('G4 existing column', $customers + ['fields' => ['Secret']], 'frontend reader', strict: false)[0],
        $denied('G4 missing column', $customers + ['fields' => ['Ghost']], 'frontend reader', strict: false)[0],
    ]);
    $same('G4 SQL Resource', [
        $denied('G4 existing resource', ['action' => 'sql', 'resource' => 'widgets/sales-day-wise'], 'frontend reader')[0],
        $denied('G4 missing resource', ['action' => 'sql', 'resource' => 'widgets/no-such-resource'], 'frontend reader')[0],
    ]);
    regressionAssert(is_file(__DIR__ . '/fixtures/sql-resources/widgets/sales-day-wise.sql') && !is_file(__DIR__ . '/fixtures/sql-resources/widgets/no-such-resource.sql'),
        'G4 resource fixtures changed.');
    $same('G4 database', array_map(fn (string $id): ?array => $denied("G4 database {$id}",
        ['action' => 'select', 'database' => $id, 'source' => ['table' => 'Orders'], 'fields' => ['Id']], 'frontend reader')[0],
        ['payrolldb', 'reporting', 'inventory', 'archive']));
    $section('G4 existence non-disclosure');

    // ---- G5: SQL Resources and metadata on the spy. Allowed requests do run
    // statements; denials run none. Structured denials never run a request query.
    $sales = fn (array $extra = []): array => ['action' => 'sql', 'resource' => 'widgets/sales-month-wise'] + $extra;
    $range = ['field' => 'Bill_Date', 'operator' => 'BETWEEN', 'value' => ['2021-06-01', '2021-06-30'], 'type' => 'daterange'];
    $sqlEngine = $allowed('G5 approved SQL Resource with its filter', $sales(['execution' => ['filters' => ['Bill_Date' => ['placement' => 'source']]], 'filters' => [$range]]), 'frontend reader');
    $check($sqlEngine->dataStatements() !== [], 'G5 the approved SQL Resource did not run its query.');
    $denied('G5 SQL Resource outside the scope', ['action' => 'sql', 'resource' => 'widgets/sales-day-wise'], 'frontend reader');
    $denied('G5 SQL Resource on an unapproved database', $sales(['database' => 'reporting']), 'frontend reader');
    $denied('G5 undeclared runtime filter', $sales(['filters' => [['field' => 'Salary', 'operator' => '>', 'value' => 0]]]), 'frontend reader');
    $denied('G5 undeclared source mapping', $sales(['execution' => ['filters' => ['Salary' => ['placement' => 'source']]]]), 'frontend reader');
    $denied('G5 client expression mapping', $sales(['execution' => ['filters' => ['Bill_Date' => ['placement' => 'source', 'expression' => 'BIL.Salary']]]]), 'frontend reader');
    $metadataEngine = $allowed('G5 granted metadata operation', ['action' => 'metadata.tables'], 'read-only key');
    $check(count($metadataEngine->statements) === 1 && ScopeSpyEngine::isCatalog($metadataEngine->statements[0]['sql']), 'G5 the metadata listing did not run its catalog query.');
    $denied('G5 ungranted metadata operation', ['action' => 'metadata.columns', 'source' => ['table' => 'Orders']], 'read-only key');
    $denied('G5 metadata of an unapproved database', ['action' => 'metadata.tables', 'database' => 'reporting'], 'read-only key');
    $denied('G5 metadata for a frontend reader', ['action' => 'metadata.tables'], 'frontend reader');
    $denied('G5 unapproved table (first object)', $payroll, 'frontend reader');
    // A hidden column after approved ones: catalog lookups for the approved
    // objects may precede the denial; the hidden column and the request query never run.
    [, $engine] = $denied('G5 hidden column after approved columns', $customers + ['fields' => ['Id', 'Name', 'Secret']], 'frontend reader', strict: false);
    $check(!$engine->mentions('Secret'), 'G5 the hidden column reached the catalog.');
    $section('G5 statement execution');

    // ---- G6: an unavailable audit or operational log never changes the decision.
    $blocked = $directory . '/not-a-directory';
    file_put_contents($blocked, 'block');
    $failingAuthorizer = function (AccessScopeConfiguration $scopes, string $who) use ($principals, $blocked): array {
        $logger = new FailingAuditLogger();
        return [new AccessScopeAuthorizer($scopes, $principals[$who], $logger, new OperationalLogger($blocked . '/operational')), $logger];
    };
    // Each outcome with failing logs must equal the outcome with working logs.
    $outcome = fn (array $result): array => [$result[0], $result[1], $result[2]['error']['code'] ?? null, $result[3]->dataStatements() !== []];
    // The deliberately unusable log path makes the sandbox's open_basedir warn; the warnings are expected here.
    set_error_handler(fn (int $severity, string $message): bool => str_contains($message, 'not-a-directory'));
    try {
        foreach ([
            'unapproved table' => $payroll,
            'hidden column' => $customers + ['fields' => ['Id', 'Secret']],
            'undeclared SQL Resource filter' => $sales(['filters' => [['field' => 'Salary', 'operator' => '>', 'value' => 0]]]),
            'SQL Resource outside the scope' => ['action' => 'sql', 'resource' => 'widgets/sales-day-wise'],
        ] as $label => $request) {
            foreach (['enforce' => $enforce, 'log-only' => $logOnly] as $mode => $scopes) {
                $expected = $outcome($run($request, $principals['frontend reader'], $scopes, new AccessScopeAuthorizer($scopes, $principals['frontend reader'])));
                [$authorizer, $logger] = $failingAuthorizer($scopes, 'frontend reader');
                $actual = $outcome($run($request, $principals['frontend reader'], $scopes, $authorizer));
                $check($logger->attempts > 0, "G6 {$mode}, {$label}: the failing audit log was never reached, so nothing was tested.");
                $check($actual === $expected, "G6 {$mode}, {$label}: a logging failure changed the outcome " . json_encode($expected) . ' → ' . json_encode($actual) . '.');
                if ($mode === 'enforce') $check($actual[0] === false && $actual[1] === 403 && $actual[2] === 'AUTHORIZATION_DENIED' && $actual[3] === false,
                    "G6 enforce, {$label}: not denied before the query with failing logs.");
            }
        }
    } finally {
        restore_error_handler();
    }
    [$authorizer, $logger] = $failingAuthorizer($enforce, 'frontend reader');
    [$ok] = $run($name, $principals['frontend reader'], $enforce, $authorizer);
    $check($ok && $logger->attempts === 0, 'G6 an approved request was affected by the failing logs.');
    // Default loggers whose directories cannot be created, through every gate phase.
    putenv('GENERIC_LOG_DIR=' . $blocked . '/logs');
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $blocked . '/operational');
    set_error_handler(fn (int $severity, string $message): bool => str_contains($message, 'not-a-directory'));
    try {
        foreach (['unregistered database' => ['action' => 'select', 'database' => 'payrolldb', 'source' => ['table' => 'Orders'], 'fields' => ['Id']],
            'unapproved table' => $payroll, 'hidden column' => $customers + ['fields' => ['Secret']]] as $label => $request) {
            [$ok, $status, $payload, $engine] = $run($request, $principals['frontend reader'], $enforce);
            $check(!$ok && $status === 403 && ($payload['error']['code'] ?? null) === 'AUTHORIZATION_DENIED' && $engine->dataStatements() === [],
                "G6 unwritable log directories, {$label}: the denial changed ({$status}).");
        }
        [$ok] = $run($name, $principals['frontend reader'], $enforce);
        $check($ok, 'G6 unwritable log directories denied an approved request.');
    } finally {
        restore_error_handler();
        putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
        putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    }
    $section('G6 logging failures');

    if ($sectionFailures !== []) throw new RuntimeException(implode("\n", $sectionFailures));
    echo "Access scope regression tests passed.\n";
} finally {
    AccessScopeContext::clear();
    DatabaseQueryPlanContext::clear();
    PrincipalContext::clear();
    foreach ($previous as $name => $value) $value === false ? putenv($name) : putenv($name . '=' . $value);
    regressionRemove($directory);
}
