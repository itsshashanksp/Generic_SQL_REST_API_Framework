<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Repositories/AuthRepository.php';
require_once __DIR__ . '/../app/Repositories/AuthorizationRepository.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Repositories/InstallationRepository.php';
require_once __DIR__ . '/../app/Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Services/ApiKeyService.php';

/*
 * HTTP coverage for the v2.1.3 authorization and API security test plan
 * (64 tests; recorded in docs/security/Security-Verification.md). Each block
 * is labelled with the plan IDs it implements; plan items covered at the
 * enforcement layer live in other suites. The boundaries under test are
 * described in docs/security/Security-Model.md.
 *
 * The database is left unavailable (the runtime default), so a data request
 * that passes authentication, CSRF, and authorization ends at
 * 503 DATABASE_UNAVAILABLE. That status is the "authorized" marker below.
 */

function coverageAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function coverageRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') coverageRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

/** Start a PHP built-in server. Environment values of null are removed. */
function coverageStartServer(string $root, string $documentRoot, string $router, array $environment): array
{
    $port = null;
    foreach (range(19100 + random_int(0, 40) * 10, 19899) as $candidate) {
        $reservation = @stream_socket_server("tcp://127.0.0.1:{$candidate}");
        if (!is_resource($reservation)) continue;
        fclose($reservation);
        $port = $candidate;
        break;
    }
    coverageAssert($port !== null, 'Unable to reserve a test port.');
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) $command[] = '-n';
    // Match the production php.ini under `php -n`: startup warnings (e.g.
    // post_max_size) are not displayed, so they cannot send headers before the
    // 413, and session cleanup is left to the operating system, so probabilistic
    // garbage collection of an unreadable system save path cannot log notices.
    array_push($command, '-d', 'display_errors=0', '-d', 'session.gc_probability=0',
        '-S', "127.0.0.1:{$port}", '-t', "{$root}/{$documentRoot}", $router);
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $processEnvironment = array_merge(is_array(getenv()) ? getenv() : [], $environment);
    foreach ($environment as $name => $value) if ($value === null) unset($processEnvironment[$name]);
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $root, $processEnvironment);
    coverageAssert(is_resource($server), "Unable to start the {$documentRoot} test server.");
    $deadline = microtime(true) + 10;
    while (!($probe = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2)) && microtime(true) < $deadline) {
        usleep(100000);
    }
    if (!is_resource($probe)) {
        proc_terminate($server);
        proc_close($server);
        throw new RuntimeException("The {$documentRoot} test server did not start.");
    }
    fclose($probe);
    return [$server, $port];
}

/**
 * Send one HTTP request. $body is JSON-encoded unless it is a string or null.
 * The session cookie is kept in $cookie when one is set.
 */
function coverageRequest(int $port, string $path, array|string|null $body, ?string &$cookie = null, array $headers = [], string $method = 'POST'): array
{
    $lines = [];
    if (!array_key_exists('Content-Type', $headers)) $lines[] = 'Content-Type: application/json';
    foreach ($headers as $name => $value) if ($value !== null) $lines[] = "{$name}: {$value}";
    if ($cookie !== null) $lines[] = "Cookie: {$cookie}";
    $options = ['method' => $method, 'header' => implode("\r\n", $lines), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30];
    if ($body !== null) $options['content'] = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    $responseBody = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, stream_context_create(['http' => $options]));
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    coverageAssert($responseBody !== false && $responseHeaders !== [], "Test server did not answer {$method} {$path}.");
    preg_match('/^HTTP\/\S+\s+(\d{3})/', $responseHeaders[0], $status);
    $setCookie = null;
    $named = [];
    foreach ($responseHeaders as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match) === 1) $cookie = $setCookie = $match[1];
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $header, $match) === 1) $named[strtolower($match[1])] = trim($match[2]);
    }
    $decoded = json_decode((string)$responseBody, true);
    return ['status' => (int)$status[1], 'json' => is_array($decoded) ? $decoded : null, 'body' => (string)$responseBody,
        'headers' => $named, 'setCookie' => $setCookie];
}

/** Assert status and error code; every observed pair is recorded for P3-01. */
function coverageExpect(array $response, int $status, ?string $code, string $message): void
{
    global $coverageObserved;
    $actualCode = $response['json']['error']['code'] ?? null;
    coverageAssert($response['status'] === $status && ($code === null || $actualCode === $code),
        "{$message} Got {$response['status']} " . (string)$actualCode);
    if (is_string($actualCode)) $coverageObserved["{$response['status']} {$actualCode}"] = true;
}

/** Log in over HTTP and return [cookie, current CSRF token]. */
function coverageLogin(int $port, string $path, string $username, string $password): array
{
    $cookie = null;
    $token = coverageRequest($port, $path, ['action' => 'auth.csrf'], $cookie)['json']['data'][0]['csrfToken'] ?? null;
    $login = coverageRequest($port, $path, ['action' => 'auth.login', 'username' => $username, 'password' => $password], $cookie, ['X-CSRF-Token' => $token]);
    coverageAssert($login['status'] === 200, "Login failed for {$username} ({$login['status']}).");
    $token = coverageRequest($port, $path, ['action' => 'auth.csrf'], $cookie)['json']['data'][0]['csrfToken'] ?? null;
    coverageAssert(is_string($token), "No CSRF token after login for {$username}.");
    return [$cookie, $token];
}

/** Fingerprint of the persisted security state, excluding locks and rate-limit counters. */
function coverageState(string $directory): string
{
    $files = ['auth.json', 'api-keys.json', 'admin.json', 'authorization.json', 'installation.json', 'database-state.json', 'application-runtime-state.json'];
    return hash('sha256', implode("\0", array_map(fn (string $file): string => (string)@file_get_contents($directory . DIRECTORY_SEPARATOR . $file), $files)));
}

$publicDataActions = ['select', 'union', 'unionAll', 'sql', 'procedure', 'function', 'tableFunction', 'insert', 'update', 'delete', 'upsert',
    'metadata.tables', 'metadata.columns', 'metadata.views', 'metadata.procedures', 'metadata.schema'];
$frontendUserActions = ['auth.frontendUsers.list', 'auth.frontendUsers.create', 'auth.frontendUsers.update', 'auth.frontendUsers.enable',
    'auth.frontendUsers.disable', 'auth.frontendUsers.delete', 'auth.frontendUsers.changePassword', 'auth.frontendUsers.assignRole'];
$adminActions = ['admin.status', 'admin.health', 'admin.system.info', 'admin.console.restart', 'admin.api.start', 'admin.api.stop', 'admin.api.restart',
    'admin.sqlParser.start', 'admin.sqlParser.stop', 'admin.sqlParser.restart', 'admin.database.get', 'admin.database.connect',
    'admin.database.disconnect', 'admin.database.restart', 'admin.database.test', 'admin.database.save', 'admin.settings.get', 'admin.server.save',
    'admin.cors.save', 'admin.authentication.save', 'admin.runtime.save', 'admin.backup.history', 'admin.backup.create', 'admin.backup.download',
    'admin.backup.preview', 'admin.backup.restore', 'admin.backup.schedule', 'admin.backup.schedule.save', 'admin.operational.event',
    'admin.servers.save', 'admin.servers.enable', 'admin.servers.disable', 'admin.servers.delete', 'admin.servers.test', 'admin.servers.list', 'admin.databases.save', 'admin.databases.enable', 'admin.databases.disable', 'admin.databases.default', 'admin.databases.delete', 'admin.databases.test', 'admin.databases.connect', 'admin.databases.disconnect', 'admin.databases.list', 'admin.databases.health'];
$userActions = ['auth.users.list', 'auth.users.create', 'auth.users.update', 'auth.users.enable', 'auth.users.disable', 'auth.users.delete',
    'auth.users.changePassword', 'auth.users.assignAuthorization'];
$apiKeyActions = ['auth.apiKeys.list', 'auth.apiKeys.create', 'auth.apiKeys.enable', 'auth.apiKeys.disable', 'auth.apiKeys.revoke'];
$adminManageActions = [...$userActions, ...$apiKeyActions, 'auth.roles.list', ...$adminActions];
$gatedActions = ['setup.createAdmin', ...$adminManageActions];
$adminCsrfActions = ['auth.login', 'auth.logout', 'auth.users.create', 'auth.users.update', 'auth.users.enable', 'auth.users.disable', 'auth.users.delete',
    'auth.users.changePassword', 'auth.users.assignAuthorization', 'auth.apiKeys.create', 'auth.apiKeys.enable', 'auth.apiKeys.disable',
    'auth.apiKeys.revoke', 'setup.createAdmin', 'admin.database.test', 'admin.database.save', 'admin.database.connect', 'admin.database.disconnect',
    'admin.database.restart', 'admin.server.save', 'admin.console.restart', 'admin.api.start', 'admin.api.stop', 'admin.api.restart',
    'admin.sqlParser.start', 'admin.sqlParser.stop', 'admin.sqlParser.restart', 'admin.cors.save', 'admin.authentication.save', 'admin.runtime.save',
    'admin.backup.create', 'admin.backup.download', 'admin.backup.preview', 'admin.backup.restore', 'admin.backup.schedule.save', 'admin.operational.event',
    'admin.servers.save', 'admin.servers.enable', 'admin.servers.disable', 'admin.servers.delete', 'admin.servers.test', 'admin.databases.save', 'admin.databases.enable', 'admin.databases.disable', 'admin.databases.default', 'admin.databases.delete', 'admin.databases.test', 'admin.databases.connect', 'admin.databases.disconnect'];
