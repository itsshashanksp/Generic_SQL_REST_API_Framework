<?php

require_once __DIR__ . '/../app/Runtime/ApplicationRuntimeManager.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Repositories/AuthorizationRepository.php';
require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';

/*
 * Exercises the real API, SQL Parser, and Admin entry points with
 * GENERIC_APP_ENV=production. PHP's built-in server is only the test harness
 * that delivers HTTP requests to those entry points; production availability
 * itself is changed exclusively through ApplicationRuntimeManager state.
 */

function availabilityAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function availabilityRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') availabilityRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function availabilityStartServer(string $root, string $boundary, array $environment): array
{
    $port = null;
    foreach (range(18600 + random_int(0, 30) * 10, 18999) as $candidate) {
        $reservation = @stream_socket_server("tcp://127.0.0.1:{$candidate}");
        if (!is_resource($reservation)) continue;
        fclose($reservation);
        $port = $candidate;
        break;
    }
    availabilityAssert($port !== null, 'Unable to reserve a test port.');
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) $command[] = '-n';
    array_push($command, '-S', "127.0.0.1:{$port}", '-t', "{$root}/{$boundary}", "{$root}/{$boundary}/router.php");
    $server = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $root,
        array_merge(is_array(getenv()) ? getenv() : [], $environment)
    );
    availabilityAssert(is_resource($server), "Unable to start the {$boundary} test server.");
    $deadline = microtime(true) + 10;
    while (!($probe = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2)) && microtime(true) < $deadline) {
        usleep(100000);
    }
    if (!is_resource($probe)) {
        proc_terminate($server);
        proc_close($server);
        throw new RuntimeException("The {$boundary} test server did not start.");
    }
    fclose($probe);
    return [$server, $port];
}

