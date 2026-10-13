<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../app/Repositories/AuthRepository.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Services/ApiKeyService.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';

/*
 * Access scopes through the real API entry point (api/index.php) over HTTP:
 * each scope phase denies before the step it protects — phase 0 before the
 * availability gate, phase 1 before planning reads SQL Resources, phase 2
 * before execution — with one identical denial whatever exists, and log-only
 * and invalid files behave as documented.
 *
 * The server runs a temporary copy of the application code, because the
 * database registry lives under the code root; runtime configuration, logs,
 * and the scope file are temporary too. The only server profile points at a
 * closed loopback port, so a request that passes every gate ends when the
 * controller fails to connect; nothing leaves the host.
 */

function scopeHttpAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function scopeHttpRemove(string $path): void
{
    if (!is_dir($path) || is_link($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') scopeHttpRemove($path . DIRECTORY_SEPARATOR . $entry);
    @rmdir($path);
}

/** Copies application code only: no runtime configuration, registry, logs, or state. */
function scopeHttpCopy(string $from, string $to, callable $include): void
{
    mkdir($to, 0700, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $source = $from . '/' . $entry;
        if (is_link($source)) continue;
        if (is_dir($source)) scopeHttpCopy($source, $to . '/' . $entry, $include);
        elseif ($include($source)) copy($source, $to . '/' . $entry);
    }
}

function scopeHttpRequest(int $port, array $body, ?string &$cookie = null, array $headers = []): array
{
    $lines = ['Content-Type: application/json'];
    foreach ($headers as $name => $value) $lines[] = "{$name}: {$value}";
    if ($cookie !== null) $lines[] = "Cookie: {$cookie}";
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $lines),
        'content' => json_encode($body, JSON_THROW_ON_ERROR), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30]]);
    $response = @file_get_contents("http://127.0.0.1:{$port}/", false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    scopeHttpAssert($response !== false && $responseHeaders !== [], 'The test server did not answer.');
    preg_match('/^HTTP\/\S+\s+(\d{3})/', $responseHeaders[0], $status);
    foreach ($responseHeaders as $header) if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match) === 1) $cookie = $match[1];
    $json = json_decode((string)$response, true);
    if (is_array($json)) unset($json['meta']['requestId']);
    return ['status' => (int)$status[1], 'code' => is_array($json) ? ($json['error']['code'] ?? null) : null, 'json' => $json, 'body' => (string)$response];
}

$source = dirname(__DIR__);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-scope-http-' . bin2hex(random_bytes(8));
$application = $directory . '/application';
$runtime = $directory . '/runtime';
$previous = [];
foreach (['GENERIC_RUNTIME_CONFIG_DIR', 'GENERIC_LOG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_SECURITY_STORAGE_DIR', 'GENERIC_APP_ENV',
    DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE] as $name) $previous[$name] = getenv($name);
$server = null;