$publicCsrfActions = ['auth.login', 'auth.logout', ...array_slice($frontendUserActions, 1), 'insert', 'update', 'delete', 'upsert'];
coverageAssert(count($publicDataActions) === 16 && count($frontendUserActions) === 8 && count($adminActions) === 45
    && count($adminManageActions) === 59 && count($gatedActions) === 60 && count($adminCsrfActions) === 49 && count($publicCsrfActions) === 13,
    'Action inventory no longer matches the plan.');
$adminSource = (string)file_get_contents(dirname(__DIR__) . '/admin/api.php');
foreach ([...$gatedActions, 'setup.status', 'auth.csrf', 'auth.login', 'auth.session', 'auth.logout'] as $action) {
    coverageAssert(str_contains($adminSource, "'{$action}'"), "Admin API no longer routes {$action}; update the coverage inventory.");
}
coverageAssert(preg_match_all("/'(?:admin|auth|setup)\.[A-Za-z.]+'/", substr($adminSource, (int)strpos($adminSource, '$setupActions'), (int)strpos($adminSource, '$allActions') - (int)strpos($adminSource, '$setupActions')), $routed) === 65,
    'Admin API action count changed; update the coverage inventory.');

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-authorization-coverage-' . bin2hex(random_bytes(8));
$logDirectory = $directory . DIRECTORY_SEPARATOR . 'logs';
$operationalLogDirectory = $directory . DIRECTORY_SEPARATOR . 'operational';
$servers = [];
$coverageObserved = [];