function availabilityHttp(int $port, string $method, string $path, ?array $json = null): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => $json === null ? '' : "Content-Type: application/json\r\n",
        'content' => $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    $headers = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    availabilityAssert($body !== false && $headers !== [], "Test server did not answer {$method} {$path}.");
    preg_match('/^HTTP\/\S+\s+(\d{3})/', $headers[0], $status);
    $decoded = json_decode((string)$body, true);
    return [
        'status' => (int)$status[1],
        'body' => (string)$body,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function availabilityUnavailable(array $response): bool
{
    return $response['status'] === 503
        && ($response['json']['success'] ?? null) === false
        && ($response['json']['error']['code'] ?? null) === 'SERVICE_UNAVAILABLE';
}

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/generic-production-availability-' . bin2hex(random_bytes(8));
$configurationDirectory = $directory . '/config';
$oldConfigurationDirectory = getenv('GENERIC_RUNTIME_CONFIG_DIR');
$servers = [];

try {
    mkdir($configurationDirectory, 0700, true);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $configurationDirectory);
    RuntimeConfiguration::ensure();
    // Normal API actions run without credentials so the database gate is
    // reachable: production anonymous access needs the explicit opt-in and
    // public roles assigned by the deployment.
    (new AdminConfigurationRepository())->update(function (array &$settings): array {
        $settings['authentication']['mode'] = 'none';
        return [];
    });
    (new AuthorizationRepository())->save(['publicRoles' => [RoleModel::READ_ONLY]] + RuntimeConfiguration::authorizationDefaults());
    $runtime = new ApplicationRuntimeManager(RuntimeConfiguration::path(RuntimeConfiguration::APPLICATION_RUNTIME_STATE_FILE));
    $database = new DatabaseAvailabilityManager(RuntimeConfiguration::path(RuntimeConfiguration::DATABASE_STATE_FILE));
    $environment = [
        'GENERIC_APP_ENV' => 'production',
        'GENERIC_RUNTIME_CONFIG_DIR' => $configurationDirectory,
        'GENERIC_ADMIN_ENABLED' => '0',
        'GENERIC_ALLOW_ANONYMOUS_PRODUCTION' => 'true',
    ];
    [$servers['api'], $apiPort] = availabilityStartServer($root, 'api', $environment);
    [$servers['sqlparser'], $parserPort] = availabilityStartServer($root, 'sqlparser', $environment);
    [$servers['admin'], $adminPort] = availabilityStartServer($root, 'admin', ['GENERIC_ADMIN_ENABLED' => '1'] + $environment);

    $setupStatus = ['action' => 'setup.status'];
    $select = ['action' => 'select', 'source' => ['table' => 'Items'], 'fields' => ['ItemCode']];
    $parse = ['sql' => 'SELECT ItemCode FROM Items'];

    // 2 and 4. Enabled API and SQL Parser serve requests unchanged.
    availabilityAssert(
        $runtime->enabled('api') && $runtime->enabled('sqlParser'),
        'Production application runtimes did not start enabled.'
    );
    $apiEnabled = availabilityHttp($apiPort, 'POST', '/', $setupStatus);
    availabilityAssert(
        $apiEnabled['status'] === 200 && ($apiEnabled['json']['success'] ?? null) === true,
        'Enabled production API did not serve a request.'
    );
    $parserPage = availabilityHttp($parserPort, 'GET', '/');
    $parserResult = availabilityHttp($parserPort, 'POST', '/', $parse);
    availabilityAssert(
        $parserPage['status'] === 200 && str_contains($parserPage['body'], 'SQL → API JSON Generator')
            && $parserResult['status'] === 200 && ($parserResult['json']['success'] ?? null) === true
            && ($parserResult['json']['request']['action'] ?? null) === 'select',
        'Enabled production SQL Parser did not serve its page and parse requests.'
    );

    // 1. A disabled API rejects real requests at the API entry point.
    $runtime->control('api', 'stop');
    foreach ([$setupStatus, $select] as $request) {
        $response = availabilityHttp($apiPort, 'POST', '/', $request);
        availabilityAssert(
            availabilityUnavailable($response) && !str_contains($response['body'], $configurationDirectory),
            "Disabled production API accepted {$request['action']}."
        );
    }

    // 6. Liveness and readiness remain reachable while the API is disabled.
    foreach (['/health', '/health/live', '/health/ready'] as $path) {
        $health = availabilityHttp($apiPort, 'GET', $path);
        availabilityAssert(
            in_array($health['status'], [200, 503], true) && isset($health['json']['status'])
                && ($health['json']['error']['code'] ?? null) !== 'SERVICE_UNAVAILABLE',
            "Health route {$path} was blocked by the disabled API runtime."
        );
    }
    availabilityAssert(
        availabilityHttp($apiPort, 'GET', '/health/live')['status'] === 200,
        'API liveness failed while only the application runtime was disabled.'
    );
    $notReady = availabilityHttp($apiPort, 'GET', '/health/ready');
    availabilityAssert(
        $notReady['status'] === 503 && ($notReady['json']['checks']['application']['category'] ?? null) === 'api_disabled',
        'A disabled production API was reported ready to receive traffic.'
    );

    // The disabled API does not disable the independent SQL Parser.
    availabilityAssert(
        availabilityHttp($parserPort, 'POST', '/', $parse)['status'] === 200,
        'Disabling the API also disabled the SQL Parser.'
    );

    // 5. The Admin control plane stays reachable and keeps its authentication.
    $adminSetup = availabilityHttp($adminPort, 'POST', '/api.php', $setupStatus);
    availabilityAssert(
        $adminSetup['status'] === 200 && ($adminSetup['json']['success'] ?? null) === true,
        'Admin Console became unreachable while the API runtime was disabled.'
    );
    foreach (['admin.api.start', 'admin.sqlParser.stop', 'admin.status'] as $action) {
        $denied = availabilityHttp($adminPort, 'POST', '/api.php', ['action' => $action]);
        availabilityAssert(
            in_array($denied['status'], [401, 403], true) && ($denied['json']['success'] ?? null) === false,
            "Unauthenticated Admin request {$action} was not rejected."
        );
    }
    availabilityAssert(
        !$runtime->enabled('api') && $runtime->enabled('sqlParser'),
        'Unauthenticated Admin requests changed application availability.'
    );

    // Re-enabling restores the unchanged API request path.
    $runtime->control('api', 'start');
    $ready = availabilityHttp($apiPort, 'GET', '/health/ready');
    availabilityAssert(
        ($ready['json']['checks']['application']['category'] ?? null) === 'api_enabled'
            && ($ready['json']['checks']['database']['category'] ?? null) === 'database_disconnected'
            && $ready['status'] === 503,
        'Readiness did not report enabled API and the closed database gate separately.'
    );
    availabilityAssert(
        availabilityHttp($apiPort, 'POST', '/', $setupStatus)['status'] === 200,
        'Re-enabled production API did not serve requests.'
    );

    // 3. A disabled SQL Parser rejects page and parse requests.
    $runtime->control('sqlParser', 'stop');
    foreach ([['GET', null], ['POST', $parse]] as [$method, $body]) {
        availabilityAssert(
            availabilityUnavailable(availabilityHttp($parserPort, $method, '/', $body)),
            "Disabled production SQL Parser accepted a {$method} request."
        );
    }
    availabilityAssert(
        availabilityHttp($apiPort, 'POST', '/', $setupStatus)['status'] === 200,
        'Disabling the SQL Parser also disabled the API.'
    );
    $runtime->control('sqlParser', 'restart');
    availabilityAssert(
        availabilityHttp($parserPort, 'POST', '/', $parse)['status'] === 200,
        'Reloaded production SQL Parser did not serve requests.'
    );

    // 7. Database availability is a separate gate from API availability.
    // Production without an access scope file fails closed before that gate;
    // an explicit enforced scope for this one request reaches it.
    $database->setAvailable(false);
    $scopeDenied = availabilityHttp($apiPort, 'POST', '/', $select);
    availabilityAssert(
        $scopeDenied['status'] === 403 && ($scopeDenied['json']['error']['code'] ?? null) === 'AUTHORIZATION_DENIED',
        'Production without access scopes did not fail closed.'
    );
    file_put_contents($configurationDirectory . '/access-scopes.json', json_encode(['version' => 1, 'mode' => 'enforce', 'scopes' => [
        'read-only' => ['actions' => ['select'], 'databases' => [
            ((new DatabaseContextResolver())->defaultDatabaseId() ?? 'default') => ['tables' => ['Items' => ['ItemCode']]],
        ]],
    ]]));
    $databaseClosed = availabilityHttp($apiPort, 'POST', '/', $select);
    availabilityAssert(
        $databaseClosed['status'] === 503
            && ($databaseClosed['json']['error']['code'] ?? null) === 'DATABASE_UNAVAILABLE',
        'A disconnected database was reported as a disabled API or was not enforced.'
    );
    availabilityAssert(
        availabilityHttp($apiPort, 'POST', '/', $setupStatus)['status'] === 200
            && availabilityHttp($parserPort, 'POST', '/', $parse)['status'] === 200
            && $runtime->enabled('api') && $runtime->enabled('sqlParser'),
        'Database availability changed API or SQL Parser application availability.'
    );
    $runtime->control('api', 'stop');
    availabilityAssert(
        $database->available() === false
            && availabilityUnavailable(availabilityHttp($apiPort, 'POST', '/', $select)),
        'A disabled API did not take precedence over the database gate.'
    );
    $database->setAvailable(true);
    availabilityAssert(
        !$runtime->enabled('api'),
        'Connecting the database re-enabled the API application runtime.'
    );

    // The production request path never created development process state.
    foreach (['api/api-process.json', 'sqlparser/sqlparser-process.json'] as $state) {
        $path = $root . '/runtime/' . $state;
        availabilityAssert(
            !is_file($path) || filemtime($path) < (int)($_SERVER['REQUEST_TIME'] ?? time()),
            "Production availability changes wrote development process state {$state}."
        );
    }

    echo "Production application availability tests passed.\n";
} finally {
    foreach ($servers as $server) {
        proc_terminate($server);
        proc_close($server);
    }
    $oldConfigurationDirectory === false
        ? putenv('GENERIC_RUNTIME_CONFIG_DIR')
        : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldConfigurationDirectory);
    availabilityRemove($directory);
}
