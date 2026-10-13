<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanner.php';
require_once __DIR__ . '/../app/Database/DatabaseQueryPlanContext.php';
require_once __DIR__ . '/../app/Database/DatabaseDirectory.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestNormalizer.php';
require_once __DIR__ . '/../app/Repositories/QueryRepository.php';
require_once __DIR__ . '/../app/Repositories/AuthRepository.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/AuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/DatabaseAvailabilityMiddleware.php';
require_once __DIR__ . '/../app/Authorization/AccessScope/AccessScopeGate.php';
require_once __DIR__ . '/../app/Authorization/AccessScope/AccessScopeReadiness.php';
require_once __DIR__ . '/../app/Deployment/ProductionValidator.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

/*
 * Phase 4 / F-01: backend read authorization with access scopes.
 *
 * Requests run through the real validator, normalizer, AccessScopeGate (the
 * code api/index.php calls), DatabaseQueryPlanner, QueryRepository and query
 * builders. Only the database is replaced: a recording engine and a catalog
 * fake. Every denial must happen before the engine executes any statement.
 */

function scopeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class ScopeRecordingEngine extends QueryEngine
{
    public array $statements = [];
    public function __construct() {}
    public function executePrepared($sql, array $params = [], array $context = [])
    {
        $this->statements[] = ['sql' => $sql, 'params' => $params];
        if (str_contains($sql, 'compatibility_level')) return ['data' => [['CompatibilityLevel' => 150]]];
        if (str_contains($sql, 'TotalRows')) return ['data' => [['TotalRows' => 0]]];
        return ['data' => [], 'rowsReturned' => 0];
    }
}

/** A catalog where every object and column exists; the scope decides access. */
final class ScopeCatalog extends MetadataRepository
{
    public function __construct() {}
    public function tableExists($table) { return true; }
    public function objectExists(QualifiedObject $object): bool { return true; }
    public function columnExists($table, $column) { return true; }
    public function objectColumnExists(QualifiedObject $object, $column): bool { return true; }
    public function getColumnDataType($table, $column) { return 'int'; }
    public function objectColumnDataType(QualifiedObject $object, $column) { return 'int'; }
    public function getColumns($table) { return ['data' => [['COLUMN_NAME' => 'Id'], ['COLUMN_NAME' => 'Name']]]; }
    public function objectColumns(QualifiedObject $object) { return $this->getColumns($object->objectName()); }
}

function scopeRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') scopeRemove($path . DIRECTORY_SEPARATOR . $entry);
    @rmdir($path);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-access-scopes-' . bin2hex(random_bytes(8));
$previous = [];
foreach (['GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_LOG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_SECURITY_STORAGE_DIR', 'GENERIC_APP_ENV',
    'GENERIC_ALLOW_ANONYMOUS_PRODUCTION', DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE] as $name) $previous[$name] = getenv($name);

