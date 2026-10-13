<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Repositories/AuthRepository.php';
require_once __DIR__ . '/../app/Repositories/AuthorizationRepository.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Services/ApiKeyService.php';
require_once __DIR__ . '/../app/Middleware/CsrfProtectionMiddleware.php';

/*
 * Regression coverage for the v2.1.3 findings AAPI-03 – AAPI-08
 * (findings register in docs/security/Security-Verification.md; current
 * controls in docs/security/Security-Model.md).
 */

function hardeningAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function hardeningFailure(callable $operation, string $code, int $status, string $message): ApiRequestException
{
    try { $operation(); }
    catch (ApiRequestException $exception) {
        hardeningAssert($exception->getErrorCode() === $code && $exception->getStatusCode() === $status,
            "{$message} Got {$exception->getStatusCode()} {$exception->getErrorCode()}.");
        return $exception;
    }
    throw new RuntimeException($message);
}

function hardeningRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') hardeningRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function hardeningStartServer(string $root, string $boundary, array $environment): array
{
    $port = null;
    foreach (range(19100 + random_int(0, 40) * 10, 19899) as $candidate) {
        $reservation = @stream_socket_server("tcp://127.0.0.1:{$candidate}");
        if (!is_resource($reservation)) continue;
        fclose($reservation);
        $port = $candidate;
        break;
    }
    hardeningAssert($port !== null, 'Unable to reserve a test port.');
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) $command[] = '-n';
    array_push($command, '-S', "127.0.0.1:{$port}", '-t', "{$root}/{$boundary}", "{$root}/{$boundary}/router.php");
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $server = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
        $pipes,
        $root,
        array_merge(is_array(getenv()) ? getenv() : [], $environment)
    );
    hardeningAssert(is_resource($server), "Unable to start the {$boundary} test server.");
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

/** POST a raw JSON body to a test server, keeping the session cookie in $cookie. */
function hardeningPost(int $port, string $path, array|string $json, ?string &$cookie = null, ?string $csrf = null): array
{
    $headers = "Content-Type: application/json\r\n";
    if ($cookie !== null) $headers .= "Cookie: {$cookie}\r\n";
    if ($csrf !== null) $headers .= "X-CSRF-Token: {$csrf}\r\n";
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $headers,
        'content' => is_string($json) ? $json : json_encode($json, JSON_THROW_ON_ERROR),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10,
    ]]);
    $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    hardeningAssert($body !== false && $responseHeaders !== [], "Test server did not answer POST {$path}.");
    preg_match('/^HTTP\/\S+\s+(\d{3})/', $responseHeaders[0], $status);
    foreach ($responseHeaders as $header) {
        if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match) === 1) $cookie = $match[1];
    }
    $decoded = json_decode((string)$body, true);
    return ['status' => (int)$status[1], 'json' => is_array($decoded) ? $decoded : null];
}

/** Log in over HTTP and return [cookie, current CSRF token]. */
function hardeningLogin(int $port, string $username, string $password): array
{
    $cookie = null;
    $token = hardeningPost($port, '/', ['action' => 'auth.csrf'], $cookie)['json']['data'][0]['csrfToken'] ?? null;
    $login = hardeningPost($port, '/', ['action' => 'auth.login', 'username' => $username, 'password' => $password], $cookie, $token);
    hardeningAssert($login['status'] === 200, "Login failed for {$username} ({$login['status']}).");
    $token = hardeningPost($port, '/', ['action' => 'auth.csrf'], $cookie)['json']['data'][0]['csrfToken'] ?? null;
    hardeningAssert(is_string($token), "No CSRF token after login for {$username}.");
    return [$cookie, $token];
}

function hardeningExpect(array $response, int $status, ?string $code, string $message): void
{
    hardeningAssert(
        $response['status'] === $status && ($code === null || ($response['json']['error']['code'] ?? null) === $code),
        $message . " Got {$response['status']} " . (string)($response['json']['error']['code'] ?? '')
    );
}

function hardeningPrincipal(AuthRepository $repository, string $username): Principal
{
    $user = $repository->findUser($username);
    return new Principal($user['id'], $user['username'], 'session', $user['backendRole'], $user['frontendAccess'], $user['frontendRole'], $user['enabled']);
}

$root = dirname(__DIR__);
$configurationDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-api-hardening-' . bin2hex(random_bytes(8));
$logDirectory = $configurationDirectory . DIRECTORY_SEPARATOR . 'logs';
$servers = [];