try {
    mkdir($directory, 0700, true);
    mkdir($logDirectory, 0700, true);
    mkdir($operationalLogDirectory, 0700, true);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory);
    putenv('GENERIC_LOG_DIR=' . $logDirectory);
    putenv('GENERIC_OPERATIONAL_LOG_DIR=' . $operationalLogDirectory);
    RuntimeConfiguration::ensure();

    $adminConfiguration = new AdminConfigurationRepository();
    $setConfiguration = function (callable $change) use ($adminConfiguration): void {
        $value = $adminConfiguration->load();
        $change($value);
        $adminConfiguration->save($value);
    };
    $setMode = fn (string $mode) => $setConfiguration(function (array &$value) use ($mode): void { $value['authentication']['mode'] = $mode; });
    // Hundreds of requests share one client identity; raise the API limit
    // until the dedicated rate-limit checks at the end (P3-06).
    $setConfiguration(function (array &$value): void { $value['runtime']['rateLimit']['api']['requests'] = 10000; });
    // P1-10: authorization invariants reject privilege that a role must never hold.
    $authorizationRepository = new AuthorizationRepository();
    $unsafeConfigurations = [];
    foreach ([RoleModel::READ_ONLY, RoleModel::DATA_OPERATOR, RoleModel::API_ADMINISTRATOR, RoleModel::APPLICATION_ADMINISTRATOR] as $role) {
        $unsafeConfigurations["admin.manage on {$role}"] = [$role, 'admin.manage'];
    }
    foreach ([RoleModel::READ_ONLY, RoleModel::APPLICATION_ADMINISTRATOR] as $role) $unsafeConfigurations["data.write on {$role}"] = [$role, 'data.write'];
    $unsafeConfigurations['frontend.users.manage on api-administrator'] = [RoleModel::API_ADMINISTRATOR, 'frontend.users.manage'];
    $unsafeConfigurations['unknown permission on read-only'] = [RoleModel::READ_ONLY, 'root.access'];
    foreach ($unsafeConfigurations as $case => [$role, $permission]) {
        $candidate = RuntimeConfiguration::authorizationDefaults();
        $candidate['roles'][$role]['permissions'][] = $permission;
        try { $authorizationRepository->validate($candidate); throw new LogicException("Authorization configuration accepted {$case}."); }
        catch (RuntimeException $exception) {}
    }
    foreach ([[RoleModel::SYSTEM_ADMINISTRATOR, 'admin.manage'], [RoleModel::DATA_OPERATOR, 'data.write'], [RoleModel::APPLICATION_ADMINISTRATOR, 'frontend.users.manage']] as [$role, $permission]) {
        $candidate = RuntimeConfiguration::authorizationDefaults();
        $candidate['roles'][$role]['permissions'] = array_values(array_diff($candidate['roles'][$role]['permissions'], [$permission]));
        try { $authorizationRepository->validate($candidate); throw new LogicException("Authorization configuration accepted {$role} without {$permission}."); }
        catch (RuntimeException $exception) {}
    }
    foreach (['publicRoles', 'legacyApiKeyRoles'] as $field) {
        $candidate = [$field => [RoleModel::SYSTEM_ADMINISTRATOR]] + RuntimeConfiguration::authorizationDefaults();
        try { $authorizationRepository->validate($candidate); throw new LogicException("{$field} accepted the System Administrator role."); }
        catch (RuntimeException $exception) {}
    }
    $authorizationRepository->validate(RuntimeConfiguration::authorizationDefaults());

    (new AuthorizationRepository())->save(RuntimeConfiguration::authorizationDefaults());
    coverageAssert((new DatabaseAvailabilityManager())->available() === false, 'The isolated database is unexpectedly available.');

    $repository = new AuthRepository();
    $users = new UserManagementService($repository);
    $accounts = [
        'Coverage.Admin' => ['coverage-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR],
        'Coverage.Reader' => ['coverage-reader-password', RoleModel::READ_ONLY, false, null],
        'Coverage.Operator' => ['coverage-operator-password', RoleModel::DATA_OPERATOR, false, null],
        'Coverage.AppAdmin' => ['coverage-appadmin-password', null, true, RoleModel::APPLICATION_ADMINISTRATOR],
        'Coverage.Viewer' => ['coverage-viewer-password', null, true, null],
        'Coverage.Target' => ['coverage-target-password', RoleModel::READ_ONLY, true, null],
        'Version.User' => ['version-user-password', RoleModel::READ_ONLY, true, null],
        'Key.Owner' => ['key-owner-password', RoleModel::DATA_OPERATOR, false, null],
        'Lock.User' => ['lock-user-password', RoleModel::READ_ONLY, false, null],
    ];
    foreach ($accounts as $username => [$password, $backendRole, $frontendAccess, $frontendRole]) {
        $users->createUser($username, $password, $backendRole, $frontendAccess, $frontendRole);
    }
    // An installation with an administrator is initialized, as after setup.
    $installation = new InstallationRepository();
    $installation->save(['initialized' => true] + $installation->load());

    $base = [
        'GENERIC_APP_ENV' => 'development',
        'GENERIC_RUNTIME_CONFIG_DIR' => $directory,
        'GENERIC_LOG_DIR' => $logDirectory,
        'GENERIC_OPERATIONAL_LOG_DIR' => $operationalLogDirectory,
        'GENERIC_SQL_API_KEY' => null,
        'GENERIC_API_ALLOWED_ORIGINS' => null,
    ];
    // Public API traffic uses a per-run documentation-range client address
    // (rate-limit and lockout counters are shared on disk); the Admin servers
    // that must be loopback use the real loopback connection.
    $clientRouter = "{$root}/tests/support/ClientAddressRouter.php";
    $client = ['GENERIC_TEST_CLIENT_ADDRESS' => sprintf('2001:db8:%x:%x::%x', random_int(1, 0xffff), random_int(1, 0xffff), random_int(1, 0xffff)),
        'GENERIC_TEST_ROUTER_TARGET' => 'api'] + $base;
    [$servers['api'], $api] = coverageStartServer($root, 'api', $clientRouter, $client);
    [$servers['admin'], $admin] = coverageStartServer($root, 'admin', "{$root}/admin/router.php", ['GENERIC_ADMIN_ENABLED' => '1'] + $base);
    [$servers['adminZero'], $adminZero] = coverageStartServer($root, 'admin', "{$root}/admin/router.php", ['GENERIC_ADMIN_ENABLED' => '0'] + $base);
    [$servers['adminUnset'], $adminUnset] = coverageStartServer($root, 'admin', "{$root}/admin/router.php", ['GENERIC_ADMIN_ENABLED' => null] + $base);
    [$servers['adminRemote'], $adminRemote] = coverageStartServer($root, 'admin', $clientRouter, ['GENERIC_ADMIN_ENABLED' => '1',
        'GENERIC_TEST_CLIENT_ADDRESS' => sprintf('2001:db8:ffff:%x::%x', random_int(1, 0xffff), random_int(1, 0xffff)), 'GENERIC_TEST_ROUTER_TARGET' => 'admin'] + $base);
    $legacyKey = 'legacy-' . bin2hex(random_bytes(16));
    [$servers['apiLegacy'], $apiLegacy] = coverageStartServer($root, 'api', $clientRouter, ['GENERIC_SQL_API_KEY' => $legacyKey] + $client);
    $shortLegacyKey = 'short-' . bin2hex(random_bytes(6));
    [$servers['apiShortLegacy'], $apiShortLegacy] = coverageStartServer($root, 'api', $clientRouter, ['GENERIC_SQL_API_KEY' => $shortLegacyKey] + $client);

    // Sessions on the public API and the enabled Admin API.
    [$readerCookie, $readerToken] = coverageLogin($api, '/', 'Coverage.Reader', 'coverage-reader-password');
    [$operatorCookie, $operatorToken] = coverageLogin($api, '/', 'Coverage.Operator', 'coverage-operator-password');
    [$appAdminCookie, $appAdminToken] = coverageLogin($api, '/', 'Coverage.AppAdmin', 'coverage-appadmin-password');
    [$viewerCookie, $viewerToken] = coverageLogin($api, '/', 'Coverage.Viewer', 'coverage-viewer-password');
    [$adminPublicCookie] = coverageLogin($api, '/', 'Coverage.Admin', 'coverage-admin-password');
    [$adminCookie, $adminToken] = coverageLogin($admin, '/api.php', 'Coverage.Admin', 'coverage-admin-password');
    $nonAdminSessions = [
        'read-only' => coverageLogin($admin, '/api.php', 'Coverage.Reader', 'coverage-reader-password'),
        'data-operator' => coverageLogin($admin, '/api.php', 'Coverage.Operator', 'coverage-operator-password'),
        'application-administrator' => coverageLogin($admin, '/api.php', 'Coverage.AppAdmin', 'coverage-appadmin-password'),
    ];

    // P0-01: the public API never exposes Admin actions.
    foreach ($adminActions as $action) {
        foreach (['anonymous' => null, 'read-only' => $readerCookie, 'System Administrator' => $adminPublicCookie] as $identity => $cookie) {
            coverageExpect(coverageRequest($api, '/', ['action' => $action], $cookie), 404, 'NOT_FOUND', "Public API exposed {$action} to {$identity}.");
        }
    }
    // P0-02: the public API never exposes backend identity management or setup.
    foreach (['setup.createAdmin', ...$userActions, ...$apiKeyActions, 'auth.roles.list'] as $action) {
        foreach (['anonymous' => null, 'System Administrator' => $adminPublicCookie] as $identity => $cookie) {
            coverageExpect(coverageRequest($api, '/', ['action' => $action], $cookie), 404, 'NOT_FOUND', "Public API exposed {$action} to {$identity}.");
        }
    }
    // P0-03 / P3-05: data actions require authentication, before CSRF.
    foreach ($publicDataActions as $action) {
        coverageExpect(coverageRequest($api, '/', ['action' => $action]), 401, 'AUTHENTICATION_REQUIRED', "Anonymous {$action} was not refused.");
    }

    // P0-04 / P1-12: the gate blocks every gated action when Admin is disabled;
    // session actions still work there but never unlock a gated action.
    foreach (['GENERIC_ADMIN_ENABLED=0' => $adminZero, 'GENERIC_ADMIN_ENABLED unset' => $adminUnset] as $condition => $port) {
        [$cookie, $token] = coverageLogin($port, '/api.php', 'Coverage.Admin', 'coverage-admin-password');
        foreach ($gatedActions as $action) {
            coverageExpect(coverageRequest($port, '/api.php', ['action' => $action], $cookie, ['X-CSRF-Token' => $token]), 404, 'NOT_FOUND',
                "{$action} reached the Admin API with {$condition}.");
        }
    }
    // P0-05 / P1-14 / P1-15: a non-loopback client gets 404 for every gated
    // action, even with a valid System Administrator session, CSRF token, and
    // proxy headers that claim loopback.
    [$remoteCookie, $remoteToken] = coverageLogin($adminRemote, '/api.php', 'Coverage.Admin', 'coverage-admin-password');
    foreach ($gatedActions as $action) {
        coverageExpect(coverageRequest($adminRemote, '/api.php', ['action' => $action], $remoteCookie, ['X-CSRF-Token' => $remoteToken]), 404, 'NOT_FOUND',
            "Non-loopback {$action} reached the Admin API.");
    }
    foreach (['X-Forwarded-For' => '127.0.0.1', 'X-Real-IP' => '127.0.0.1', 'Forwarded' => 'for=127.0.0.1', 'Client-IP' => '::1'] as $header => $value) {
        foreach (['admin.status', 'auth.users.list', 'auth.apiKeys.create'] as $action) {
            coverageExpect(coverageRequest($adminRemote, '/api.php', ['action' => $action], $remoteCookie, ['X-CSRF-Token' => $remoteToken, $header => $value]),
                404, 'NOT_FOUND', "{$header} let a non-loopback client reach {$action}.");
        }
    }
    // P0-06: Admin requires a session.
    foreach ($adminManageActions as $action) {
        coverageExpect(coverageRequest($admin, '/api.php', ['action' => $action]), 401, 'AUTHENTICATION_REQUIRED', "Anonymous {$action} reached the Admin API.");
    }
    // P0-07: Admin requires admin.manage.
    foreach ($nonAdminSessions as $role => [$cookie, $token]) {
        foreach ($adminManageActions as $action) {
            coverageExpect(coverageRequest($admin, '/api.php', ['action' => $action], $cookie, ['X-CSRF-Token' => $token]), 403, 'AUTHORIZATION_DENIED',
                "{$role} reached Admin action {$action}.");
        }
    }
    // P0-08: Admin allowlist.
    foreach (['admin.unknown', 'admin.users.export', 'auth.users.export', 'auth.apiKeys.rotate', 'setup.reset', 'select', 'auth.frontendUsers.list', ''] as $action) {
        coverageExpect(coverageRequest($admin, '/api.php', ['action' => $action], $adminCookie, ['X-CSRF-Token' => $adminToken]), 404, 'NOT_FOUND',
            "Admin API dispatched unknown action '{$action}'.");
    }

    // P1-11 (state) / P1-13: Admin CSRF on every protected action, with no state change.
    $foreignToken = $nonAdminSessions['read-only'][1];
    $before = coverageState($directory);
    foreach ($adminCsrfActions as $action) {
        foreach (['missing' => null, 'invalid' => str_repeat('a', 64), 'foreign-session' => $foreignToken] as $case => $token) {
            coverageExpect(coverageRequest($admin, '/api.php', ['action' => $action], $adminCookie, ['X-CSRF-Token' => $token]), 403, 'CSRF_VALIDATION_FAILED',
                "Admin {$action} accepted a {$case} CSRF token.");
        }
    }
    coverageAssert(coverageState($directory) === $before, 'A CSRF-rejected Admin request changed persisted state.');
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.users.list'], $adminCookie), 200, null, 'A CSRF-rejected logout ended the Admin session.');

    // P1-07: no self-authorization change; the last enabled System Administrator is protected.
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.users.assignAuthorization', 'username' => 'Coverage.Admin', 'role' => RoleModel::READ_ONLY],
        $adminCookie, ['X-CSRF-Token' => $adminToken]), 403, 'AUTHORIZATION_DENIED', 'A System Administrator changed its own authorization.');
    foreach (['auth.users.disable', 'auth.users.delete'] as $action) {
        coverageExpect(coverageRequest($admin, '/api.php', ['action' => $action, 'username' => 'Coverage.Admin'], $adminCookie, ['X-CSRF-Token' => $adminToken]),
            403, 'LAST_ENABLED_ADMIN', "{$action} removed the last enabled System Administrator.");
    }
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.users.disable', 'username' => 'Missing.Account'], $adminCookie, ['X-CSRF-Token' => $adminToken]),
        404, 'USER_NOT_FOUND', 'Admin API lost its explicit not-found response.');

    // P1-08: API key role confinement.
    foreach ([[RoleModel::SYSTEM_ADMINISTRATOR], [RoleModel::APPLICATION_ADMINISTRATOR], [RoleModel::READ_ONLY, RoleModel::DATA_OPERATOR], []] as $roles) {
        coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.apiKeys.create', 'name' => 'Confined key', 'ownerUsername' => 'Coverage.Operator', 'roles' => $roles],
            $adminCookie, ['X-CSRF-Token' => $adminToken]), 400, null, 'API key was created with roles ' . json_encode($roles) . '.');
    }
    coverageAssert((new ApiKeyService())->list() === [], 'A refused API key request created a key.');

    // P1-19: API-key mode without any configured key fails closed.
    $setMode('api_key');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], headers: ['X-API-Key' => 'gsk_' . str_repeat('a', 16) . '_' . str_repeat('b', 43)]),
        503, 'AUTHENTICATION_UNAVAILABLE', 'API-key mode without keys did not fail closed.');
    coverageExpect(coverageRequest($apiShortLegacy, '/', ['action' => 'select'], headers: ['X-API-Key' => $shortLegacyKey]),
        503, 'AUTHENTICATION_UNAVAILABLE', 'A legacy key shorter than 32 characters configured API-key mode.');

    $createKey = function (string $name, string $owner, string $role) use ($admin, &$adminCookie, $adminToken): array {
        $response = coverageRequest($admin, '/api.php', ['action' => 'auth.apiKeys.create', 'name' => $name, 'ownerUsername' => $owner, 'roles' => [$role]],
            $adminCookie, ['X-CSRF-Token' => $adminToken]);
        coverageExpect($response, 201, null, "API key {$name} was not created.");
        return $response['json']['data'][0];
    };
    $readKey = $createKey('Read key', 'Coverage.Reader', RoleModel::READ_ONLY);
    $operatorKey = $createKey('Operator key', 'Coverage.Operator', RoleModel::DATA_OPERATOR);
    $administratorKey = $createKey('API administrator key', 'Coverage.Operator', RoleModel::API_ADMINISTRATOR);
    $ownerKey = $createKey('Owner key', 'Key.Owner', RoleModel::DATA_OPERATOR);
    $disabledKey = $createKey('Disabled key', 'Coverage.Operator', RoleModel::READ_ONLY);
    $revokedKey = $createKey('Revoked key', 'Coverage.Operator', RoleModel::READ_ONLY);
    $keyHeader = fn (array $key): array => ['X-API-Key' => $key['apiKey']];

    // P1-16: invalid keys are rejected.
    [$validPrefix] = explode('_', substr($readKey['apiKey'], 4), 2);
    foreach ([
        'malformed' => 'not-a-key',
        'unknown id' => 'gsk_' . str_repeat('0', 16) . '_' . str_repeat('A', 43),
        'wrong secret' => "gsk_{$validPrefix}_" . str_repeat('B', 43),
        'empty' => '',
    ] as $case => $value) {
        coverageExpect(coverageRequest($api, '/', ['action' => 'select'], headers: ['X-API-Key' => $value]), 401, 'AUTHENTICATION_REQUIRED', "A {$case} API key was accepted.");
    }
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], headers: $keyHeader($readKey)), 503, 'DATABASE_UNAVAILABLE', 'A valid API key was not accepted.');
    // P1-17: disabled and revoked keys are rejected; a revoked key cannot be re-enabled.
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.apiKeys.disable', 'id' => $disabledKey['id']], $adminCookie, ['X-CSRF-Token' => $adminToken]), 200, null, 'Key disable failed.');
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.apiKeys.revoke', 'id' => $revokedKey['id']], $adminCookie, ['X-CSRF-Token' => $adminToken]), 200, null, 'Key revoke failed.');
    foreach (['disabled' => $disabledKey, 'revoked' => $revokedKey] as $case => $key) {
        coverageExpect(coverageRequest($api, '/', ['action' => 'select'], headers: $keyHeader($key)), 401, 'AUTHENTICATION_REQUIRED', "A {$case} API key was accepted.");
    }
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.apiKeys.enable', 'id' => $revokedKey['id']], $adminCookie, ['X-CSRF-Token' => $adminToken]),
        409, 'API_KEY_REVOKED', 'A revoked API key was re-enabled.');
    // The revocation timestamp is enforced on its own, even for a stored
    // record left enabled (legacy or tampered storage).
    (new ApiKeyRepository())->update(function (array &$value) use ($revokedKey): void {
        foreach ($value['keys'] as &$key) if ($key['id'] === $revokedKey['id']) $key['enabled'] = true;
        unset($key);
    });
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], headers: $keyHeader($revokedKey)), 401, 'AUTHENTICATION_REQUIRED', 'A revoked but enabled API key was accepted.');
    // P1-18: a disabled owner disables the key.
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], headers: $keyHeader($ownerKey)), 503, 'DATABASE_UNAVAILABLE', 'Owner key was not accepted.');
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.users.disable', 'username' => 'Key.Owner'], $adminCookie, ['X-CSRF-Token' => $adminToken]), 200, null, 'Owner disable failed.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], headers: $keyHeader($ownerKey)), 401, 'AUTHENTICATION_REQUIRED', 'A disabled owner key was accepted.');
    // P1-22: legacy shared key.
    coverageExpect(coverageRequest($apiShortLegacy, '/', ['action' => 'select'], headers: ['X-API-Key' => $shortLegacyKey]), 401, 'AUTHENTICATION_REQUIRED', 'A short legacy key was accepted.');
    coverageExpect(coverageRequest($apiLegacy, '/', ['action' => 'select'], headers: ['X-API-Key' => $legacyKey . 'x']), 401, 'AUTHENTICATION_REQUIRED', 'A wrong legacy key was accepted.');
    coverageExpect(coverageRequest($apiLegacy, '/', ['action' => 'select'], headers: ['X-API-Key' => $legacyKey]), 503, 'DATABASE_UNAVAILABLE', 'The legacy key was not accepted.');
    coverageExpect(coverageRequest($apiLegacy, '/', ['action' => 'insert', 'table' => 'Customers'], headers: ['X-API-Key' => $legacyKey]),
        403, 'AUTHORIZATION_DENIED', 'The legacy key exceeded the read-only role.');
    // P1-23 / P2-02: API keys are confined by their role's permissions only;
    // no table or SQL Resource registration applies. API-key writes are CSRF-exempt.
    coverageExpect(coverageRequest($api, '/', ['action' => 'insert', 'table' => 'Customers'], headers: $keyHeader($readKey)), 403, 'AUTHORIZATION_DENIED', 'A read-only key wrote data.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'procedure', 'source' => ['procedure' => 'dbo.Any']], headers: $keyHeader($readKey)), 403, 'AUTHORIZATION_DENIED', 'A read-only key ran a stored procedure.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'function', 'source' => ['function' => 'dbo.Any']], headers: $keyHeader($readKey)), 503, 'DATABASE_UNAVAILABLE', 'A read-only key could not call a function.');
    foreach (['reports/allowed', 'reports/other'] as $resource) {
        coverageExpect(coverageRequest($api, '/', ['action' => 'sql', 'resource' => $resource], headers: $keyHeader($readKey)), 503, 'DATABASE_UNAVAILABLE', 'A read-only key was limited to a SQL Resource scope.');
    }
    foreach (['Customers', 'Orders', 'inventory.Stock'] as $table) {
        coverageExpect(coverageRequest($api, '/', ['action' => 'insert', 'table' => $table], headers: $keyHeader($operatorKey)), 503, 'DATABASE_UNAVAILABLE', 'An API-key write required CSRF or a table registration.');
    }
    coverageExpect(coverageRequest($api, '/', ['action' => 'procedure', 'source' => ['procedure' => 'dbo.Any']], headers: $keyHeader($operatorKey)), 503, 'DATABASE_UNAVAILABLE', 'An operator key could not run a procedure.');
    // P1-09: no API key reaches Admin.
    foreach ($adminActions as $action) {
        coverageExpect(coverageRequest($api, '/', ['action' => $action], headers: $keyHeader($administratorKey)), 404, 'NOT_FOUND', "API key reached public {$action}.");
    }
    foreach ($adminManageActions as $action) {
        coverageExpect(coverageRequest($admin, '/api.php', ['action' => $action], headers: $keyHeader($administratorKey)), 401, 'AUTHENTICATION_REQUIRED', "API key reached Admin {$action}.");
    }
    // P0-09: identity management never accepts API keys.
    foreach (['api_key', 'session+api_key'] as $mode) {
        $setMode($mode);
        foreach (['read-only' => $readKey, 'data-operator' => $operatorKey, 'api-administrator' => $administratorKey] as $role => $key) {
            foreach ($frontendUserActions as $action) {
                coverageExpect(coverageRequest($api, '/', ['action' => $action], headers: $keyHeader($key)), 401, 'AUTHENTICATION_REQUIRED',
                    "{$role} key reached {$action} in mode {$mode}.");
            }
        }
    }
    // P1-20: session+api_key falls back to the session for an invalid key.
    $invalidKey = ['X-API-Key' => 'gsk_' . str_repeat('1', 16) . '_' . str_repeat('C', 43)];
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], $readerCookie, $invalidKey), 503, 'DATABASE_UNAVAILABLE', 'An invalid key overrode a valid session.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'insert', 'table' => 'Customers'], $readerCookie, $invalidKey + ['X-CSRF-Token' => $readerToken]),
        403, 'AUTHORIZATION_DENIED', 'An invalid key changed the session principal.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], headers: $invalidKey), 401, 'AUTHENTICATION_REQUIRED', 'An invalid key alone was accepted.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], headers: $keyHeader($readKey)), 503, 'DATABASE_UNAVAILABLE', 'A valid key was refused in mixed mode.');
    // P1-21: mode none is least privilege.
    // Anonymous principals hold no permission unless a deployment assigns publicRoles.
    $setMode('none');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select']), 403, 'AUTHORIZATION_DENIED', 'Mode none granted anonymous reads by default.');
    (new AuthorizationRepository())->save(['publicRoles' => [RoleModel::READ_ONLY]] + RuntimeConfiguration::authorizationDefaults());
    coverageExpect(coverageRequest($api, '/', ['action' => 'select']), 503, 'DATABASE_UNAVAILABLE', 'Mode none refused anonymous reads assigned by publicRoles.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'insert', 'table' => 'Customers']), 403, 'AUTHORIZATION_DENIED', 'Mode none allowed anonymous writes.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.list']), 401, 'AUTHENTICATION_REQUIRED', 'Mode none exposed frontend user management.');
    (new AuthorizationRepository())->save(RuntimeConfiguration::authorizationDefaults());
    $setMode('session');

    // P0-10: frontend user management requires frontend.users.manage.
    foreach (['read-only' => [$readerCookie, $readerToken], 'data-operator' => [$operatorCookie, $operatorToken], 'frontend user without a role' => [$viewerCookie, $viewerToken]] as $role => [$cookie, $token]) {
        foreach ($frontendUserActions as $action) {
            coverageExpect(coverageRequest($api, '/', ['action' => $action, 'username' => 'Coverage.Target'], $cookie, ['X-CSRF-Token' => $token]),
                403, 'AUTHORIZATION_DENIED', "{$role} reached {$action}.");
        }
    }
    // P1-04: an Application Administrator cannot create privileged users.
    $create = ['action' => 'auth.frontendUsers.create', 'name' => 'Escalated User', 'username' => 'Escalated.User', 'mobile' => '+15551230000', 'email' => null,
        'password' => 'escalated-user-password', 'passwordConfirmation' => 'escalated-user-password'];
    foreach ([['role' => RoleModel::SYSTEM_ADMINISTRATOR], ['role' => RoleModel::DATA_OPERATOR], ['role' => RoleModel::API_ADMINISTRATOR],
        ['role' => RoleModel::READ_ONLY, 'frontendRole' => RoleModel::APPLICATION_ADMINISTRATOR]] as $role) {
        coverageExpect(coverageRequest($api, '/', $create + $role, $appAdminCookie, ['X-CSRF-Token' => $appAdminToken]), 400, 'INVALID_FRONTEND_USER_REQUEST',
            'Application Administrator created a user with ' . json_encode($role) . '.');
    }
    coverageAssert($repository->findUser('Escalated.User') === null, 'A refused create stored a user.');
    // P1-05: an Application Administrator cannot promote or mutate its own lifecycle.
    foreach ([
        ['action' => 'auth.frontendUsers.assignRole', 'username' => 'Coverage.Viewer', 'frontendAccess' => true, 'frontendRole' => RoleModel::APPLICATION_ADMINISTRATOR],
        ['action' => 'auth.frontendUsers.disable', 'username' => 'Coverage.AppAdmin'],
        ['action' => 'auth.frontendUsers.delete', 'username' => 'Coverage.AppAdmin'],
        ['action' => 'auth.frontendUsers.assignRole', 'username' => 'Coverage.AppAdmin', 'frontendAccess' => true, 'frontendRole' => null],
    ] as $request) {
        coverageExpect(coverageRequest($api, '/', $request, $appAdminCookie, ['X-CSRF-Token' => $appAdminToken]), 403, 'AUTHORIZATION_DENIED',
            "Application Administrator performed {$request['action']} on {$request['username']}.");
    }
    // P1-06: client-supplied identity fields are rejected or have no effect.
    $identityFields = ['backendRole' => RoleModel::SYSTEM_ADMINISTRATOR, 'frontendRole' => RoleModel::APPLICATION_ADMINISTRATOR,
        'roles' => [RoleModel::SYSTEM_ADMINISTRATOR], 'permissions' => ['admin.manage', 'data.write'], 'userId' => str_repeat('f', 32)];
    coverageExpect(coverageRequest($api, '/', ['action' => 'insert', 'table' => 'Customers'] + $identityFields, $readerCookie, ['X-CSRF-Token' => $readerToken]),
        403, 'AUTHORIZATION_DENIED', 'Request identity fields elevated a read-only session.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.list'] + $identityFields, $readerCookie), 403, 'AUTHORIZATION_DENIED',
        'Request identity fields granted frontend user management.');
    foreach ($identityFields as $field => $value) {
        coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.changePassword', 'username' => 'Coverage.Target', 'newPassword' => 'changed-target-password',
            'passwordConfirmation' => 'changed-target-password', $field => $value], $appAdminCookie, ['X-CSRF-Token' => $appAdminToken]),
            400, 'INVALID_FRONTEND_USER_REQUEST', "Frontend user management accepted identity field {$field}.");
    }
    coverageAssert(password_verify('coverage-target-password', $repository->findUser('Coverage.Target')['passwordHash']), 'A rejected request changed a password.');
    // F-01: a frontend-created Read Only user holds frontend access only;
    // disabling or revoking it ends its live session and blocks login, and the
    // frontend can still restore it without a backend role.
    $appAdminHeaders = ['X-CSRF-Token' => $appAdminToken];
    $lifecycleLogin = ['action' => 'auth.login', 'username' => 'Lifecycle.Reader', 'password' => 'lifecycle-reader-password'];
    $lifecycleLoginRefused = function () use ($api, $lifecycleLogin): void {
        $cookie = null;
        $token = coverageRequest($api, '/', ['action' => 'auth.csrf'], $cookie)['json']['data'][0]['csrfToken'] ?? null;
        coverageExpect(coverageRequest($api, '/', $lifecycleLogin, $cookie, ['X-CSRF-Token' => $token]), 401, null, 'A disabled frontend reader logged in.');
    };
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.create', 'name' => 'Lifecycle Reader', 'username' => 'Lifecycle.Reader',
        'mobile' => '+15551239999', 'email' => null, 'password' => 'lifecycle-reader-password', 'passwordConfirmation' => 'lifecycle-reader-password',
        'role' => RoleModel::READ_ONLY], $appAdminCookie, $appAdminHeaders), 201, null, 'Application Administrator could not create a Read Only user.');
    $lifecycle = $repository->findUser('Lifecycle.Reader');
    coverageAssert($lifecycle['backendRole'] === null && $lifecycle['frontendAccess'] === true && $lifecycle['frontendRole'] === null,
        'A frontend-created Read Only user received a backend role.');
    [$lifecycleCookie, $lifecycleToken] = coverageLogin($api, '/', 'Lifecycle.Reader', 'lifecycle-reader-password');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], $lifecycleCookie), 503, 'DATABASE_UNAVAILABLE', 'A frontend reader could not read.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'procedure', 'source' => ['procedure' => 'AnyProcedure']], $lifecycleCookie, ['X-CSRF-Token' => $lifecycleToken]),
        403, 'AUTHORIZATION_DENIED', 'A frontend reader reached a routine.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.disable', 'username' => 'Lifecycle.Reader'], $appAdminCookie, $appAdminHeaders),
        200, null, 'Application Administrator could not disable a frontend reader.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], $lifecycleCookie), 401, 'AUTHENTICATION_REQUIRED', 'A disabled reader session kept reading.');
    $lifecycleLoginRefused();
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.enable', 'username' => 'Lifecycle.Reader'], $appAdminCookie, $appAdminHeaders),
        200, null, 'Application Administrator could not re-enable a frontend reader.');
    [$lifecycleCookie] = coverageLogin($api, '/', 'Lifecycle.Reader', 'lifecycle-reader-password');
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.assignRole', 'username' => 'Lifecycle.Reader', 'frontendAccess' => false, 'frontendRole' => null],
        $appAdminCookie, $appAdminHeaders), 200, null, 'Application Administrator could not revoke a frontend reader.');
    $lifecycle = $repository->findUser('Lifecycle.Reader');
    coverageAssert($lifecycle['enabled'] === false && $lifecycle['frontendAccess'] === false && $lifecycle['backendRole'] === null,
        'Revoking a frontend reader did not disable it without a role.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], $lifecycleCookie), 401, 'AUTHENTICATION_REQUIRED', 'A revoked reader session kept reading.');
    $lifecycleLoginRefused();
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.enable', 'username' => 'Lifecycle.Reader'], $appAdminCookie, $appAdminHeaders),
        409, 'USER_ACCESS_REQUIRED', 'A revoked reader was re-enabled without access.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.assignRole', 'username' => 'Lifecycle.Reader', 'frontendAccess' => true, 'frontendRole' => null],
        $appAdminCookie, $appAdminHeaders), 200, null, 'Application Administrator could not restore a revoked reader.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.enable', 'username' => 'Lifecycle.Reader'], $appAdminCookie, $appAdminHeaders),
        200, null, 'Application Administrator could not re-enable a restored reader.');
    coverageAssert($repository->findUser('Lifecycle.Reader')['backendRole'] === null, 'Restoring a reader granted a backend role.');
    [$lifecycleCookie] = coverageLogin($api, '/', 'Lifecycle.Reader', 'lifecycle-reader-password');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], $lifecycleCookie), 503, 'DATABASE_UNAVAILABLE', 'A restored reader could not read.');
    // P2-17: username case variants cannot reach a System Administrator.
    foreach (['coverage.admin', 'COVERAGE.ADMIN', 'Coverage.ADMIN'] as $variant) {
        foreach ([['action' => 'auth.frontendUsers.changePassword', 'username' => $variant, 'newPassword' => 'case-variant-password', 'passwordConfirmation' => 'case-variant-password'],
            ['action' => 'auth.frontendUsers.delete', 'username' => $variant], ['action' => 'auth.frontendUsers.disable', 'username' => $variant]] as $request) {
            coverageExpect(coverageRequest($api, '/', $request, $appAdminCookie, ['X-CSRF-Token' => $appAdminToken]), 403, 'AUTHORIZATION_DENIED',
                "Case variant {$variant} reached a System Administrator through {$request['action']}.");
        }
    }
    coverageAssert(password_verify('coverage-admin-password', $repository->findUser('Coverage.Admin')['passwordHash'])
        && $repository->findUser('Coverage.Admin')['enabled'] === true, 'A case-variant request changed the System Administrator.');

    // P2-01: public CSRF on all 13 protected actions, with no state change.
    $anonymousCookie = null;
    coverageRequest($api, '/', ['action' => 'auth.csrf'], $anonymousCookie);
    $before = coverageState($directory);
    foreach ($publicCsrfActions as $action) {
        [$cookie, $validToken] = match (true) {
            $action === 'auth.login' => [$anonymousCookie, null],
            str_starts_with($action, 'auth.frontendUsers.') => [$appAdminCookie, $appAdminToken],
            default => [$operatorCookie, $operatorToken],
        };
        $request = $action === 'auth.login' ? ['action' => $action, 'username' => 'Coverage.Target', 'password' => 'coverage-target-password']
            : ['action' => $action, 'username' => 'Coverage.Target', 'table' => 'Customers'];
        foreach (['missing' => null, 'invalid' => str_repeat('b', 64), 'foreign-session' => $readerToken] as $case => $token) {
            coverageExpect(coverageRequest($api, '/', $request, $cookie, ['X-CSRF-Token' => $token]), 403, 'CSRF_VALIDATION_FAILED', "Public {$action} accepted a {$case} CSRF token.");
        }
    }
    coverageAssert(coverageState($directory) === $before, 'A CSRF-rejected public request changed persisted state.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.list'], $appAdminCookie), 200, null, 'A CSRF-rejected logout ended the session.');

    // P2-04: login CSRF and rotation.
    $loginCookie = null;
    $preLoginToken = coverageRequest($api, '/', ['action' => 'auth.csrf'], $loginCookie)['json']['data'][0]['csrfToken'];
    $login = ['action' => 'auth.login', 'username' => 'Coverage.AppAdmin', 'password' => 'coverage-appadmin-password'];
    coverageExpect(coverageRequest($api, '/', $login, $loginCookie), 403, 'CSRF_VALIDATION_FAILED', 'Login accepted a missing CSRF token.');
    $loggedIn = coverageRequest($api, '/', $login, $loginCookie, ['X-CSRF-Token' => $preLoginToken]);
    coverageExpect($loggedIn, 200, null, 'Login with a valid CSRF token failed.');
    $rotatedToken = $loggedIn['headers']['x-csrf-token'] ?? null;
    coverageAssert(is_string($rotatedToken) && preg_match('/^[a-f0-9]{64}$/', $rotatedToken) === 1 && $rotatedToken !== $preLoginToken, 'Login did not rotate the CSRF token.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.disable', 'username' => 'Coverage.Target'], $loginCookie, ['X-CSRF-Token' => $preLoginToken]),
        403, 'CSRF_VALIDATION_FAILED', 'The pre-login CSRF token survived login.');
    coverageAssert($repository->findUser('Coverage.Target')['enabled'] === true, 'A pre-login token changed state.');

    // P2-07: every authVersion trigger revokes existing sessions.
    $versionSession = fn (): string => coverageLogin($api, '/', $GLOBALS['versionName'], 'version-user-password')[0];
    $GLOBALS['versionName'] = 'Version.User';
    $assertRevoked = function (string $cookie, string $trigger) use ($api): void {
        coverageExpect(coverageRequest($api, '/', ['action' => 'select'], $cookie), 401, 'AUTHENTICATION_REQUIRED', "A {$trigger} left a session active.");
    };
    $session = $versionSession();
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], $session), 503, 'DATABASE_UNAVAILABLE', 'Version user session is not active.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.frontendUsers.assignRole', 'username' => 'Version.User', 'frontendAccess' => false, 'frontendRole' => null],
        $appAdminCookie, ['X-CSRF-Token' => $appAdminToken]), 200, null, 'Frontend access change failed.');
    $assertRevoked($session, 'frontend access change');
    $session = $versionSession();
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.users.update', 'username' => 'Version.User', 'name' => 'Version Renamed',
        'newUsername' => 'Version.Renamed', 'mobile' => '+15550002000', 'email' => null], $adminCookie, ['X-CSRF-Token' => $adminToken]), 200, null, 'Rename failed.');
    $assertRevoked($session, 'profile rename');
    $GLOBALS['versionName'] = 'Version.Renamed';
    $session = $versionSession();
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.users.disable', 'username' => 'Version.Renamed'], $adminCookie, ['X-CSRF-Token' => $adminToken]), 200, null, 'Disable failed.');
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.users.enable', 'username' => 'Version.Renamed'], $adminCookie, ['X-CSRF-Token' => $adminToken]), 200, null, 'Enable failed.');
    $assertRevoked($session, 'disable and re-enable');
    $session = $versionSession();
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.users.assignAuthorization', 'username' => 'Version.Renamed', 'role' => RoleModel::DATA_OPERATOR],
        $adminCookie, ['X-CSRF-Token' => $adminToken]), 200, null, 'Role change failed.');
    $assertRevoked($session, 'role change');
    // P2-08: a logged-out cookie cannot be replayed.
    [$logoutCookie, $logoutToken] = coverageLogin($api, '/', 'Coverage.Target', 'coverage-target-password');
    $replay = $logoutCookie;
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.logout'], $logoutCookie, ['X-CSRF-Token' => $logoutToken]), 200, null, 'Logout failed.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], $replay), 401, 'AUTHENTICATION_REQUIRED', 'A logged-out session cookie was replayed.');

    // P2-10: OPTIONS and non-POST methods never reach dispatch or change state.
    $before = coverageState($directory);
    foreach (['OPTIONS', 'GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
        foreach ([[$api, '/', $readerCookie, $readerToken], [$admin, '/api.php', $adminCookie, $adminToken]] as [$port, $path, $cookie, $token]) {
            $probeCookie = $cookie;
            $response = coverageRequest($port, $path, ['action' => 'auth.logout'], $probeCookie, ['X-CSRF-Token' => $token], $method);
            $expected = $method === 'OPTIONS' && $port === $api ? 204 : 405;
            coverageAssert($response['status'] === $expected && $response['setCookie'] === null, "{$method} {$path} reached dispatch or touched the session.");
            if ($expected === 405) coverageExpect($response, 405, 'METHOD_NOT_ALLOWED', "{$method} {$path} was not refused.");
        }
    }
    coverageAssert(coverageState($directory) === $before, 'A non-POST request changed persisted state.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], $readerCookie), 503, 'DATABASE_UNAVAILABLE', 'A non-POST logout ended the session.');
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'auth.users.list'], $adminCookie), 200, null, 'A non-POST logout ended the Admin session.');

    // P2-11 / P2-12 / P3-03: sessions follow the same permission-only rules as
    // API keys; database state stays hidden from unauthenticated and
    // unauthorized callers.
    foreach (['reports/allowed', 'reports/other'] as $resource) {
        coverageExpect(coverageRequest($api, '/', ['action' => 'sql', 'resource' => $resource], $readerCookie), 503, 'DATABASE_UNAVAILABLE', 'A read-only session was limited to a SQL Resource scope.');
    }
    coverageExpect(coverageRequest($api, '/', ['action' => 'sql', 'resource' => 'reports/other'], $viewerCookie), 503, 'DATABASE_UNAVAILABLE',
        'Frontend access no longer reads SQL Resources.');
    foreach (['insert', 'update', 'delete', 'upsert'] as $action) {
        foreach (['Customers', 'Orders'] as $table) {
            coverageExpect(coverageRequest($api, '/', ['action' => $action, 'table' => $table], $operatorCookie, ['X-CSRF-Token' => $operatorToken]),
                503, 'DATABASE_UNAVAILABLE', "Operator {$action} on {$table} required a table registration.");
        }
        coverageExpect(coverageRequest($api, '/', ['action' => $action, 'table' => 'Customers'], $readerCookie, ['X-CSRF-Token' => $readerToken]),
            403, 'AUTHORIZATION_DENIED', "Unauthorized {$action} learned the database state.");
        coverageExpect(coverageRequest($api, '/', ['action' => $action, 'table' => 'Customers']), 401, 'AUTHENTICATION_REQUIRED', "Anonymous {$action} learned the database state.");
    }
    // Routines: functions need routine.execute; procedures also need data.write
    // and, for sessions, CSRF.
    coverageExpect(coverageRequest($api, '/', ['action' => 'function', 'source' => ['function' => 'dbo.Any']], $readerCookie), 503, 'DATABASE_UNAVAILABLE', 'A read-only session could not call a function.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'procedure', 'source' => ['procedure' => 'dbo.Any']], $readerCookie, ['X-CSRF-Token' => $readerToken]),
        403, 'AUTHORIZATION_DENIED', 'A read-only session ran a stored procedure.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'procedure', 'source' => ['procedure' => 'dbo.Any']], $operatorCookie),
        403, 'CSRF_VALIDATION_FAILED', 'A session procedure call ran without CSRF.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'procedure', 'source' => ['procedure' => 'dbo.Any']], $operatorCookie, ['X-CSRF-Token' => $operatorToken]),
        503, 'DATABASE_UNAVAILABLE', 'An operator session could not run a procedure.');

    // P2-15: API key identifiers.
    foreach (['auth.apiKeys.enable', 'auth.apiKeys.disable', 'auth.apiKeys.revoke'] as $action) {
        coverageExpect(coverageRequest($admin, '/api.php', ['action' => $action, 'id' => str_repeat('e', 16)], $adminCookie, ['X-CSRF-Token' => $adminToken]),
            404, 'API_KEY_NOT_FOUND', "{$action} did not report an unknown key.");
        foreach (['../../etc', 'XYZ', str_repeat('e', 17), ''] as $malformed) {
            coverageExpect(coverageRequest($admin, '/api.php', ['action' => $action, 'id' => $malformed], $adminCookie, ['X-CSRF-Token' => $adminToken]),
                400, 'INVALID_API_KEY_REQUEST', "{$action} accepted malformed id '{$malformed}'.");
        }
    }
    // P2-16: backup identifiers never reach the filesystem.
    $outside = $directory . DIRECTORY_SEPARATOR . 'outside.zip';
    foreach ([
        ['action' => 'admin.backup.download', 'recoveryPointId' => '../../outside'],
        ['action' => 'admin.backup.download', 'recoveryPointId' => '20260101T000000Z-zzzzzzzzzzzz'],
        ['action' => 'admin.backup.preview', 'filename' => '../outside.zip', 'archive' => base64_encode('PK')],
        ['action' => 'admin.backup.preview', 'filename' => 'backup.php', 'archive' => base64_encode('PK')],
        ['action' => 'admin.backup.restore', 'uploadToken' => '../../outside', 'confirmed' => true],
        ['action' => 'admin.backup.restore', 'uploadToken' => str_repeat('a', 48), 'confirmed' => false],
    ] as $request) {
        coverageExpect(coverageRequest($admin, '/api.php', $request, $adminCookie, ['X-CSRF-Token' => $adminToken]), 400, 'INVALID_ADMIN_REQUEST',
            "Backup identifier was accepted: " . json_encode($request));
    }
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'admin.backup.download', 'recoveryPointId' => '20260101T000000Z-0123456789ab'], $adminCookie,
        ['X-CSRF-Token' => $adminToken]), 404, null, 'An unknown recovery point did not return 404.');
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'admin.backup.restore', 'uploadToken' => str_repeat('a', 48), 'confirmed' => true], $adminCookie,
        ['X-CSRF-Token' => $adminToken]), 404, 'RESTORE_UPLOAD_UNAVAILABLE', 'An unknown restore upload did not return 404.');
    coverageAssert(!file_exists($outside), 'A backup identifier wrote outside the backup directory.');

    // P2-19 / P2-20 / P2-21: method, content type, and JSON body on both APIs.
    foreach ([[$api, '/'], [$admin, '/api.php']] as [$port, $path]) {
        foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
            coverageExpect(coverageRequest($port, $path, ['action' => 'setup.status'], headers: [], method: $method), 405, 'METHOD_NOT_ALLOWED', "{$method} {$path} was not refused.");
        }
        foreach (['', 'text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', 'application/jsonp'] as $type) {
            coverageExpect(coverageRequest($port, $path, ['action' => 'setup.status'], headers: ['Content-Type' => $type]), 415, 'UNSUPPORTED_MEDIA_TYPE',
                "Content type '{$type}' on {$path} was not refused.");
        }
        coverageExpect(coverageRequest($port, $path, ['action' => 'setup.status'], headers: ['Content-Type' => 'application/json; charset=utf-8']), 200, null,
            "JSON with a charset on {$path} was refused.");
        foreach (['', '{', '{"action":', "\x00"] as $malformed) {
            coverageExpect(coverageRequest($port, $path, $malformed), 400, 'INVALID_JSON', "Malformed JSON on {$path} was not refused.");
        }
        foreach (['7', '"setup.status"', 'null', 'true'] as $scalar) {
            coverageExpect(coverageRequest($port, $path, $scalar), 400, 'INVALID_REQUEST', "Scalar body {$scalar} on {$path} was not refused.");
        }
    }
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'setup.status'], headers: [], method: 'OPTIONS'), 405, 'METHOD_NOT_ALLOWED', 'Admin OPTIONS was not refused.');
    // P2-22: unknown properties are rejected by every validator.
    foreach ([
        [$api, '/', ['action' => 'auth.login', 'username' => 'Coverage.Target', 'password' => 'coverage-target-password', 'remember' => true], $anonymousCookie,
            coverageRequest($api, '/', ['action' => 'auth.csrf'], $anonymousCookie)['json']['data'][0]['csrfToken'], 'INVALID_AUTH_REQUEST'],
        [$api, '/', ['action' => 'auth.session', 'extra' => 1], null, null, 'INVALID_AUTH_REQUEST'],
        [$api, '/', ['action' => 'setup.status', 'extra' => 1], null, null, null],
        [$api, '/', ['action' => 'auth.frontendUsers.list', 'extra' => 1], $appAdminCookie, null, 'INVALID_FRONTEND_USER_REQUEST'],
        [$admin, '/api.php', ['action' => 'auth.users.list', 'extra' => 1], $adminCookie, $adminToken, null],
        [$admin, '/api.php', ['action' => 'auth.apiKeys.list', 'extra' => 1], $adminCookie, $adminToken, 'INVALID_API_KEY_REQUEST'],
        [$admin, '/api.php', ['action' => 'auth.roles.list', 'extra' => 1], $adminCookie, $adminToken, 'INVALID_ROLE_REQUEST'],
        [$admin, '/api.php', ['action' => 'admin.status', 'extra' => 1], $adminCookie, $adminToken, 'INVALID_ADMIN_REQUEST'],
    ] as [$port, $path, $request, $cookie, $token, $code]) {
        $probeCookie = $cookie;
        coverageExpect(coverageRequest($port, $path, $request, $probeCookie, ['X-CSRF-Token' => $token]), 400, $code, "{$request['action']} accepted an unknown property.");
    }
    // P2-23: action type confusion is refused without dispatch or PHP warnings.
    foreach (['[]' => ['x'], 'number' => 7, 'null' => null, 'boolean' => true, 'object' => ['action' => 'admin.status']] as $case => $value) {
        coverageExpect(coverageRequest($api, '/', ['action' => $value]), 400, 'INVALID_REQUEST', "Public API accepted a {$case} action.");
        coverageExpect(coverageRequest($api, '/', ['action' => $value], $adminPublicCookie), 400, 'INVALID_REQUEST', "Public API accepted a {$case} action from a session.");
        coverageExpect(coverageRequest($admin, '/api.php', ['action' => $value], $adminCookie, ['X-CSRF-Token' => $adminToken]), 404, 'NOT_FOUND', "Admin API accepted a {$case} action.");
    }
    foreach ([' admin.status', 'admin.status ', "admin.status\n", 'ADMIN.STATUS', ' select'] as $padded) {
        coverageExpect(coverageRequest($admin, '/api.php', ['action' => $padded], $adminCookie, ['X-CSRF-Token' => $adminToken]), 404, 'NOT_FOUND', "Admin API normalized action '{$padded}'.");
        coverageAssert(in_array(coverageRequest($api, '/', ['action' => $padded])['status'], [401, 404], true), "Public API dispatched action '{$padded}' anonymously.");
    }
    // An authorized session's invalid action is rejected by validation whatever the database state
    // (here: closed, and no database is registered in CI), while a real data action still meets the
    // availability gate. Nothing here depends on the deployment's own database registry.
    coverageAssert((new DatabaseAvailabilityManager())->available() === false, 'The isolated database is unexpectedly available.');
    foreach ([' select', 'select ', 'SELECT', ' admin.status'] as $padded) {
        coverageExpect(coverageRequest($api, '/', ['action' => $padded], $adminPublicCookie), 400, 'INVALID_REQUEST', "Public API normalized action '{$padded}'.");
    }
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], $adminPublicCookie), 503, 'DATABASE_UNAVAILABLE', 'A data action bypassed the availability gate.');
    // P2-24: CORS.
    $evil = ['Origin' => 'https://evil.example'];
    coverageExpect(coverageRequest($api, '/', ['action' => 'setup.status'], headers: $evil), 403, 'CORS_ORIGIN_DENIED', 'A disallowed origin reached POST.');
    coverageExpect(coverageRequest($api, '/', null, headers: $evil, method: 'OPTIONS'), 403, 'CORS_ORIGIN_DENIED', 'A disallowed origin passed preflight.');
    $allowed = coverageRequest($api, '/', ['action' => 'setup.status'], headers: ['Origin' => 'http://localhost:5173']);
    coverageAssert($allowed['status'] === 200 && ($allowed['headers']['access-control-allow-origin'] ?? null) === 'http://localhost:5173', 'An allowed origin was refused.');
    coverageAssert(!isset(coverageRequest($api, '/', ['action' => 'setup.status'])['headers']['access-control-allow-origin']), 'CORS headers were sent without an Origin.');

    // P3-01 (rows): invalid credentials and one-time setup.
    coverageExpect(coverageRequest($api, '/', ['action' => 'auth.login', 'username' => 'Coverage.Target', 'password' => 'wrong-target-password'], $anonymousCookie,
        ['X-CSRF-Token' => coverageRequest($api, '/', ['action' => 'auth.csrf'], $anonymousCookie)['json']['data'][0]['csrfToken']]), 401, 'INVALID_CREDENTIALS', 'Invalid credentials were accepted.');
    coverageExpect(coverageRequest($admin, '/api.php', ['action' => 'setup.status'], $adminCookie), 200, null, 'Setup status failed.');
    $setup = ['action' => 'setup.createAdmin', 'name' => 'Second Setup', 'username' => 'Second.Setup', 'mobile' => '+15550003000', 'email' => null,
        'password' => 'second-setup-password', 'passwordConfirmation' => 'second-setup-password'];
    coverageExpect(coverageRequest($admin, '/api.php', $setup, $adminCookie, ['X-CSRF-Token' => $adminToken]), 409, 'INSTALLATION_ALREADY_INITIALIZED', 'Setup ran on an initialized installation.');
    coverageAssert($repository->findUser('Second.Setup') === null, 'Refused setup created an administrator.');

    // P3-06: login lockout cannot be bypassed by changing username case; the
    // API rate limit applies to sessions and anonymous callers.
    $lockCookie = null;
    $lockToken = coverageRequest($api, '/', ['action' => 'auth.csrf'], $lockCookie)['json']['data'][0]['csrfToken'];
    $variants = ['Lock.User', 'LOCK.USER', 'lock.user', 'Lock.user', 'lOCK.uSER'];
    foreach ($variants as $index => $variant) {
        $failure = coverageRequest($api, '/', ['action' => 'auth.login', 'username' => $variant, 'password' => 'wrong-lock-password'], $lockCookie, ['X-CSRF-Token' => $lockToken]);
        coverageExpect($failure, $index < 4 ? 401 : 429, $index < 4 ? 'INVALID_CREDENTIALS' : 'LOGIN_RATE_LIMITED', "Login attempt with {$variant} was not counted.");
    }
    foreach (['LOCK.USER', 'lock.user', 'Lock.User'] as $variant) {
        coverageExpect(coverageRequest($api, '/', ['action' => 'auth.login', 'username' => $variant, 'password' => 'lock-user-password'], $lockCookie, ['X-CSRF-Token' => $lockToken]),
            429, 'LOGIN_RATE_LIMITED', "Case variant {$variant} bypassed the login lockout.");
    }
    $setConfiguration(function (array &$value): void { $value['runtime']['rateLimit']['api'] = ['enabled' => true, 'requests' => 2, 'windowSeconds' => 60]; });
    $limited = [];
    for ($index = 0; $index < 3; $index++) $limited[] = coverageRequest($api, '/', ['action' => 'select'], $operatorCookie)['status'];
    coverageAssert(in_array(429, $limited, true), 'The API rate limit did not apply to a session.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select'], $operatorCookie), 429, 'RATE_LIMIT_EXCEEDED', 'The API rate limit was bypassed.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'select']), 429, 'RATE_LIMIT_EXCEEDED', 'Anonymous callers bypassed the rate limit.');
    $setConfiguration(function (array &$value): void { $value['runtime']['rateLimit']['api'] = ['enabled' => true, 'requests' => 10000, 'windowSeconds' => 60]; });

    // P2-25: body limits on both APIs.
    $setConfiguration(function (array &$value): void { $value['runtime']['request']['maxBodyBytes'] = 1024; });
    coverageExpect(coverageRequest($api, '/', json_encode(['action' => 'setup.status', 'padding' => str_repeat('x', 2048)])), 413, 'REQUEST_TOO_LARGE', 'Public body limit was not enforced.');
    coverageExpect(coverageRequest($api, '/', ['action' => 'setup.status']), 200, null, 'A small public body was refused.');
    coverageExpect(coverageRequest($admin, '/api.php', json_encode(['action' => 'admin.status', 'padding' => str_repeat('x', 30 * 1024 * 1024)]), $adminCookie,
        ['X-CSRF-Token' => $adminToken]), 413, 'REQUEST_TOO_LARGE', 'Admin body limit was not enforced.');

    // P2-23 (warnings): no request in this suite produced a PHP warning.
    $warnings = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($operationalLogDirectory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        foreach (file((string)$file) ?: [] as $line) if (preg_match('/PHP (?:Warning|Notice|Deprecated)|Array to string/i', $line) === 1) $warnings[] = trim($line);
    }
    coverageAssert($warnings === [], 'Requests produced PHP warnings: ' . implode(' | ', array_slice($warnings, 0, 3)));

    // P3-01: every security status in the plan's status table was observed
    // with its exact code. SERVICE_UNAVAILABLE needs production hosting and is
    // asserted by ProductionApplicationAvailabilityTest.
    foreach (['403 CORS_ORIGIN_DENIED', '405 METHOD_NOT_ALLOWED', '415 UNSUPPORTED_MEDIA_TYPE', '413 REQUEST_TOO_LARGE', '400 INVALID_JSON',
        '400 INVALID_REQUEST', '404 NOT_FOUND', '503 AUTHENTICATION_UNAVAILABLE', '401 AUTHENTICATION_REQUIRED', '429 RATE_LIMIT_EXCEEDED',
        '401 INVALID_CREDENTIALS', '429 LOGIN_RATE_LIMITED', '403 AUTHORIZATION_DENIED', '403 CSRF_VALIDATION_FAILED',
        '403 LAST_ENABLED_ADMIN', '404 USER_NOT_FOUND', '409 INSTALLATION_ALREADY_INITIALIZED', '503 DATABASE_UNAVAILABLE'] as $row) {
        coverageAssert(isset($coverageObserved[$row]), "Status table row {$row} was not exercised.");
    }

    echo "Authorization and API coverage tests passed.\n";
} finally {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
    putenv('GENERIC_RUNTIME_CONFIG_DIR');
    putenv('GENERIC_LOG_DIR');
    putenv('GENERIC_OPERATIONAL_LOG_DIR');
    coverageRemove($directory);
}