try {
    // Application code only: entry points, classes, SQL Resources, and shipped examples.
    foreach (['api', 'app', 'core', 'queries'] as $path) scopeHttpCopy("{$source}/{$path}", "{$application}/{$path}", fn (): bool => true);
    scopeHttpCopy("{$source}/database", "{$application}/database", fn (string $file): bool => !str_contains($file, '/database/config/'));
    scopeHttpCopy("{$source}/config", "{$application}/config", fn (string $file): bool => str_ends_with($file, '.php') || str_ends_with($file, '.example.json'));
    foreach (['runtime', 'logs', 'operational', 'security', 'application/database/config'] as $path) @mkdir("{$directory}/{$path}", 0700, true);
    $environment = [
        'GENERIC_RUNTIME_CONFIG_DIR' => $runtime,
        'GENERIC_LOG_DIR' => "{$directory}/logs",
        'GENERIC_OPERATIONAL_LOG_DIR' => "{$directory}/operational",
        'GENERIC_SECURITY_STORAGE_DIR' => "{$directory}/security",
    ];
    foreach ($environment as $name => $value) putenv("{$name}={$value}");
    putenv('GENERIC_APP_ENV');
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . base64_encode(random_bytes(32)));
    RuntimeConfiguration::ensure();

    // Registry of the copy: company (default), reporting, inventory (unavailable), archive (disabled).
    $registry = new DatabaseRegistry("{$application}/database/config/databases.json", "{$application}/database/config/database.json");
    $registry->saveServer('sql01', 'SQL Server 01', true, ['provider' => 'sqlserver', 'driver' => 'ODBC Driver 18 for SQL Server',
        'server' => '127.0.0.1', 'port' => '1', 'authentication' => 'sql', 'username' => 'scope_user', 'password' => 'scope-http-secret',
        'options' => ['encrypt' => true, 'trustServerCertificate' => false, 'loginTimeoutSeconds' => 1]]);
    foreach (['company' => 'CompanyDB', 'reporting' => 'ReportingDB', 'inventory' => 'InventoryDB'] as $id => $catalog) {
        $registry->saveDatabase($id, ucfirst($id), 'sql01', true, $catalog);
    }
    $registry->saveDatabase('archive', 'Archive', 'sql01', false, 'ArchiveDB');
    scopeHttpAssert($registry->defaultDatabaseId() === 'company', 'The copy has an unexpected default database.');
    $availability = new DatabaseAvailabilityManager(RuntimeConfiguration::path(RuntimeConfiguration::DATABASE_STATE_FILE), $registry);
    foreach (['company', 'reporting', 'archive'] as $id) $availability->setAvailable(true, $id);
    $availability->setAvailable(false, 'inventory');

    // A frontend Read Only user (session) and a read-only API key.
    (new AdminConfigurationRepository())->update(function (array &$settings): array {
        $settings['authentication']['mode'] = 'session+api_key';
        return [];
    });
    $users = new UserManagementService(new AuthRepository());
    $users->createUser('Scope.Admin', 'scope-http-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR);
    $admin = (new AuthRepository())->findUser('Scope.Admin');
    $users->createFrontendUser(new Principal($admin['id'], $admin['username'], 'session', $admin['backendRole'], true, $admin['frontendRole'], true),
        'Scope Reader', 'Scope.Reader', '+15551230000', null, 'scope-http-reader-password', RoleModel::READ_ONLY);
    $apiKey = (new ApiKeyService())->create('Scope HTTP key', 'Scope.Admin', [RoleModel::READ_ONLY])['apiKey'];

    $scopePath = "{$runtime}/access-scopes.json";
    $writeScopes = function (string $mode) use ($scopePath): void {
        file_put_contents($scopePath . '.tmp', json_encode(['version' => 1, 'mode' => $mode, 'scopes' => [
            'frontend' => ['actions' => ['select', 'sql'], 'databases' => ['company' => [
                'tables' => ['Customers' => ['Id', 'Name']],
                'resources' => ['widgets/sales-month-wise' => ['filters' => ['Bill_Date']], 'widgets/retired-resource' => ['filters' => []]],
            ]]],
            'read-only' => ['actions' => ['select', 'metadata'], 'databases' => ['company' => ['tables' => ['Customers' => ['Id']], 'metadata' => ['tables']]]],
        ]], JSON_PRETTY_PRINT));
        rename($scopePath . '.tmp', $scopePath);
    };
    $writeScopes('enforce');

    // The real entry point of the copy.
    $port = null;
    foreach (range(19950 + random_int(0, 4) * 10, 20200) as $candidate) {
        $reservation = @stream_socket_server("tcp://127.0.0.1:{$candidate}");
        if (!is_resource($reservation)) continue;
        fclose($reservation);
        $port = $candidate;
        break;
    }
    scopeHttpAssert($port !== null, 'Unable to reserve a test port.');
    $serverEnvironment = array_merge(is_array(getenv()) ? getenv() : [], $environment);
    $serverEnvironment[DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE] = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    unset($serverEnvironment['GENERIC_APP_ENV']);
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) $command[] = '-n';
    array_push($command, '-S', "127.0.0.1:{$port}", '-t', "{$application}/api", "{$application}/api/router.php");
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $application, $serverEnvironment);
    scopeHttpAssert(is_resource($server), 'Unable to start the API test server.');
    $deadline = microtime(true) + 10;
    while (!($probe = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2)) && microtime(true) < $deadline) usleep(100000);
    scopeHttpAssert(is_resource($probe), 'The API test server did not start.');
    fclose($probe);

    $cookie = null;
    $csrf = scopeHttpRequest($port, ['action' => 'auth.csrf'], $cookie)['json']['data'][0]['csrfToken'] ?? null;
    $login = scopeHttpRequest($port, ['action' => 'auth.login', 'username' => 'Scope.Reader', 'password' => 'scope-http-reader-password'], $cookie, ['X-CSRF-Token' => (string)$csrf]);
    scopeHttpAssert($login['status'] === 200, "The frontend reader could not log in ({$login['status']}).");
    $reader = fn (array $body): array => scopeHttpRequest($port, $body, $cookie);
    $key = fn (array $body): array => scopeHttpRequest($port, $body, headers: ['X-API-Key' => $apiKey]);

    $failures = [];
    $expect = function (string $label, array $response, int $status, string $code) use (&$failures): void {
        if ($response['status'] !== $status || $response['code'] !== $code) $failures[] = "{$label}: expected {$status} {$code}, got {$response['status']} {$response['code']}";
    };
    $denied = fn (string $label, array $response) => $expect($label, $response, 403, 'AUTHORIZATION_DENIED');
    // A request that passed every scope phase ends when the controller cannot connect,
    // which is distinct from the availability gate's answer.
    $gateMessage = 'Database access is currently unavailable.';
    $passed = function (string $label, array $response) use (&$failures, $gateMessage): void {
        $connection = ['DATABASE_UNAVAILABLE', 'DATABASE_CONNECTION_FAILED', 'DATABASE_CONNECTION_TIMEOUT', 'DATABASE_AUTHENTICATION_FAILED'];
        if ($response['status'] !== 503 || !in_array($response['code'], $connection, true) || ($response['json']['message'] ?? null) === $gateMessage) {
            $failures[] = "{$label}: did not reach execution ({$response['status']} {$response['code']} " . ($response['json']['message'] ?? '') . ')';
        }
    };
    $same = function (string $label, array $responses) use (&$failures): void {
        $bodies = array_unique(array_map(fn (array $response): string => json_encode($response['json']), $responses));
        if (count($bodies) !== 1) $failures[] = "{$label}: responses differ: " . implode(' | ', $bodies);
        foreach ($bodies as $body) if (preg_match('/reporting|inventory|archive|payrolldb|sales-day-wise|no-such|Secret|Payroll/i', $body)) $failures[] = "{$label}: the response names the object: {$body}";
    };
    $select = fn (?string $database, string $field = 'Name'): array => ['action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => [$field]]
        + ($database === null ? [] : ['database' => $database]);
    $sales = fn (array $extra = []): array => ['action' => 'sql', 'resource' => 'widgets/sales-month-wise'] + $extra;

    // Requests that pass every phase reach execution (the markers the denials are compared with).
    $passed('in-scope select', $reader($select(null)));
    $passed('in-scope SQL Resource with its filter', $reader($sales(['execution' => ['filters' => ['Bill_Date' => ['placement' => 'source']]],
        'filters' => [['field' => 'Bill_Date', 'operator' => 'BETWEEN', 'value' => ['2021-06-01', '2021-06-30'], 'type' => 'daterange']]])));
    $passed('API key: granted metadata operation', $key(['action' => 'metadata.tables']));

    // Phase 0, before the availability gate: one denial for every out-of-scope database state.
    $databaseProbes = [];
    foreach (['payrolldb' => 'unregistered', 'reporting' => 'registered', 'inventory' => 'unavailable', 'archive' => 'disabled'] as $id => $state) {
        $databaseProbes[$state] = $reader($select($id));
        $denied("phase 0: {$state} database", $databaseProbes[$state]);
    }
    $same('phase 0: database states', $databaseProbes);
    // In scope, the availability gate still answers (scope first, then availability).
    $availability->setAvailable(false, 'company');
    $gated = $reader($select(null));
    $expect('in-scope unavailable database', $gated, 503, 'DATABASE_UNAVAILABLE');
    if (($gated['json']['message'] ?? null) !== $gateMessage) $failures[] = 'in-scope unavailable database: not answered by the availability gate';
    $availability->setAvailable(true, 'company');

    // Phase 1, before planning reads SQL Resources: existing and missing resources look alike.
    $resources = [
        'existing' => $reader(['action' => 'sql', 'resource' => 'widgets/sales-day-wise']),
        'missing' => $reader(['action' => 'sql', 'resource' => 'widgets/no-such-resource']),
    ];
    foreach ($resources as $state => $response) $denied("phase 1: {$state} resource outside the scope", $response);
    $same('phase 1: SQL Resources', $resources);
    // In scope, planning runs and reports a missing resource.
    $retired = $reader(['action' => 'sql', 'resource' => 'widgets/retired-resource']);
    if ($retired['status'] === 403 || $retired['status'] < 400) $failures[] = "in-scope missing resource: planning did not report it ({$retired['status']} {$retired['code']})";
    $denied('phase 1: database listing without listDatabases', $key(['action' => 'metadata.databases']));

    // Phase 2, after planning and before execution: filters and metadata operations.
    $denied('phase 2: undeclared runtime filter', $reader($sales(['filters' => [['field' => 'Salary', 'operator' => '>', 'value' => 0]]])));
    $denied('phase 2: undeclared source mapping', $reader($sales(['execution' => ['filters' => ['Salary' => ['placement' => 'source']]]])));
    $denied('phase 2: client expression mapping', $reader($sales(['execution' => ['filters' => ['Bill_Date' => ['placement' => 'source', 'expression' => 'BIL.Salary']]]])));
    $denied('phase 2: HAVING mapping', $reader($sales(['execution' => ['filters' => ['Bill_Date' => ['placement' => 'having', 'expression' => 'SUM(Salary)']]]])));
    $denied('phase 2: ungranted metadata operation', $key(['action' => 'metadata.columns', 'source' => ['table' => 'Customers']]));
    $denied('phase 0: action outside the scope', $reader(['action' => 'metadata.tables']));
    $denied('phase 0: union outside the scope', $key(['action' => 'union', 'queries' => [['source' => ['table' => 'Customers'], 'fields' => ['Id']],
        ['source' => ['table' => 'Customers'], 'fields' => ['Id']]]]));

    // Every enforced denial was recorded with its reason; no filter value reached the logs.
    $logs = function () use ($directory): string {
        $text = '';
        foreach (['logs', 'operational'] as $root) {
            if (!is_dir("{$directory}/{$root}")) continue;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$directory}/{$root}", FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) $text .= file_get_contents($file->getPathname()) . "\n";
            }
        }
        return $text;
    };
    $recorded = $logs();
    foreach (['database_not_in_scope', 'resource_not_in_scope', 'metadata_not_in_scope', 'filter_not_in_scope', 'filter_expression_not_in_scope', 'action_not_in_scope'] as $reason) {
        if (!str_contains($recorded, $reason)) $failures[] = "audit: no enforced denial recorded with {$reason}";
    }

    // Log-only: legacy responses, one would-be denial per request, no filter values in the logs.
    $writeScopes('log-only');
    $before = substr_count($logs(), 'authorization.scope_would_deny');
    $legacy = $reader($sales(['filters' => [['field' => 'Salary', 'operator' => '=', 'value' => 'SensitiveFilterValue']]]));
    $passed('log-only: out-of-scope filter keeps its legacy response', $legacy);
    $legacyDatabase = $reader($select('payrolldb'));
    $expect('log-only: unregistered database keeps its legacy answer', $legacyDatabase, 404, 'DATABASE_NOT_FOUND');
    $after = $logs();
    if (substr_count($after, 'authorization.scope_would_deny') !== $before + 2) $failures[] = 'log-only: expected exactly one would-be denial per request';
    if (str_contains($after, 'SensitiveFilterValue') || str_contains($after, 'scope-http-secret')) $failures[] = 'log-only: a filter value or credential reached the logs';

    // An invalid file denies every scoped request, in any mode.
    file_put_contents($scopePath, '{not json');
    $denied('invalid scope file', $reader($select(null)));
    $writeScopes('enforce');
    $passed('restored scope file', $reader($select(null)));

    if ($failures !== []) throw new RuntimeException("Access scope HTTP enforcement failed:\n - " . implode("\n - ", $failures));
    echo "Access scope HTTP enforcement tests passed.\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach ($previous as $name => $value) $value === false ? putenv($name) : putenv($name . '=' . $value);
    scopeHttpRemove($directory);
}