try {
    mkdir($configurationDirectory, 0700, true);
    mkdir($logDirectory, 0700, true);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $configurationDirectory);
    putenv('GENERIC_LOG_DIR=' . $logDirectory);
    RuntimeConfiguration::ensure();

    // AAPI-03: stored procedures can change data, so every procedure call needs
    // CSRF for session callers, like insert/update/delete. There is no routine
    // registry: the rule depends only on the routine type.
    $tokens = new CsrfTokenService();
    $validToken = $tokens->token();
    $csrf = new CsrfProtectionMiddleware($tokens);
    $procedures = [
        ['action' => 'procedure', 'source' => ['procedure' => 'dbo.PostLedger'], 'parameters' => []],
        ['action' => 'procedure', 'source' => ['procedure' => 'LedgerReport'], 'parameters' => []],
        ['action' => 'procedure', 'source' => 'malformed'],
    ];
    unset($_SERVER['GENERIC_AUTH_PROVIDER']);
    foreach ($procedures as $request) {
        foreach ([null, str_repeat('0', 64), substr($validToken, 0, 32)] as $provided) {
            if ($provided === null) unset($_SERVER['HTTP_X_CSRF_TOKEN']); else $_SERVER['HTTP_X_CSRF_TOKEN'] = $provided;
            hardeningFailure(fn () => $csrf->handle($request), 'CSRF_VALIDATION_FAILED', 403,
                'A session procedure call ran without a valid CSRF token.');
        }
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $validToken;
        $csrf->handle($request);
    }
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    // Scalar and table-valued functions cannot modify data and stay ungated,
    // like select.
    foreach ([
        ['action' => 'function', 'source' => ['function' => 'dbo.Score']],
        ['action' => 'tableFunction', 'source' => ['function' => 'dbo.Rows']],
        ['action' => 'function'],
    ] as $request) $csrf->handle($request);
    // API-key and anonymous callers remain exempt, as for insert/update/delete.
    foreach (['api_key', 'none'] as $provider) {
        $_SERVER['GENERIC_AUTH_PROVIDER'] = $provider;
        foreach ($procedures as $request) $csrf->handle($request);
    }
    unset($_SERVER['GENERIC_AUTH_PROVIDER']);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    // AAPI-08: anonymous and legacy-key principals cannot be System Administrators.
    $authorizationRepository = new AuthorizationRepository();
    $defaults = RuntimeConfiguration::authorizationDefaults();
    foreach (['publicRoles', 'legacyApiKeyRoles'] as $field) {
        foreach ([[RoleModel::SYSTEM_ADMINISTRATOR], [RoleModel::READ_ONLY, RoleModel::SYSTEM_ADMINISTRATOR]] as $roles) {
            try {
                $authorizationRepository->validate([$field => $roles] + $defaults);
                throw new LogicException("{$field} accepted the System Administrator role.");
            } catch (RuntimeException $exception) {
                hardeningAssert($exception->getMessage() === 'Unsafe authorization role assignment.', "{$field} failed for the wrong reason.");
            }
        }
        foreach ([[], [RoleModel::READ_ONLY], [RoleModel::DATA_OPERATOR]] as $roles) $authorizationRepository->validate([$field => $roles] + $defaults);
    }
    $authorizationRepository->validate($defaults);
    // Anonymous principals hold no role by default (Phase 4 / F-01).
    hardeningAssert($defaults['publicRoles'] === [] && $defaults['legacyApiKeyRoles'] === [RoleModel::READ_ONLY],
        'Anonymous or legacy-key defaults are no longer least privilege.');

    $repository = new AuthRepository(RuntimeConfiguration::path(RuntimeConfiguration::AUTH_FILE));
    $users = new UserManagementService($repository);
    $users->createUser('Hardening.Admin', 'hardening-admin-password', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR, true, 'Hardening Admin', '+15550001001', 'admin@example.invalid');
    $users->createUser('Frontend.Admin', 'frontend-admin-password', null, true, RoleModel::APPLICATION_ADMINISTRATOR, true, 'Frontend Admin', '+15550001002', 'frontend-admin@example.invalid');
    $users->createUser('Frontend.Reader', 'frontend-reader-password', RoleModel::READ_ONLY, true, null, true, 'Frontend Reader', '+15550001003', 'reader@example.invalid');
    $users->createUser('Frontend.Operator', 'frontend-operator-password', RoleModel::DATA_OPERATOR, true, null, true, 'Frontend Operator', '+15550001004', 'operator@example.invalid');
    $users->createUser('Backend.Operator', 'backend-operator-password', RoleModel::DATA_OPERATOR, false, null);
    $users->createUser('Key.Owner', 'key-owner-password', RoleModel::DATA_OPERATOR, false, null);
    $systemAdministrator = hardeningPrincipal($repository, 'Hardening.Admin');
    $frontendAdministrator = hardeningPrincipal($repository, 'Frontend.Admin');

    // AAPI-04: backend identity profiles are hidden from frontend administrators.
    $byUsername = fn (array $listed): array => array_column($listed, null, 'username');
    $listed = $byUsername($users->listFrontendUsers($frontendAdministrator));
    hardeningAssert(!isset($listed['Backend.Operator']) && !isset($listed['Key.Owner']), 'Backend-only accounts appear in the frontend list.');
    foreach (['Hardening.Admin', 'Frontend.Operator'] as $backendIdentity) {
        $row = $listed[$backendIdentity] ?? null;
        hardeningAssert($row !== null && $row['backendProtected'] === true
            && $row['name'] === null && $row['mobile'] === null && $row['email'] === null,
            "Frontend administrator received the profile of backend identity {$backendIdentity}.");
    }
    foreach (['Frontend.Admin' => 'frontend-admin@example.invalid', 'Frontend.Reader' => 'reader@example.invalid'] as $frontendIdentity => $email) {
        hardeningAssert($listed[$frontendIdentity]['backendProtected'] === false && $listed[$frontendIdentity]['email'] === $email
            && $listed[$frontendIdentity]['name'] !== null && $listed[$frontendIdentity]['mobile'] !== null,
            "Frontend identity {$frontendIdentity} lost its manageable profile.");
    }
    $anonymousList = $byUsername($users->listFrontendUsers());
    hardeningAssert($anonymousList['Hardening.Admin']['email'] === null, 'A list without a viewer exposed a backend identity profile.');
    $administratorList = $byUsername($users->listFrontendUsers($systemAdministrator));
    hardeningAssert($administratorList['Hardening.Admin']['email'] === 'admin@example.invalid'
        && $administratorList['Frontend.Operator']['email'] === 'operator@example.invalid',
        'System Administrator lost backend identity profiles in the frontend list.');
    $operatorSelf = new Principal($repository->findUser('Frontend.Operator')['id'], 'Frontend.Operator', 'session', RoleModel::DATA_OPERATOR, true, null, true);
    hardeningAssert($byUsername($users->listFrontendUsers($operatorSelf))['Frontend.Operator']['email'] === 'operator@example.invalid',
        'A backend identity could not see its own profile.');
    hardeningAssert(!str_contains(json_encode($users->listFrontendUsers($frontendAdministrator), JSON_THROW_ON_ERROR), 'passwordHash'), 'Frontend list exposed credentials.');

    // AAPI-04: a missing account is indistinguishable from a protected one.
    foreach (['Frontend Administrator' => $frontendAdministrator, 'System Administrator' => $systemAdministrator] as $actorName => $actor) {
        $responses = [];
        foreach (['Backend.Operator', 'Missing.Account'] as $target) {
            foreach ([
                fn () => $users->changeFrontendUserPassword($actor, $target, 'probe-password-value'),
                fn () => $users->updateFrontendProfile($actor, $target, 'Probe', 'Probe.Name', '+15551234567', null),
                fn () => $users->setFrontendUserEnabled($actor, $target, false),
                fn () => $users->deleteFrontendUser($actor, $target),
                fn () => $users->assignFrontendAccess($actor, $target, true, null),
            ] as $index => $operation) {
                $failure = hardeningFailure($operation, 'AUTHORIZATION_DENIED', 403, "{$actorName} probe of {$target} was not refused.");
                $responses[$target][$index] = [$failure->getStatusCode(), $failure->getErrorCode(), $failure->getMessage(), $failure->getDetails()];
            }
        }
        hardeningAssert($responses['Backend.Operator'] === $responses['Missing.Account'], "{$actorName} can distinguish missing from protected accounts.");
    }
    hardeningAssert($repository->findUser('Missing.Account') === null, 'A refused probe created an account.');
    $readerPrincipal = hardeningPrincipal($repository, 'Frontend.Reader');
    hardeningFailure(fn () => $users->changeFrontendUserPassword($readerPrincipal, 'Missing.Account', 'probe-password-value'),
        'AUTHORIZATION_DENIED', 403, 'A non-administrator probe of a missing account was not refused.');
    // The Admin API keeps its explicit not-found response.
    hardeningFailure(fn () => $users->changePassword('Missing.Account', 'probe-password-value'), 'USER_NOT_FOUND', 404, 'Admin user management lost USER_NOT_FOUND.');

    // AAPI-05: password resets revoke every session through authVersion.
    $readerVersion = $repository->findUser('Frontend.Reader')['authVersion'];
    $users->changeFrontendUserPassword($frontendAdministrator, 'Frontend.Reader', 'reset-reader-password');
    hardeningAssert($repository->findUser('Frontend.Reader')['authVersion'] === $readerVersion + 1, 'Frontend password reset did not advance authVersion.');
    $operatorVersion = $repository->findUser('Backend.Operator')['authVersion'];
    $users->changePassword('Backend.Operator', 'reset-operator-password');
    hardeningAssert($repository->findUser('Backend.Operator')['authVersion'] === $operatorVersion + 1, 'Admin password reset did not advance authVersion.');

    // AAPI-06: an API key keeps its SA-assigned role; owner state still gates it.
    $keys = new ApiKeyService();
    $created = $keys->create('Hardening key', 'Key.Owner', [RoleModel::DATA_OPERATOR]);
    $raw = $created['apiKey'];
    $ownerId = $repository->findUser('Key.Owner')['id'];
    hardeningAssert(($keys->authenticate($raw)['key']['roles'] ?? null) === [RoleModel::DATA_OPERATOR], 'API key did not authenticate.');
    $users->assignAuthorization('Key.Owner', RoleModel::READ_ONLY, false, null, $systemAdministrator->userId);
    $users->changePassword('Key.Owner', 'replacement-owner-password');
    $resolved = $keys->authenticate($raw);
    hardeningAssert($resolved !== null && $resolved['key']['roles'] === [RoleModel::DATA_OPERATOR] && $resolved['owner']['id'] === $ownerId,
        'API key role no longer follows the key record after owner demotion or password change.');
    $users->setEnabled('Key.Owner', false);
    hardeningAssert($keys->authenticate($raw) === null, 'API key authenticated for a disabled owner.');
    $users->setEnabled('Key.Owner', true);
    hardeningAssert($keys->authenticate($raw) !== null, 'API key did not recover when its owner was re-enabled.');
    $keys->setEnabled($created['id'], false);
    hardeningAssert($keys->authenticate($raw) === null, 'Disabled API key authenticated.');
    $keys->setEnabled($created['id'], true);
    $keys->revoke($created['id']);
    hardeningAssert($keys->authenticate($raw) === null, 'Revoked API key authenticated.');
    hardeningFailure(fn () => $keys->setEnabled($created['id'], true), 'API_KEY_REVOKED', 409, 'Revoked API key was re-enabled.');
    $second = $keys->create('Deleted owner key', 'Key.Owner', [RoleModel::API_ADMINISTRATOR]);
    hardeningAssert(($keys->authenticate($second['apiKey'])['key']['roles'] ?? null) === [RoleModel::API_ADMINISTRATOR],
        'A key-only role could not be issued to a lower-privileged owner.');
    $users->deleteUser('Key.Owner', 'Hardening.Admin');
    hardeningAssert($keys->authenticate($second['apiKey']) === null, 'API key authenticated after its owner was deleted.');

    $environment = [
        'GENERIC_APP_ENV' => 'development',
        'GENERIC_RUNTIME_CONFIG_DIR' => $configurationDirectory,
        'GENERIC_LOG_DIR' => $logDirectory,
        'GENERIC_ADMIN_ENABLED' => '1',
    ];
    [$servers['api'], $apiPort] = hardeningStartServer($root, 'api', $environment);
    [$servers['admin'], $adminPort] = hardeningStartServer($root, 'admin', $environment);

    // AAPI-07: a JSON list body never reaches an action on either API.
    foreach (['[]', '[{"action":"setup.status"}]', '["auth.frontendUsers.list"]'] as $body) {
        hardeningExpect(hardeningPost($apiPort, '/', $body), 401, 'AUTHENTICATION_REQUIRED', "Public API dispatched list body {$body} anonymously.");
        hardeningExpect(hardeningPost($adminPort, '/api.php', $body), 400, 'INVALID_REQUEST', "Admin API accepted list body {$body}.");
    }
    [$frontendAdminCookie, $frontendAdminToken] = hardeningLogin($apiPort, 'Frontend.Admin', 'frontend-admin-password');
    hardeningExpect(hardeningPost($apiPort, '/', '[{"action":"auth.frontendUsers.list"}]', $frontendAdminCookie, $frontendAdminToken),
        403, 'AUTHORIZATION_DENIED', 'An authenticated list body reached an action.');

    // AAPI-04 over HTTP: minimized list and uniform refusals.
    $listedOverHttp = $byUsername(hardeningPost($apiPort, '/', ['action' => 'auth.frontendUsers.list'], $frontendAdminCookie)['json']['data'] ?? []);
    hardeningAssert(($listedOverHttp['Hardening.Admin']['backendProtected'] ?? null) === true && array_key_exists('email', $listedOverHttp['Hardening.Admin'])
        && $listedOverHttp['Hardening.Admin']['email'] === null && $listedOverHttp['Hardening.Admin']['mobile'] === null,
        'Public frontend list exposed a System Administrator profile.');
    $probe = fn (string $target): array => hardeningPost($apiPort, '/', ['action' => 'auth.frontendUsers.changePassword', 'username' => $target,
        'newPassword' => 'probe-password-value', 'passwordConfirmation' => 'probe-password-value'], $frontendAdminCookie, $frontendAdminToken);
    $protectedProbe = $probe('Backend.Operator');
    $missingProbe = $probe('Missing.Account');
    hardeningExpect($missingProbe, 403, 'AUTHORIZATION_DENIED', 'Public API revealed a missing account.');
    unset($protectedProbe['json']['meta'], $missingProbe['json']['meta'], $protectedProbe['json']['requestId'], $missingProbe['json']['requestId']);
    hardeningAssert($protectedProbe['status'] === $missingProbe['status'] && ($protectedProbe['json']['error'] ?? null) === ($missingProbe['json']['error'] ?? null),
        'Public API responses distinguish missing from protected accounts.');

    // AAPI-05 over HTTP: a password change revokes every session of the account,
    // including the session that made the change.
    [$readerCookie] = hardeningLogin($apiPort, 'Frontend.Reader', 'reset-reader-password');
    [$secondAdminCookie] = hardeningLogin($apiPort, 'Frontend.Admin', 'frontend-admin-password');
    hardeningExpect(hardeningPost($apiPort, '/', ['action' => 'auth.frontendUsers.changePassword', 'username' => 'Frontend.Reader',
        'newPassword' => 'second-reader-password', 'passwordConfirmation' => 'second-reader-password'], $frontendAdminCookie, $frontendAdminToken),
        200, null, 'Frontend administrator could not reset a frontend user password.');
    hardeningExpect(hardeningPost($apiPort, '/', ['action' => 'select', 'source' => 'CustomerTable', 'fields' => ['*']], $readerCookie),
        401, 'AUTHENTICATION_REQUIRED', 'A reset password left the target session active.');
    hardeningExpect(hardeningPost($apiPort, '/', ['action' => 'auth.frontendUsers.changePassword', 'username' => 'Frontend.Admin',
        'newPassword' => 'rotated-admin-password', 'passwordConfirmation' => 'rotated-admin-password'], $frontendAdminCookie, $frontendAdminToken),
        200, null, 'Frontend administrator could not change its own password.');
    foreach ([$frontendAdminCookie, $secondAdminCookie] as $staleCookie) {
        hardeningExpect(hardeningPost($apiPort, '/', ['action' => 'auth.frontendUsers.list'], $staleCookie),
            401, 'AUTHENTICATION_REQUIRED', 'A self password change left a session active.');
    }
    hardeningLogin($apiPort, 'Frontend.Admin', 'rotated-admin-password');
    hardeningExpect(hardeningPost($apiPort, '/', ['action' => 'auth.frontendUsers.changePassword', 'username' => 'Frontend.Reader',
        'newPassword' => 'third-reader-password', 'passwordConfirmation' => 'third-reader-password'], $secondAdminCookie),
        401, 'AUTHENTICATION_REQUIRED', 'A revoked session changed a password.');

    echo "API security hardening tests passed.\n";
} finally {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
    unset($_SERVER['GENERIC_AUTH_PROVIDER'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    putenv('GENERIC_RUNTIME_CONFIG_DIR');
    putenv('GENERIC_LOG_DIR');
    hardeningRemove($configurationDirectory);
}