try {
    foreach (['config', 'database/config', 'logs', 'operational', 'security'] as $path) mkdir($directory . '/' . $path, 0700, true);
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory . '/config');
    putenv('GENERIC_LOG_DIR=' . $directory . '/logs');
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $directory . '/operational');
    putenv('GENERIC_SECURITY_STORAGE_DIR=' . $directory . '/security');
    putenv('GENERIC_APP_ENV');
    putenv('GENERIC_ALLOW_ANONYMOUS_PRODUCTION');
    RuntimeConfiguration::ensure();

    // Regression: the scope file has a runtime path, and bootstrap never creates it.
    scopeAssert(AccessScopeConfiguration::path() === $directory . '/config' . DIRECTORY_SEPARATOR . 'access-scopes.json', 'Access scope path is wrong.');
    scopeAssert(!is_file(AccessScopeConfiguration::path()), 'Bootstrap created an access scope file.');
    scopeAssert(RuntimeConfiguration::authorizationDefaults()['publicRoles'] === [], 'Anonymous principals hold a default role.');

    // Isolated registry: company (default), reporting, inventory on one server profile.
    $registry = DatabaseRegistry::forLegacyPath($directory . '/database/config/database.json');
    $server = ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server', 'server' => 'sql01.scope.test', 'port' => '1433',
        'authentication' => 'sql', 'username' => 'scope_user', 'password' => 'scope-secret', 'options' => ['encrypt' => true, 'trustServerCertificate' => false]];
    $registry->saveServer('sql01', 'SQL Server 01', true, $server);
    foreach (['company' => 'CompanyDB', 'reporting' => 'ReportingDB', 'inventory' => 'InventoryDB'] as $id => $catalog) {
        $registry->saveDatabase($id, ucfirst($id), 'sql01', true, $catalog);
    }
    $availability = new DatabaseAvailabilityManager($directory . '/config/database-state.json', $registry);
    foreach (['company', 'reporting', 'inventory'] as $id) $availability->setAvailable(true, $id);
    $resolver = new DatabaseContextResolver($registry, $availability);
    $planner = new DatabaseQueryPlanner($resolver);
    scopeAssert($resolver->defaultDatabaseId() === 'company', 'The isolated default database is not company.');

    $frontendScope = [
        'actions' => ['select', 'sql'],
        'databases' => [
            'company' => [
                'tables' => ['Customers' => ['Id', 'Name', 'City'], 'Orders' => '*', 'sales.Invoices' => '*'],
                'resources' => ['widgets/sales-month-wise' => ['filters' => ['Bill_Date']], 'reports/*' => ['filters' => []]],
            ],
        ],
    ];
    $readOnlyScope = [
        'actions' => ['select', 'union', 'unionAll', 'metadata'],
        'listDatabases' => true,
        'databases' => ['company' => ['tables' => ['Orders' => '*'], 'metadata' => ['tables']]],
    ];
    $scopes = fn (string $mode): AccessScopeConfiguration => AccessScopeConfiguration::fromArray(['version' => 1, 'mode' => $mode, 'scopes' => [
        'frontend' => $frontendScope,
        'read-only' => $readOnlyScope,
        'system-administrator' => ['unrestricted' => true],
    ]]);
    $enforce = $scopes('enforce');
    $logOnly = $scopes('log-only');

    $principals = [
        'frontend reader' => new Principal('u1', 'Frontend.Reader', 'session', null, true, null, true),
        'legacy frontend reader' => new Principal('u2', 'Legacy.Reader', 'session', RoleModel::READ_ONLY, true, null, true),
        'managed API key' => new Principal('u3', 'Key.Owner', 'api_key', RoleModel::READ_ONLY, false, null, true),
        'legacy API key' => new Principal(null, 'legacy-api-key', 'api_key', RoleModel::READ_ONLY, false, null, true),
        'system administrator' => new Principal('u4', 'System.Admin', 'session', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR, true),
        'anonymous' => new Principal(null, 'public', 'none', null, false, null, true),
        'backend without role' => new Principal('u5', 'No.Role', 'session', null, false, null, true),
    ];
    $validator = new QueryRequestValidator();
    $normalizer = new QueryRequestNormalizer();

    /** Runs a request in api/index.php order (scope phase 0, availability gate, validation, phases 1 and 2); returns [allowed, status, code, statements]. */
    $run = function (array $request, Principal $principal, AccessScopeConfiguration $configuration) use ($validator, $normalizer, $planner, $resolver): array {
        $engine = new ScopeRecordingEngine();
        AccessScopeContext::clear();
        DatabaseQueryPlanContext::clear();
        try {
            $authorizer = AccessScopeGate::beforeAvailability($request, $principal, $configuration, $resolver);
            (new DatabaseAvailabilityMiddleware(null, $resolver))->handle($request);
            $validator->validate($request);
            $normalized = $normalizer->normalize($request);
            AccessScopeGate::beforePlanning($request, $principal, $configuration, $resolver, $authorizer);
            if (!DatabaseDirectory::isRegistryOnly($request['action'])) {
                $plan = $planner->plan($request, $principal);
                DatabaseQueryPlanContext::set($plan);
                AccessScopeGate::afterPlanning($request, $plan);
            }
            if (in_array($request['action'], ['select', 'union', 'unionAll'], true)) {
                (new QueryRepository($engine, new ScopeCatalog()))->select($normalized);
            }
            return [true, 200, null, count($engine->statements)];
        } catch (ApiRequestException $exception) {
            [$status, $payload] = ExceptionHandler::responseFor($exception);
            return [false, $status, $payload['error']['code'] ?? null, count($engine->statements)];
        } finally {
            AccessScopeContext::clear();
            DatabaseQueryPlanContext::clear();
        }
    };
    $denied = function (array $request, string $who, string $message, ?AccessScopeConfiguration $configuration = null) use ($run, $principals, $enforce): void {
        [$allowed, $status, $code, $statements] = $run($request, $principals[$who], $configuration ?? $enforce);
        scopeAssert(!$allowed && $status === 403 && $code === 'AUTHORIZATION_DENIED', "{$message} ({$who}: {$status} {$code})");
        scopeAssert($statements === 0, "{$message}: {$statements} statements ran before the denial.");
    };
    $allowed = function (array $request, string $who, string $message, ?AccessScopeConfiguration $configuration = null) use ($run, $principals, $enforce): void {
        [$ok, $status, $code, $statements] = $run($request, $principals[$who], $configuration ?? $enforce);
        scopeAssert($ok, "{$message} ({$who}: {$status} {$code})");
        if (in_array($request['action'], ['select', 'union', 'unionAll'], true)) scopeAssert($statements > 0, "{$message}: the query did not run.");
    };

    $customers = ['action' => 'select', 'source' => ['table' => 'Customers', 'alias' => 'c']];

    // Approved tables and columns.
    $allowed($customers + ['fields' => ['Id', 'c.Name', ['field' => 'City', 'alias' => 'Town']]], 'frontend reader', 'Approved columns were denied.');
    $allowed(['action' => 'select', 'source' => ['table' => 'Orders'], 'fields' => ['*']], 'frontend reader', 'A fully approved table was denied for *.');
    $allowed(['action' => 'select', 'source' => ['table' => 'Invoices', 'schema' => 'sales'], 'fields' => ['Id']], 'frontend reader', 'An approved schema-qualified table was denied.');
    $allowed($customers + ['fields' => [['function' => 'COUNT', 'field' => '*', 'alias' => 'Total']]], 'frontend reader', 'COUNT(*) of an approved table was denied.');

    // A hidden column in every position the builders render.
    $hidden = [
        'projection' => ['fields' => ['Secret']],
        'qualified projection' => ['fields' => ['c.Secret']],
        'aliased projection' => ['fields' => [['field' => 'Secret', 'alias' => 'x']]],
        'aggregate argument' => ['fields' => [['function' => 'SUM', 'field' => 'Secret', 'alias' => 'x']]],
        'multi-field function' => ['fields' => [['function' => 'COALESCE', 'fields' => ['Name', 'Secret'], 'default' => '', 'alias' => 'x']]],
        'nested function' => ['fields' => [['function' => 'ROUND', 'field' => ['function' => 'SUM', 'field' => ['field' => 'Secret']], 'precision' => 0, 'alias' => 'x']]],
        'arithmetic' => ['fields' => [['expression' => ['left' => ['field' => 'Secret'], 'operator' => '+', 'right' => ['literal' => 1]], 'alias' => 'x']]],
        'CASE condition' => ['fields' => [['case' => ['when' => [['condition' => ['field' => 'Secret', 'operator' => '=', 'value' => 'a'], 'then' => 'y']], 'else' => 'n'], 'alias' => 'x']]],
        'window value' => ['fields' => ['Id', ['function' => 'LAG', 'field' => 'Secret', 'offset' => 1, 'default' => 0, 'sort' => [['field' => 'Id']], 'alias' => 'x']]],
        'window sort' => ['fields' => ['Id', ['function' => 'ROW_NUMBER', 'sort' => [['field' => 'Secret', 'direction' => 'ASC']], 'alias' => 'x']]],
        'window partition' => ['fields' => ['Id', ['function' => 'ROW_NUMBER', 'partitionBy' => ['Secret'], 'sort' => [['field' => 'Id', 'direction' => 'ASC']], 'alias' => 'x']]],
        'predicate' => ['fields' => ['Id'], 'filters' => [['field' => 'Secret', 'operator' => '=', 'value' => 1]]],
        'grouping' => ['fields' => ['Name'], 'groupBy' => ['Name', 'Secret']],
        'HAVING' => ['fields' => ['Name'], 'groupBy' => ['Name'], 'having' => [['function' => 'MAX', 'field' => 'Secret', 'operator' => '>', 'value' => 0]]],
        'sorting' => ['fields' => ['Id'], 'sort' => [['field' => 'Secret', 'direction' => 'ASC']]],
        'join condition' => ['fields' => ['c.Id'], 'joins' => [['type' => 'INNER', 'source' => ['table' => 'Orders', 'alias' => 'o'], 'on' => ['left' => 'o.Id', 'operator' => '=', 'right' => 'c.Secret']]]],
        'subquery' => ['fields' => ['Id'], 'filters' => [['field' => 'Id', 'operator' => 'IN', 'query' => ['source' => ['table' => 'Customers'], 'fields' => ['Secret']]]]],
        'EXISTS subquery' => ['fields' => ['Id'], 'filters' => [['operator' => 'EXISTS', 'query' => ['source' => ['table' => 'Customers'], 'fields' => ['Id'], 'filters' => [['field' => 'Secret', 'operator' => '=', 'value' => 1]]]]]],
        'wildcard under a column list' => ['fields' => ['*']],
        'wildcard across a join' => ['source' => ['table' => 'Orders', 'alias' => 'o'], 'fields' => ['*'], 'joins' => [['type' => 'INNER', 'source' => ['table' => 'Customers', 'alias' => 'c'], 'on' => ['left' => 'o.Id', 'operator' => '=', 'right' => 'c.Id']]]],
    ];
    foreach ($hidden as $position => $shape) $denied($shape + $customers, 'frontend reader', "A hidden column in {$position} was not denied.");

    // Unapproved objects anywhere in the request.
    $denied(['action' => 'select', 'source' => ['table' => 'Payroll'], 'fields' => ['Id']], 'frontend reader', 'An unapproved table was not denied.');
    $denied(['action' => 'select', 'source' => ['table' => 'customers'], 'fields' => ['Id']], 'frontend reader', 'A case variant of an approved table was not denied.');
    $denied(['action' => 'select', 'source' => ['table' => 'Customers', 'schema' => 'dbo'], 'fields' => ['Id']], 'frontend reader', 'An unconfigured schema-qualified name was not denied.');
    $denied($customers + ['fields' => ['c.Id'], 'joins' => [['type' => 'LEFT', 'source' => ['table' => 'Payroll', 'alias' => 'p'], 'on' => ['left' => 'p.Id', 'operator' => '=', 'right' => 'c.Id']]]], 'frontend reader', 'An unapproved joined table was not denied.');
    $denied($customers + ['fields' => ['Id'], 'filters' => [['field' => 'Id', 'operator' => 'IN', 'query' => ['source' => ['table' => 'Payroll'], 'fields' => ['Id']]]]], 'frontend reader', 'An unapproved subquery table was not denied.');
    $denied(['action' => 'select', 'source' => ['table' => 'Recent'], 'fields' => ['Id'], 'with' => ['name' => 'Recent', 'query' => ['source' => ['table' => 'Payroll'], 'fields' => ['Id']]]], 'frontend reader', 'An unapproved CTE body was not denied.');
    $denied(['action' => 'select', 'source' => ['table' => 'Customers', 'database' => 'inventory'], 'fields' => ['Id']], 'frontend reader', 'A cross-database source was not denied.');
    $denied(['action' => 'select', 'database' => 'reporting', 'source' => ['table' => 'Customers'], 'fields' => ['Id']], 'frontend reader', 'An unapproved database was not denied.');
    $denied($customers + ['fields' => ['c.Id'], 'joins' => [['type' => 'INNER', 'source' => ['table' => 'Orders', 'alias' => 'o', 'database' => 'reporting'], 'on' => ['left' => 'o.Id', 'operator' => '=', 'right' => 'c.Id']]]], 'frontend reader', 'A cross-database join was not denied.');

    // Set operations: the action is scoped, and every branch is checked.
    $union = fn (string $second): array => ['action' => 'union', 'queries' => [['source' => ['table' => 'Orders'], 'fields' => ['Id']], ['source' => ['table' => $second], 'fields' => ['Id']]]];
    $denied($union('Orders'), 'frontend reader', 'union was not denied without an explicit grant.');
    $allowed($union('Orders'), 'managed API key', 'An approved union was denied.');
    $denied($union('Payroll'), 'managed API key', 'An unapproved second union branch was not denied.');
    $denied(['action' => 'unionAll', 'queries' => [['source' => ['table' => 'Orders'], 'fields' => ['*']], ['source' => ['table' => 'Payroll'], 'fields' => ['*']]]], 'managed API key', 'A wildcard union branch was not denied.');

    // One scope must allow the whole request; grants are never combined.
    $allowed($customers + ['fields' => ['Name']], 'legacy frontend reader', 'The frontend scope of a legacy reader was denied.');
    $denied($union('Customers'), 'legacy frontend reader', 'Grants of two scopes were combined (union from read-only, Customers from frontend).');

    // Principals without an applicable scope, and unrestricted scopes.
    $denied($customers + ['fields' => ['Id']], 'anonymous', 'An anonymous principal without a scope was not denied.');
    $denied($customers + ['fields' => ['Id']], 'backend without role', 'A principal without a scope was not denied.');
    $allowed(['action' => 'select', 'source' => ['table' => 'Payroll'], 'fields' => ['*'], 'database' => 'reporting'], 'system administrator', 'An explicitly unrestricted scope was denied.');
    $denied(['action' => 'select', 'source' => ['table' => 'Payroll'], 'fields' => ['Id']], 'legacy API key', 'The legacy key was not bound by its role scope.');

    // An integration whose role has no approved scope is denied when enforced
    // (log-only keeps its legacy response); no other scope stands in for it.
    $frontendOnly = fn (string $mode): AccessScopeConfiguration => AccessScopeConfiguration::fromArray(['version' => 1, 'mode' => $mode, 'scopes' => ['frontend' => $frontendScope]]);
    foreach (['managed API key', 'legacy API key'] as $who) {
        foreach ([$customers + ['fields' => ['Id']], ['action' => 'sql', 'resource' => 'widgets/sales-month-wise'], $union('Orders'), ['action' => 'metadata.tables']] as $request) {
            $denied($request, $who, "An integration without an approved scope reached {$request['action']}.", $frontendOnly('enforce'));
        }
        $allowed($customers + ['fields' => ['Id']], $who, 'Log-only mode changed an unscoped integration response.', $frontendOnly('log-only'));
    }

    // Every public data action passes the scope gate; a new action cannot bypass it.
    foreach ((new ReflectionClassConstant(QueryRequestValidator::class, 'ACTIONS'))->getValue() as $action) {
        scopeAssert(AccessScopeAuthorizer::appliesTo($action), "Data action {$action} is not scoped.");
    }

    // SQL Resources (the gate decides; parameter binding is unchanged).
    $sql = fn (array $extra = []): array => ['action' => 'sql', 'resource' => 'widgets/sales-month-wise'] + $extra;
    $runtimeFilter = ['field' => 'Bill_Date', 'operator' => 'BETWEEN', 'value' => ['2021-06-01', '2021-06-30'], 'type' => 'daterange'];
    $allowed($sql(['execution' => ['columns' => ['Month', 'Sales'], 'filters' => ['Bill_Date' => ['placement' => 'source']]], 'filters' => [$runtimeFilter],
        'sort' => [['field' => 'Month', 'direction' => 'DESC']], 'pagination' => ['page' => 1, 'pageSize' => 10]]), 'frontend reader', 'The approved dashboard resource request was denied.');
    $allowed(['action' => 'sql', 'resource' => 'reports/customer'], 'frontend reader', 'A resource matching an approved dir/* pattern was denied.');
    $allowed(['action' => 'sql', 'resource' => 'widgets/sales-month-wise', 'database' => 'company'], 'frontend reader', 'An approved resource on its approved database was denied.');
    $denied(['action' => 'sql', 'resource' => 'widgets/payroll'], 'frontend reader', 'An unapproved resource was not denied.');
    $denied(['action' => 'sql', 'resource' => 'reports/hr/salaries'], 'frontend reader', 'A dir/* pattern matched more than one segment.');
    $denied(['action' => 'sql', 'resource' => 'sales'], 'frontend reader', 'A basename shortcut bypassed the exact resource id.');
    $denied(['action' => 'sql', 'resource' => 'Widgets/sales-month-wise'], 'frontend reader', 'A case variant of an approved resource was not denied.');
    $denied(['action' => 'sql', 'resource' => 'widgets/sales-month-wise', 'database' => 'inventory'], 'frontend reader', 'An approved resource on an unapproved database was not denied.');
    $denied($sql(['filters' => [['field' => 'Salary', 'operator' => '>', 'value' => 0]]]), 'frontend reader', 'An undeclared runtime filter was not denied.');
    $denied($sql(['execution' => ['filters' => ['Salary' => ['placement' => 'source']]]]), 'frontend reader', 'An undeclared hidden source column filter was not denied.');
    $denied($sql(['execution' => ['filters' => ['Bill_Date' => ['placement' => 'source', 'expression' => 'BIL.Salary']]]]), 'frontend reader', 'A client expression remapping an allowed filter was not denied.');
    $denied($sql(['execution' => ['filters' => ['Bill_Date' => ['placement' => 'having', 'expression' => 'SUM(Salary)']]]]), 'frontend reader', 'A HAVING filter mapping was not denied.');
    $placeholderScope = AccessScopeConfiguration::fromArray(['version' => 1, 'mode' => 'enforce', 'scopes' => ['frontend' => $frontendScope]]);
    $placeholder = new AccessScopeAuthorizer($placeholderScope, $principals['frontend reader']);
    $placeholder->authorizeRequest(['action' => 'sql', 'resource' => 'widgets/sales-month-wise'], []);
    try {
        $placeholder->authorizePlan(['action' => 'sql', 'resource' => 'widgets/sales-month-wise'], ['inventory']);
        throw new LogicException('A resource placeholder database outside the scope was accepted.');
    } catch (ApiRequestException $exception) {
        scopeAssert($exception->getErrorCode() === 'AUTHORIZATION_DENIED', 'Placeholder database denial used the wrong code.');
    }

    // Metadata: denied by default, granted per operation and database.
    foreach (['metadata.tables', 'metadata.columns', 'metadata.schema', 'metadata.databases'] as $action) {
        $request = ['action' => $action] + ($action === 'metadata.columns' ? ['source' => ['table' => 'Orders']] : []);
        $denied($request, 'frontend reader', "{$action} was not denied for a frontend reader.");
    }
    $allowed(['action' => 'metadata.tables'], 'managed API key', 'An explicitly granted metadata operation was denied.');
    $allowed(['action' => 'metadata.databases'], 'managed API key', 'An explicitly granted database listing was denied.');
    $denied(['action' => 'metadata.columns', 'source' => ['table' => 'Orders']], 'managed API key', 'An ungranted metadata operation was not denied.');
    $denied(['action' => 'metadata.tables', 'database' => 'reporting'], 'managed API key', 'Metadata of an unapproved database was not denied.');

    // Routines and writes: never part of a restricted scope.
    foreach (['function', 'tableFunction', 'procedure', 'insert', 'update', 'delete', 'upsert'] as $action) {
        foreach (['frontend reader', 'managed API key', 'legacy frontend reader'] as $who) {
            $authorizer = new AccessScopeAuthorizer($enforce, $principals[$who]);
            try {
                $authorizer->authorizeRequest(['action' => $action], ['company']);
                throw new LogicException("{$action} was not denied for {$who}.");
            } catch (ApiRequestException $exception) {
                scopeAssert($exception->getStatusCode() === 403, "{$action} denial for {$who} used the wrong status.");
            }
        }
        (new AccessScopeAuthorizer($enforce, $principals['system administrator']))->authorizeRequest(['action' => $action], ['company']);
    }

    // Frontend readers hold frontend.read only: no routine or write permission.
    foreach (['function', 'tableFunction', 'procedure', 'insert'] as $action) {
        PrincipalContext::set($principals['frontend reader']);
        try {
            (new AuthorizationMiddleware())->handle(['action' => $action]);
            throw new LogicException("frontend.read granted {$action}.");
        } catch (ApiRequestException $exception) {
            scopeAssert($exception->getErrorCode() === 'AUTHORIZATION_DENIED', "{$action} for a frontend reader used the wrong code.");
        }
    }
    PrincipalContext::clear();

    // Existing frontend payloads keep working unchanged under a reviewed scope.
    $frontendPayloads = [
        ['action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => ['Name', ['function' => 'COUNT', 'field' => 'Name', 'alias' => 'Total'],
            ['function' => 'MAX', 'field' => 'Id', 'alias' => 'Highest']], 'groupBy' => ['Name'], 'sort' => [['field' => 'Name', 'direction' => 'ASC']],
            'filters' => [['field' => 'City', 'operator' => 'LIKE', 'value' => '%a%']], 'pagination' => ['page' => 1, 'pageSize' => 10]],
        ['action' => 'select', 'database' => 'company', 'source' => ['table' => 'Orders'], 'fields' => ['Id', ['function' => 'COUNT', 'field' => '*', 'alias' => 'Frequency']],
            'groupBy' => ['Id'], 'sort' => [['field' => 'Frequency', 'direction' => 'DESC']], 'limit' => 1000],
    ];
    foreach ($frontendPayloads as $index => $payload) $allowed($payload, 'frontend reader', "Frontend payload {$index} was denied.");

    // Log-only: the same out-of-scope request keeps its legacy response and is recorded once.
    $logFiles = function () use ($directory): string {
        $contents = [];
        foreach (['logs', 'operational', 'security'] as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/' . $root, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) if ($file->isFile()) $contents[] = (string)file_get_contents($file->getPathname());
        }
        return implode("\n", $contents);
    };
    $before = substr_count($logFiles(), 'authorization.scope_would_deny');
    $allowed(['action' => 'select', 'source' => ['table' => 'Payroll'], 'fields' => ['Id', 'Salary'],
        'filters' => [['field' => 'Salary', 'operator' => '=', 'value' => 'SensitiveFilterValue']]], 'frontend reader', 'Log-only mode changed a legacy response.', $logOnly);
    $logs = $logFiles();
    scopeAssert(substr_count($logs, 'authorization.scope_would_deny') === $before + 1, 'Log-only mode did not record exactly one would-be denial.');
    scopeAssert(str_contains($logs, 'table_not_in_scope') && str_contains($logs, 'company:Payroll'), 'The would-be denial lacks its reason or object.');
    scopeAssert(!str_contains($logs, 'SensitiveFilterValue') && !str_contains($logs, 'scope-secret'), 'Scope logs contain a filter value or credential.');
    $denied(['action' => 'select', 'source' => ['table' => 'Payroll'], 'fields' => ['Id']], 'frontend reader', 'Enforce mode did not deny the same request.');
    scopeAssert(str_contains($logFiles(), 'authorization.scope_denied'), 'Enforce mode did not record the denial.');

    // Missing and invalid configuration.
    $missingPath = $directory . '/config/absent-access-scopes.json';
    $missingDevelopment = AccessScopeConfiguration::load($missingPath, false);
    scopeAssert($missingDevelopment->state === 'missing' && $missingDevelopment->mode === 'log-only', 'Missing development configuration is not log-only.');
    $allowed($customers + ['fields' => ['Id']], 'frontend reader', 'Missing development configuration changed a legacy response.', $missingDevelopment);
    $missingProduction = AccessScopeConfiguration::load($missingPath, true);
    scopeAssert($missingProduction->state === 'missing' && $missingProduction->enforced(), 'Missing production configuration is not fail-closed.');
    $denied($customers + ['fields' => ['Id']], 'frontend reader', 'Missing production configuration did not fail closed.', $missingProduction);
    $denied($customers + ['fields' => ['Id']], 'system administrator', 'Missing production configuration did not fail closed for administrators.', $missingProduction);
    $invalidPath = $directory . '/config/invalid-access-scopes.json';
    foreach (['{not json', '[]', json_encode(['version' => 1, 'mode' => 'enforce', 'scopes' => ['frontend' => ['unrestricted' => true, 'actions' => ['select']]]])] as $content) {
        file_put_contents($invalidPath, $content);
        $invalid = AccessScopeConfiguration::load($invalidPath, false);
        scopeAssert($invalid->state === 'invalid' && $invalid->enforced(), 'Invalid configuration did not fail closed: ' . $content);
        $denied($customers + ['fields' => ['Id']], 'system administrator', 'Invalid configuration granted access.', $invalid);
    }

    // Malformed, ambiguous, or overly broad rules are rejected.
    $base = ['version' => 1, 'mode' => 'enforce'];
    $restricted = fn (array $database, array $actions = ['select', 'sql']): array => $base + ['scopes' => ['frontend' => ['actions' => $actions, 'databases' => ['company' => $database]]]];
    $rejected = [
        'unknown top-level key' => $base + ['scopes' => [], 'default' => 'allow'],
        'unknown mode' => ['version' => 1, 'mode' => 'off', 'scopes' => []],
        'unknown version' => ['version' => 2, 'mode' => 'enforce', 'scopes' => []],
        'unknown subject' => $base + ['scopes' => ['everyone' => ['unrestricted' => true]]],
        'unrestricted false' => $base + ['scopes' => ['frontend' => ['unrestricted' => false]]],
        'routine action' => $restricted([], ['select', 'procedure']),
        'write action' => $restricted([], ['insert']),
        'empty actions' => $restricted([], []),
        'invalid database id' => $base + ['scopes' => ['frontend' => ['actions' => ['select'], 'databases' => ['Company DB' => []]]]],
        'table wildcard' => $restricted(['tables' => ['*' => '*']]),
        'three-part table' => $restricted(['tables' => ['db.dbo.Orders' => '*']]),
        'system schema' => $restricted(['tables' => ['sys.objects' => '*']]),
        'empty column list' => $restricted(['tables' => ['Orders' => []]]),
        'duplicate columns' => $restricted(['tables' => ['Orders' => ['Id', 'Id']]]),
        'column wildcard inside list' => $restricted(['tables' => ['Orders' => ['*']]]),
        'path traversal resource' => $restricted(['resources' => ['../secret' => ['filters' => []]]]),
        'backslash resource' => $restricted(['resources' => ['widgets\\sales' => ['filters' => []]]]),
        'extension resource' => $restricted(['resources' => ['widgets/sales.sql' => ['filters' => []]]]),
        'bare star resource' => $restricted(['resources' => ['*' => ['filters' => []]]]),
        'double star resource' => $restricted(['resources' => ['widgets/**' => ['filters' => []]]]),
        'inner star resource' => $restricted(['resources' => ['widgets/*/sales' => ['filters' => []]]]),
        'leading slash resource' => $restricted(['resources' => ['/widgets/sales' => ['filters' => []]]]),
        'missing filters' => $restricted(['resources' => ['widgets/sales-month-wise' => []]]),
        'filter expression' => $restricted(['resources' => ['widgets/sales-month-wise' => ['filters' => ['SUM(x)']]]]),
        'unknown metadata operation' => $restricted(['metadata' => ['everything']]),
        'unknown database key' => $restricted(['views' => []]),
    ];
    foreach ($rejected as $case => $configuration) {
        try {
            AccessScopeConfiguration::fromArray($configuration);
            throw new LogicException("Access scope configuration accepted {$case}.");
        } catch (InvalidArgumentException $exception) {
            scopeAssert(str_starts_with($exception->getMessage(), 'Invalid access scope configuration:'), "{$case} failed for the wrong reason.");
        }
    }
    $example = AccessScopeConfiguration::fromArray(json_decode((string)file_get_contents(__DIR__ . '/../config/access-scopes.example.json'), true));
    scopeAssert($example->state === 'configured' && $example->mode === 'log-only', 'The shipped example is not a valid log-only configuration.');

    // Stage D: a frontend-created Read Only user is a frontend reader without a backend role.
    $authRepository = new AuthRepository(RuntimeConfiguration::path(RuntimeConfiguration::AUTH_FILE));
    $users = new UserManagementService($authRepository);
    $users->createUser('Scope.Admin', 'scope-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $adminRecord = $authRepository->findUser('Scope.Admin');
    $actor = new Principal($adminRecord['id'], $adminRecord['username'], 'session', $adminRecord['backendRole'], true, $adminRecord['frontendRole'], true);
    $users->createFrontendUser($actor, 'Scope Reader', 'Scope.Reader', '+15551234567', null, 'scope-reader-password', RoleModel::READ_ONLY);
    $reader = $authRepository->findUser('Scope.Reader');
    scopeAssert($reader['backendRole'] === null && $reader['frontendAccess'] === true && $reader['frontendRole'] === null,
        'A frontend-created Read Only user received a backend role.');
    $readerPrincipal = new Principal($reader['id'], $reader['username'], 'session', $reader['backendRole'], true, null, true);
    PrincipalContext::set($readerPrincipal);
    (new AuthorizationMiddleware())->handle(['action' => 'select']);
    try {
        (new AuthorizationMiddleware())->handle(['action' => 'function']);
        throw new LogicException('A frontend-created reader may execute functions.');
    } catch (ApiRequestException $exception) {
        scopeAssert($exception->getErrorCode() === 'AUTHORIZATION_DENIED', 'Reader function denial used the wrong code.');
    }
    PrincipalContext::clear();
    $denied(['action' => 'select', 'source' => ['table' => 'Payroll'], 'fields' => ['Id']], 'frontend reader', 'A frontend reader escaped the frontend scope.');

    // Database existence: outside its scope, a caller receives the same denial
    // for an unregistered, registered, unavailable, or disabled database, and
    // never reaches the availability gate. Inside its scope, and for
    // unrestricted principals, the gate's diagnostics are unchanged.
    $registry->saveDatabase('archive', 'Archive', 'sql01', false, 'ArchiveDB');
    $availability->setAvailable(false, 'inventory');
    $databaseProbes = [
        'unregistered' => 'payrolldb',
        'registered' => 'reporting',
        'unavailable' => 'inventory',
        'disabled' => 'archive',
    ];
    $probeRequests = [
        'select' => fn (string $id): array => ['action' => 'select', 'database' => $id, 'source' => ['table' => 'Orders'], 'fields' => ['Id']],
        'source database' => fn (string $id): array => ['action' => 'select', 'source' => ['table' => 'Orders', 'database' => $id], 'fields' => ['Id']],
        'union branch' => fn (string $id): array => ['action' => 'union', 'queries' => [
            ['source' => ['table' => 'Orders', 'database' => $id], 'fields' => ['Id']], ['source' => ['table' => 'Orders'], 'fields' => ['Id']]]],
        'sql' => fn (string $id): array => ['action' => 'sql', 'database' => $id, 'resource' => 'widgets/sales-month-wise'],
        'metadata' => fn (string $id): array => ['action' => 'metadata.tables', 'database' => $id],
        'invalid body' => fn (string $id): array => ['action' => 'select', 'database' => $id, 'source' => ['table' => 'Orders'], 'fields' => 'not-a-list'],
    ];
    foreach ($probeRequests as $shape => $request) {
        foreach ($databaseProbes as $state => $id) {
            $denied($request($id), 'frontend reader', "An out-of-scope {$state} database was distinguishable through {$shape}.");
            $denied($request($id), 'managed API key', "An out-of-scope {$state} database was distinguishable to an API key through {$shape}.");
        }
    }
    scopeAssert(str_contains($logFiles(), 'payrolldb') && str_contains($logFiles(), 'database_not_in_scope'), 'The protected log lacks the probed database id.');
    // Unrestricted principals keep the availability diagnostics.
    foreach (['payrolldb' => [404, 'DATABASE_NOT_FOUND'], 'inventory' => [503, 'DATABASE_UNAVAILABLE'], 'archive' => [403, 'DATABASE_DISABLED']] as $id => [$status, $code]) {
        [$ok, $actualStatus, $actualCode, $statements] = $run($probeRequests['select']($id), $principals['system administrator'], $enforce);
        scopeAssert(!$ok && $actualStatus === $status && $actualCode === $code && $statements === 0,
            "An unrestricted principal lost the {$code} diagnostic ({$actualStatus} {$actualCode}).");
    }
    // A database inside the caller's scope still reports its availability.
    $availability->setAvailable(false, 'company');
    [$ok, $status, $code] = $run($customers + ['fields' => ['Id']], $principals['frontend reader'], $enforce);
    scopeAssert(!$ok && $status === 503 && $code === 'DATABASE_UNAVAILABLE', "An in-scope unavailable database was not reported ({$status} {$code}).");
    $availability->setAvailable(true, 'company');
    // Log-only keeps the legacy gate responses and records the probe once.
    $before = substr_count($logFiles(), 'authorization.scope_would_deny');
    [$ok, $status, $code] = $run($probeRequests['select']('payrolldb'), $principals['frontend reader'], $logOnly);
    scopeAssert(!$ok && $status === 404 && $code === 'DATABASE_NOT_FOUND', "Log-only mode changed the legacy availability response ({$status} {$code}).");
    scopeAssert(substr_count($logFiles(), 'authorization.scope_would_deny') === $before + 1, 'Log-only mode did not record the database probe exactly once.');
    $before = substr_count($logFiles(), 'authorization.scope_would_deny');
    $allowed(['action' => 'select', 'source' => ['table' => 'Payroll'], 'fields' => ['Id']], 'frontend reader', 'Log-only mode changed a legacy response.', $logOnly);
    scopeAssert(substr_count($logFiles(), 'authorization.scope_would_deny') === $before + 1, 'Phases 0 and 1 recorded one log-only request twice.');
    $availability->setAvailable(true, 'inventory');
    $registry->deleteDatabase('archive');

    // Readiness: a valid file is not production-ready while it names a
    // database or SQL Resource that does not exist; broad grants and roles
    // in use without a scope are listed for an explicit owner decision.
    $readiness = AccessScopeReadiness::review(AccessScopeConfiguration::fromArray(['version' => 1, 'mode' => 'enforce', 'scopes' => [
        'frontend' => ['actions' => ['select', 'sql'], 'databases' => [
            'company' => ['tables' => ['Orders' => '*', 'Customers' => ['Id']], 'resources' => ['widgets/sales-month-wise' => ['filters' => ['Bill_Date']],
                'reports/*' => ['filters' => []], 'widgets/retired' => ['filters' => []], 'archive/*' => ['filters' => []]]],
            'payrolldb' => ['tables' => ['Staff' => ['Id']]],
        ]],
        'read-only' => ['actions' => ['metadata'], 'listDatabases' => true, 'databases' => ['company' => ['metadata' => ['tables']]]],
        'system-administrator' => ['unrestricted' => true],
    ]]), ['company', 'reporting'], ['widgets/sales-month-wise', 'reports/customer'], ['frontend' => 3, 'data-operator' => 1, 'read-only' => 0]);
    foreach (['scopes.frontend.databases.payrolldb: not a registered database', 'scopes.frontend.databases.company.resources.widgets/retired: no such SQL Resource',
        'scopes.frontend.databases.company.resources.archive/*: matches no SQL Resource'] as $expected) {
        scopeAssert(in_array($expected, $readiness['blocking'], true), "Readiness did not block: {$expected}");
    }
    scopeAssert(count($readiness['blocking']) === 3, 'Readiness blocked a complete entry: ' . json_encode($readiness['blocking']));
    $reviewText = implode("\n", $readiness['review']);
    foreach (['scopes.system-administrator: unrestricted', 'tables.Orders: every column', 'resources.reports/*: every current and future',
        'widgets/sales-month-wise.filters: confirm each column may be filtered (Bill_Date)', 'scopes.read-only.listDatabases', 'company.metadata: lists every object',
        'data-operator: 1 enabled principal(s) rely on it but it has no scope'] as $expected) {
        scopeAssert(str_contains($reviewText, $expected), "Readiness review lacks: {$expected}");
    }
    scopeAssert(!str_contains($reviewText, 'Customers'), 'Readiness flagged a column-restricted table as broad.');
    scopeAssert(!str_contains($reviewText, 'read-only: 0'), 'Readiness flagged an unused subject.');
    scopeAssert(AccessScopeReadiness::review($missingProduction, ['company'], [], [])['blocking'] === ['configuration is missing'], 'A missing file was reported ready.');

    // The draft generator grants only the columns the frontend uses, lists
    // hidden filter columns for review, and never produces an enforced file.
    $frontendConfig = $directory . '/frontend-config';
    foreach (['reports', 'dashboards'] as $path) mkdir($frontendConfig . '/' . $path, 0700, true);
    file_put_contents($frontendConfig . '/reports/customers.json', json_encode([
        'request' => ['action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => ['Name', ['function' => 'COUNT', 'field' => '*', 'alias' => 'Total']], 'groupBy' => ['Name']],
        'columns' => [['field' => 'Name'], ['field' => 'Total']],
        'filters' => [['field' => 'Name', 'type' => 'text'], ['field' => 'City', 'type' => 'text',
            'dynamicOptions' => ['request' => ['action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => ['City'], 'groupBy' => ['City']]]]],
    ]));
    file_put_contents($frontendConfig . '/dashboards/sales.json', json_encode([
        'filters' => [['field' => 'Bill_Date', 'type' => 'daterange']],
        'widgets' => [
            ['id' => 'sales', 'queryDefinition' => ['resource' => 'widgets/sales-month-wise'], 'columns' => [['field' => 'Month'], ['field' => 'Sales']], 'filters' => [['field' => 'Region']]],
            ['id' => 'joined', 'request' => ['action' => 'select', 'source' => ['table' => 'Orders', 'alias' => 'o'], 'fields' => ['o.Id'],
                'joins' => [['type' => 'INNER', 'source' => ['table' => 'Customers', 'alias' => 'c'], 'on' => [['left' => 'o.CustomerId', 'operator' => '=', 'right' => 'c.Id']]]]]],
        ],
    ]));
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/generate-access-scope-draft.php', $frontendConfig, 'company'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $draftJson = stream_get_contents($pipes[1]);
    $draftReview = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    scopeAssert(proc_close($process) === 0, 'The draft generator failed: ' . $draftReview);
    $draft = json_decode($draftJson, true);
    scopeAssert(is_array($draft) && $draft['mode'] === 'log-only', 'The draft is not valid log-only JSON.');
    $draftCompany = $draft['scopes']['frontend']['databases']['company'];
    scopeAssert($draftCompany['tables']['Customers'] === '*' && $draftCompany['tables']['Orders'] === '*', 'A joined request was not granted "*" for manual review.');
    scopeAssert($draftCompany['resources']['widgets/sales-month-wise'] === ['filters' => ['Bill_Date', 'Region']], 'The draft resource filters are wrong.');
    foreach (['hidden filter column company:widgets/sales-month-wise → Bill_Date', 'hidden filter column company:widgets/sales-month-wise → Region',
        'hidden filter column company:Customers.City', 'joined table company:Customers granted "*"', 'DRAFT ONLY'] as $expected) {
        scopeAssert(str_contains($draftReview, $expected), "The draft review lacks: {$expected}");
    }
    scopeAssert(!str_contains($draftReview, 'Customers.Name'), 'A displayed filter column was reported as hidden.');
    // Without the joined widget, the structured report gets an exact column list.
    unlink($frontendConfig . '/dashboards/sales.json');
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/scripts/generate-access-scope-draft.php', $frontendConfig, 'company'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $draft = json_decode(stream_get_contents($pipes[1]), true);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    scopeAssert($draft['scopes']['frontend']['databases']['company']['tables'] === ['Customers' => ['City', 'Name']], 'The draft did not derive minimal columns: '
        . json_encode($draft['scopes']['frontend']['databases']['company']['tables']));
    $draft['mode'] = 'enforce';
    $generated = AccessScopeConfiguration::fromArray($draft);
    $allowed(['action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => ['Name', ['function' => 'COUNT', 'field' => '*', 'alias' => 'Total']], 'groupBy' => ['Name'],
        'filters' => [['field' => 'City', 'operator' => '=', 'value' => 'x']]], 'frontend reader', 'The generated draft denied its own frontend request.', $generated);
    $denied(['action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => ['Id']], 'frontend reader', 'The generated draft granted an unused column.', $generated);
    $denied(['action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => ['*']], 'frontend reader', 'The generated draft granted every column.', $generated);

    // Stage F: anonymous mode in production.
    $adminConfiguration = new AdminConfigurationRepository();
    $settings = $adminConfiguration->load();
    $settings['authentication']['mode'] = 'none';
    $adminConfiguration->save($settings);
    $authenticate = function (): ?Principal {
        (new AuthenticationMiddleware(true, []))->handle(['action' => 'select']);
        return PrincipalContext::current();
    };
    $anonymous = $authenticate();
    scopeAssert($anonymous !== null && $anonymous->backendRole === null, 'Development anonymous mode granted a default role.');
    try {
        (new AuthorizationMiddleware())->handle(['action' => 'select']);
        throw new LogicException('An anonymous principal without roles could read data.');
    } catch (ApiRequestException $exception) {
        scopeAssert($exception->getErrorCode() === 'AUTHORIZATION_DENIED', 'Anonymous denial used the wrong code.');
    }
    putenv('GENERIC_APP_ENV=production');
    try {
        $authenticate();
        throw new LogicException('Anonymous mode was accepted in production.');
    } catch (ApiRequestException $exception) {
        scopeAssert($exception->getStatusCode() === 503 && $exception->getErrorCode() === 'AUTHENTICATION_UNAVAILABLE', 'Production anonymous refusal used the wrong response.');
    }
    $validation = (new ProductionValidator())->report()['environment'];
    scopeAssert($validation['anonymousAuthentication']['status'] === ProductionValidator::OPERATOR, 'Production validation did not flag anonymous mode.');
    scopeAssert($validation['accessScopes']['status'] === ProductionValidator::OPERATOR && $validation['accessScopes']['state'] === 'missing',
        'Production validation did not flag missing access scopes.');
    foreach (['TRUE', '1', 'yes', ''] as $notOptIn) {
        putenv('GENERIC_ALLOW_ANONYMOUS_PRODUCTION=' . $notOptIn);
        scopeAssert(!SecurityConfiguration::anonymousAuthenticationPermitted(), "'{$notOptIn}' opted in to anonymous production mode.");
    }
    putenv('GENERIC_ALLOW_ANONYMOUS_PRODUCTION=true');
    $optedIn = $authenticate();
    scopeAssert($optedIn !== null && $optedIn->authenticationType === 'none' && $optedIn->backendRole === null,
        'The explicit production opt-in did not produce a role-less anonymous principal.');
    putenv('GENERIC_ALLOW_ANONYMOUS_PRODUCTION');
    putenv('GENERIC_APP_ENV');
    PrincipalContext::clear();

    echo "Access scope authorization tests passed.\n";
} finally {
    AccessScopeContext::clear();
    DatabaseQueryPlanContext::clear();
    PrincipalContext::clear();
    foreach ($previous as $name => $value) $value === false ? putenv($name) : putenv($name . '=' . $value);
    scopeRemove($directory);
}
