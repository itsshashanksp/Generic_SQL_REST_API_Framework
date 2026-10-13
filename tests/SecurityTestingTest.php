<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../app/Services/UserManagementService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';
require_once __DIR__ . '/../app/Services/ApiKeyService.php';
require_once __DIR__ . '/../app/Services/AuthorizationService.php';
require_once __DIR__ . '/../app/Middleware/AuthenticationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/AuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/FrontendUserAuthorizationMiddleware.php';
require_once __DIR__ . '/../app/Middleware/CsrfProtectionMiddleware.php';
require_once __DIR__ . '/../app/Requests/AuthRequestValidator.php';
require_once __DIR__ . '/../app/Requests/QueryRequestValidator.php';
require_once __DIR__ . '/../app/Requests/AdminRequestValidator.php';
require_once __DIR__ . '/../app/Resources/SqlResourceDiscovery.php';
require_once __DIR__ . '/../app/Resources/SqlResourceStatement.php';
require_once __DIR__ . '/../app/Resources/DatabaseObjectName.php';
require_once __DIR__ . '/../app/Repositories/MetadataRepository.php';
require_once __DIR__ . '/../app/Repositories/Query/WhereBuilder.php';
require_once __DIR__ . '/../app/Repositories/Write/InsertBuilder.php';
require_once __DIR__ . '/../app/Repositories/Write/UpdateBuilder.php';
require_once __DIR__ . '/../app/Repositories/Write/DeleteBuilder.php';
require_once __DIR__ . '/../app/Repositories/Write/UpsertBuilder.php';
require_once __DIR__ . '/../app/Http/RequestBodyReader.php';
require_once __DIR__ . '/../app/Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../app/Backup/ApplicationBackupManager.php';
require_once __DIR__ . '/../core/ExceptionHandler.php';

function securityTestingAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function securityTestingFailure(callable $operation, ?string $code = null, ?int $status = null): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($code !== null) {
            securityTestingAssert($exception instanceof ApiRequestException, "Expected API failure {$code}.");
            securityTestingAssert($exception->getErrorCode() === $code,
                "Expected {$code}, got {$exception->getErrorCode()}.");
        }
        if ($status !== null) {
            securityTestingAssert($exception instanceof ApiRequestException
                && $exception->getStatusCode() === $status, "Unexpected status for {$code}.");
        }
        return $exception;
    }
    throw new RuntimeException('Expected a security boundary rejection.');
}

function securityTestingRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($path);
}

function securityTestingCloseSession(string $sessionName): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    unset($_COOKIE[$sessionName]);
    session_id('');
}

final class SecurityTestingMetadataRepository extends MetadataRepository
{
    public function __construct() {}
    public function columnExists($table, $column) { return in_array($column, ['Id', 'Name'], true); }
    public function getColumnDataType($table, $column) { return $column === 'Id' ? 'int' : 'nvarchar'; }
}

if (!defined('API_REQUEST_ID')) define('API_REQUEST_ID', 'security-testing-request');

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-security-testing-' . bin2hex(random_bytes(8));
$runtime = $root . '/runtime';
$sessions = $root . '/sessions';
$logs = $root . '/logs';
$resources = $root . '/queries';
$sessionName = 'generic_security_testing_' . bin2hex(random_bytes(4));
$oldRuntime = getenv('GENERIC_RUNTIME_CONFIG_DIR');
$oldEnvironment = getenv('GENERIC_APP_ENV');
$oldOrigins = getenv('GENERIC_API_ALLOWED_ORIGINS');
$oldKey = getenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
// Default loggers and security storage must never reach the deployment's logs
// or state, even when this file is run directly (tests/run.php isolates them
// for the whole run; a direct run did not).
$isolatedPaths = ['GENERIC_LOG_DIR' => $root . '/default-logs', 'GENERIC_OPERATIONAL_LOG_DIR' => $root . '/operational',
    'GENERIC_SECURITY_STORAGE_DIR' => $root . '/security'];
$oldIsolated = array_map(fn (string $name) => getenv($name), array_combine(array_keys($isolatedPaths), array_keys($isolatedPaths)));
$oldRemoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$oldUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

try {
    foreach ([$root, $sessions, $logs, $resources, ...array_values($isolatedPaths)] as $directory) {
        if (!is_dir($directory)) mkdir($directory, 0700, true);
    }
    foreach ($isolatedPaths as $name => $path) putenv($name . '=' . $path);
    ini_set('session.save_path', $sessions);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $runtime);
    putenv('GENERIC_APP_ENV=production');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.44';
    RuntimeConfiguration::ensure();

    $logger = new Logger($logs);
    $repository = new AuthRepository();
    $users = new UserManagementService($repository, new PasswordHasher(), $logger);
    $users->createUser('System.Admin', 'fake-admin-password-123', RoleModel::SYSTEM_ADMINISTRATOR,
        true, RoleModel::APPLICATION_ADMINISTRATOR);
    $users->createUser('Read.User', 'fake-reader-password-123', RoleModel::READ_ONLY, false, null);
    $users->createUser('Data.Operator', 'fake-operator-password-123', RoleModel::DATA_OPERATOR, false, null);
    $users->createUser('Disabled.User', 'fake-disabled-password-123', RoleModel::READ_ONLY, false, null, false);
    $users->createUser('Application.Admin', 'fake-application-password-123', null, true, RoleModel::APPLICATION_ADMINISTRATOR);

    // Authentication failures are generic, validation rejects malformed credentials, and login regenerates the ID.
    $authValidator = new AuthRequestValidator();
    foreach ([
        ['action' => 'auth.login', 'username' => '', 'password' => 'x'],
        ['action' => 'auth.login', 'username' => "bad\nname", 'password' => 'x'],
        ['action' => 'auth.login', 'username' => 'Read.User', 'password' => ''],
        ['action' => 'auth.login', 'username' => 'Read.User', 'password' => 'x', 'backendRole' => RoleModel::SYSTEM_ADMINISTRATOR],
    ] as $malformed) securityTestingFailure(fn () => $authValidator->validate($malformed), 'INVALID_AUTH_REQUEST');

    $loginClock = 1000;
    $loginLimiter = new LoginRateLimiter($root . '/login-rate', [
        'enabled' => true, 'maximumAttempts' => 2, 'windowSeconds' => 60, 'lockoutSeconds' => 30,
    ], function () use (&$loginClock): int { return $loginClock; });
    $session = new AuthSessionService($sessionName, [
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        'idleTimeout' => 600, 'absoluteTimeout' => 3600,
    ], $logger);
    $auth = new AuthService($repository, new PasswordHasher(), $session, $loginLimiter, $logger);
    $genericCredentialDetails = [];
    foreach ([['Missing.User', 'wrong-password'], ['Disabled.User', 'fake-disabled-password-123']] as [$username, $password]) {
        $failure = securityTestingFailure(fn () => $auth->login($username, $password), 'INVALID_CREDENTIALS', 401);
        $genericCredentialDetails[] = $failure->getDetails();
        securityTestingAssert($failure->getMessage() === 'Invalid username or password.'
            && ($failure->getDetails()[0]['attemptsRemaining'] ?? null) === 1
            && ($failure->getDetails()[0]['locked'] ?? null) === false,
            'Authentication disclosed account existence or state.');
    }
    securityTestingAssert($genericCredentialDetails[0] === $genericCredentialDetails[1],
        'Credential feedback differs for missing and disabled identities.');
    securityTestingFailure(fn () => $auth->login('Read.User', 'wrong-one'), 'INVALID_CREDENTIALS', 401);
    $locked = securityTestingFailure(fn () => $auth->login('Read.User', 'wrong-two'), 'LOGIN_RATE_LIMITED', 429);
    securityTestingAssert(($locked->getDetails()[0]['locked'] ?? null) === true
        && ($locked->getDetails()[0]['retryAfterSeconds'] ?? null) === 30, 'Lockout feedback omitted the safe retry duration.');
    $_SERVER['HTTP_USER_AGENT'] = 'changed-agent';
    $loginClock += 11;
    $stillLocked = securityTestingFailure(fn () => $auth->login('Read.User', 'fake-reader-password-123'), 'LOGIN_RATE_LIMITED', 429);
    securityTestingAssert(($stillLocked->getDetails()[0]['retryAfterSeconds'] ?? null) === 19,
        'Lockout feedback did not use the server-side remaining duration.');
    $loginClock += 20;
    $session->start();
    $anonymousId = session_id();
    $snapshot = $auth->login('Read.User', 'fake-reader-password-123');
    securityTestingAssert($snapshot['authenticated'] === true && session_id() !== $anonymousId,
        'Valid login failed or retained the pre-login session identifier.');
    $cookie = session_get_cookie_params();
    securityTestingAssert($cookie['lifetime'] === 0 && $cookie['path'] === '/' && $cookie['domain'] === ''
        && $cookie['secure'] && $cookie['httponly'] && ($cookie['samesite'] ?? null) === 'Lax',
        'Session cookie security properties changed.');
    securityTestingAssert(ini_get('session.use_only_cookies') === '1'
        && ini_get('session.use_strict_mode') === '1' && ini_get('session.use_trans_sid') === '0',
        'Cookie-only strict sessions are not enforced.');

    // Server-side identity wins over role/capability fields supplied by a client.
    $authorization = new AuthorizationService(new AuthorizationRepository(), $logger);
    $boundary = new AuthenticationMiddleware(true, [], $session, $repository, null, $authorization, null, $logger,
        new ApiRateLimiter($root . '/authenticated-rate', ['enabled' => true, 'requests' => 10, 'windowSeconds' => 60]));
    $boundary->handle([
        'action' => 'admin.status', 'backendRole' => RoleModel::SYSTEM_ADMINISTRATOR,
        'frontendAccess' => true, 'frontendRole' => RoleModel::APPLICATION_ADMINISTRATOR,
    ]);
    securityTestingAssert(PrincipalContext::current()?->backendRole === RoleModel::READ_ONLY,
        'Client-supplied role fields changed the authenticated principal.');
    securityTestingFailure(fn () => (new AuthorizationMiddleware($authorization))->handle(['action' => 'admin.status']),
        'AUTHORIZATION_DENIED', 403);

    // Password/authVersion changes and deletion invalidate a stale session.
    $users->changePassword('Read.User', 'replacement-reader-password-123');
    securityTestingAssert($auth->session() === ['authenticated' => false, 'user' => null],
        'A session survived an authVersion-changing password update.');
    securityTestingCloseSession($sessionName);
    $temporary = $users->createUser('Deleted.User', 'fake-deleted-password-123', RoleModel::READ_ONLY, false, null);
    $deletedSession = new AuthSessionService($sessionName, null, $logger);
    $deletedAuth = new AuthService($repository, new PasswordHasher(), $deletedSession,
        new LoginRateLimiter($root . '/deleted-login', ['enabled' => false]), $logger);
    $deletedAuth->login('Deleted.User', 'fake-deleted-password-123');
    $users->deleteUser('Deleted.User', 'System.Admin');
    securityTestingAssert($deletedAuth->session() === ['authenticated' => false, 'user' => null]
        && !array_key_exists('passwordHash', $temporary), 'Deleted identity remained usable or leaked credentials.');
    securityTestingCloseSession($sessionName);

    // Idle/absolute expiry, logout replay, CSRF rotation, and API-key CSRF independence.
    $csrfSession = new AuthSessionService($sessionName, [
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        'idleTimeout' => 60, 'absoluteTimeout' => 300,
    ], $logger);
    $csrf = new CsrfTokenService($csrfSession);
    $firstToken = $csrf->token();
    $csrfSession->establish('Read.User', $repository->findUser('Read.User')['id'],
        $repository->findUser('Read.User')['authVersion']);
    $authenticatedToken = $csrf->rotate();
    securityTestingFailure(fn () => $csrf->validate($firstToken), 'CSRF_VALIDATION_FAILED', 403);
    unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['GENERIC_AUTH_PROVIDER']);
    $csrfMiddleware = new CsrfProtectionMiddleware($csrf);
    securityTestingFailure(fn () => $csrfMiddleware->handle(['action' => 'update']), 'CSRF_VALIDATION_FAILED', 403);
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $authenticatedToken;
    $csrfMiddleware->handle(['action' => 'update']);
    $_SERVER['GENERIC_AUTH_PROVIDER'] = 'api_key';
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $csrfMiddleware->handle(['action' => 'update']);
    unset($_SERVER['GENERIC_AUTH_PROVIDER']);

    $_SESSION['security_concurrent_counter'] = 0;
    $concurrentSessionId = session_id();
    session_write_close();
    $workers = [];
    for ($index = 0; $index < 2; $index++) {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() === false) $command[] = '-n';
        array_push($command, __DIR__ . '/SessionConcurrentWorker.php', $sessionName, $sessions, $concurrentSessionId);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        securityTestingAssert(is_resource($process), 'Concurrent session worker could not start.');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        securityTestingAssert(proc_close($process) === 0 && trim($stdout) === 'ok' && $stderr === '',
            'Concurrent same-session request failed safely: ' . $stdout . $stderr);
    }
    $_COOKIE[$sessionName] = $concurrentSessionId;
    securityTestingAssert($csrfSession->resume() && ($_SESSION['security_concurrent_counter'] ?? null) === 2,
        'Concurrent same-session requests lost state instead of serializing on the session lock.');
    unset($_SESSION['security_concurrent_counter']);
    $_SESSION['generic_reporting_session_meta']['lastActivity'] = time() - 61;
    securityTestingAssert($csrfSession->resume() === false, 'Idle timeout was bypassed.');
    securityTestingCloseSession($sessionName);
    $absoluteSession = new AuthSessionService($sessionName, [
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        'idleTimeout' => 60, 'absoluteTimeout' => 300,
    ], $logger);
    $absoluteSession->establish('Read.User', $repository->findUser('Read.User')['id'],
        $repository->findUser('Read.User')['authVersion']);
    $_SESSION['generic_reporting_session_meta']['createdAt'] = time() - 301;
    $_SESSION['generic_reporting_session_meta']['lastActivity'] = time();
    securityTestingAssert($absoluteSession->resume() === false, 'Absolute timeout was bypassed.');
    securityTestingCloseSession($sessionName);
    $logoutSession = new AuthSessionService($sessionName, null, $logger);
    $logoutSession->establish('Read.User', $repository->findUser('Read.User')['id'],
        $repository->findUser('Read.User')['authVersion']);
    $logoutId = session_id();
    $logoutSession->destroy();
    securityTestingAssert(!is_file($sessions . '/sess_' . $logoutId), 'Logout left reusable server-side session state.');
    securityTestingCloseSession($sessionName);

    // Role boundaries, cross-role access, and object protection. Authorization
    // is permission-only: there are no per-role resource scopes.
    (new AuthorizationRepository())->save(RuntimeConfiguration::authorizationDefaults());
    $authorization = new AuthorizationService(new AuthorizationRepository(), $logger);
    $reader = new Principal(str_repeat('1', 32), 'Read.User', 'session', RoleModel::READ_ONLY, false, null, true);
    $operator = new Principal(str_repeat('2', 32), 'Data.Operator', 'session', RoleModel::DATA_OPERATOR, false, null, true);
    $systemAdministrator = new Principal(str_repeat('3', 32), 'System.Admin', 'session', RoleModel::SYSTEM_ADMINISTRATOR, true, RoleModel::APPLICATION_ADMINISTRATOR, true);
    $applicationAdministrator = new Principal(str_repeat('4', 32), 'Application.Admin', 'session', null, true, RoleModel::APPLICATION_ADMINISTRATOR, true);
    $authorization->authorize($reader, 'data.read');
    $authorization->authorize($reader, 'sql.execute');
    $authorization->authorize($reader, 'routine.execute');
    securityTestingFailure(fn () => $authorization->authorize($reader, 'data.write'), 'AUTHORIZATION_DENIED', 403);
    $authorization->authorize($operator, 'data.write');
    securityTestingFailure(fn () => $authorization->authorize($operator, 'admin.manage'), 'AUTHORIZATION_DENIED', 403);
    $authorization->authorize($systemAdministrator, 'admin.manage');
    $authorization->authorize($applicationAdministrator, 'frontend.users.manage');
    securityTestingFailure(fn () => $authorization->authorize($applicationAdministrator, 'admin.manage'), 'AUTHORIZATION_DENIED', 403);
    securityTestingFailure(fn () => $authorization->authorize($applicationAdministrator, 'data.write'), 'AUTHORIZATION_DENIED', 403);
    securityTestingFailure(fn () => $authorization->authorize($applicationAdministrator, 'routine.execute'), 'AUTHORIZATION_DENIED', 403);
    $persistedApplicationAdmin = $repository->findUser('Application.Admin');
    $persistedApplicationPrincipal = new Principal(
        $persistedApplicationAdmin['id'], $persistedApplicationAdmin['username'], 'session', null,
        true, RoleModel::APPLICATION_ADMINISTRATOR, true
    );
    securityTestingFailure(
        fn () => $users->changeFrontendUserPassword($persistedApplicationPrincipal, 'System.Admin', 'unsafe-change'),
        'AUTHORIZATION_DENIED',
        403
    );

    // API key secret handling, owner state, role confinement, object IDs, and invalid-auth throttling.
    $keys = new ApiKeyService(null, $repository, $authorization, $logger);
    $createdKey = $keys->create('Security test key', 'Data.Operator', [RoleModel::DATA_OPERATOR]);
    securityTestingAssert(str_starts_with($createdKey['apiKey'], 'gsk_')
        && !str_contains(json_encode((new ApiKeyRepository())->load(), JSON_THROW_ON_ERROR), $createdKey['apiKey']),
        'Raw API key was not one-time-only or reached storage.');
    securityTestingAssert(!str_contains(json_encode($keys->list(), JSON_THROW_ON_ERROR), $createdKey['apiKey']),
        'Raw API key reappeared after creation.');
    securityTestingAssert($keys->authenticate('not-an-api-key') === null, 'Malformed API key authenticated.');
    securityTestingFailure(fn () => $keys->create('Escalated key', 'Data.Operator', [RoleModel::SYSTEM_ADMINISTRATOR, RoleModel::DATA_OPERATOR]),
        'INVALID_API_KEY_REQUEST');
    $missingKey = securityTestingFailure(fn () => $keys->setEnabled(str_repeat('f', 16), false), 'API_KEY_NOT_FOUND', 404);
    securityTestingAssert($missingKey->getDetails() === [], 'Unknown API key leaked object metadata.');

    $adminConfiguration = new AdminConfigurationRepository();
    $configured = $adminConfiguration->load();
    $configured['authentication']['mode'] = 'api_key';
    $adminConfiguration->save($configured);
    securityTestingCloseSession($sessionName);
    $_SERVER['HTTP_X_API_KEY'] = $createdKey['apiKey'];
    $keyManagementBoundary = new AuthenticationMiddleware(true, [], new AuthSessionService($sessionName), $repository,
        null, $authorization, $keys, $logger,
        new ApiRateLimiter($root . '/key-management-rate', ['enabled' => true, 'requests' => 2, 'windowSeconds' => 60]));
    securityTestingFailure(fn () => $keyManagementBoundary->handle(['action' => 'auth.apiKeys.list']),
        'AUTHENTICATION_REQUIRED', 401);
    $_SERVER['HTTP_X_API_KEY'] = 'gsk_' . str_repeat('0', 16) . '_' . str_repeat('A', 43);
    $invalidKeyBoundary = new AuthenticationMiddleware(true, [], new AuthSessionService($sessionName), $repository,
        null, $authorization, $keys, $logger,
        new ApiRateLimiter($root . '/invalid-key-rate', ['enabled' => true, 'requests' => 2, 'windowSeconds' => 60], static fn (): int => 5000));
    securityTestingFailure(fn () => $invalidKeyBoundary->handle(['action' => 'select']), 'AUTHENTICATION_REQUIRED', 401);
    $_SERVER['HTTP_USER_AGENT'] = 'another-agent';
    securityTestingFailure(fn () => $invalidKeyBoundary->handle(['action' => 'select', 'requestId' => 'changed']), 'AUTHENTICATION_REQUIRED', 401);
    securityTestingFailure(fn () => $invalidKeyBoundary->handle(['action' => 'select']), 'RATE_LIMIT_EXCEEDED', 429);

    $_SERVER['HTTP_X_API_KEY'] = $createdKey['apiKey'];
    $validKeyBoundary = new AuthenticationMiddleware(true, [], new AuthSessionService($sessionName), $repository,
        null, $authorization, $keys, $logger,
        new ApiRateLimiter($root . '/valid-key-rate', ['enabled' => true, 'requests' => 2, 'windowSeconds' => 60]));
    $validKeyBoundary->handle(['action' => 'select', 'backendRole' => RoleModel::SYSTEM_ADMINISTRATOR]);
    securityTestingAssert(PrincipalContext::current()?->backendRole === RoleModel::DATA_OPERATOR,
        'Client data escalated an API key role.');
    securityTestingAssert((new ApiKeyRepository())->findById($createdKey['id'])['lastUsedAt'] !== null,
        'Successful API-key authentication did not update last-used state.');
    securityTestingFailure(fn () => $authorization->authorize(PrincipalContext::current(), 'admin.manage'), 'AUTHORIZATION_DENIED', 403);
    $users->setEnabled('Data.Operator', false);
    securityTestingAssert($keys->authenticate($createdKey['apiKey']) === null,
        'API key authenticated for a disabled owner.');
    $users->setEnabled('Data.Operator', true);
    $keys->setEnabled($createdKey['id'], false);
    securityTestingAssert($keys->authenticate($createdKey['apiKey']) === null, 'Disabled API key remained valid.');
    $keys->setEnabled($createdKey['id'], true);
    $keys->revoke($createdKey['id']);
    securityTestingAssert($keys->authenticate($createdKey['apiKey']) === null, 'Revoked API key remained valid.');
    unset($_SERVER['HTTP_X_API_KEY']);
    PrincipalContext::clear();

    // SQL resource IDs and authored SQL stay server-controlled and read-only.
    mkdir($resources . '/reports', 0700, true);
    file_put_contents($resources . '/reports/allowed.sql', "SELECT Id, Name FROM dbo.Customers\n");
    $discovery = new SqlResourceDiscovery(['root' => $resources, 'exclude' => ['system']], $resources);
    securityTestingAssert($discovery->resolve('reports/allowed')['file'] === realpath($resources . '/reports/allowed.sql'),
        'Allowed SQL resource did not resolve.');
    foreach (['../secret', '..\\secret', '/etc/passwd', 'C:/Windows/win.ini', 'reports%2F..%2Fsecret', "reports/allowed\0.sql", 'https://example.test/a'] as $resource) {
        securityTestingFailure(fn () => $discovery->resolve($resource), 'INVALID_SQL_RESOURCE');
    }
    securityTestingFailure(fn () => $discovery->resolve('reports/unknown'), 'INVALID_SQL_RESOURCE');
    SqlResourceStatement::analyze('SELECT Id FROM dbo.Customers');
    foreach (['SELECT Id INTO dbo.Copy FROM dbo.Customers', 'SELECT Id FROM dbo.Customers; SELECT 1', 'DELETE FROM dbo.Customers'] as $sql) {
        securityTestingFailure(fn () => SqlResourceStatement::analyze($sql));
    }

    // Values are parameters; dynamic identifiers, metadata, CRUD columns, and resources are allowlisted.
    $injection = "x' OR '1'='1'; UNION SELECT passwordHash FROM users--";
    $where = (new WhereBuilder(new SecurityTestingMetadataRepository(),
        static fn (string $column): array => ['table' => 'Customers', 'column' => $column],
        static fn (): array => ['sql' => 'SELECT 1', 'params' => []]))->build([
            'table' => 'Customers',
            'where' => [['column' => 'Name', 'operator' => '=', 'value' => $injection]],
        ], []);
    securityTestingAssert($where['sql'] === ' WHERE Name = ?' && $where['params'] === [$injection]
        && !str_contains($where['sql'], $injection), 'Filter value was interpolated into SQL.');
    $queryValidator = new QueryRequestValidator();
    foreach ([
        ['action' => 'select', 'source' => ['table' => 'Customers; DROP TABLE Users'], 'fields' => ['Id']],
        ['action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => ['Id'], 'sort' => [['field' => 'Id;--', 'direction' => 'ASC']]],
        ['action' => 'select', 'source' => ['table' => 'Customers'], 'fields' => ['Id'], 'sort' => [['field' => 'Id', 'direction' => 'DESC; DROP']]],
        ['action' => 'sql', 'resource' => '../secret'],
        ['action' => 'sql', 'resource' => 'reports/allowed', 'execution' => ['filters' => ['Bad' => ['placement' => 'source', 'expression' => 'Id OR 1=1']]]],
    ] as $request) securityTestingFailure(fn () => $queryValidator->validate($request), 'INVALID_REQUEST');

    // Write targets are client-named tables, so the name itself is the attack
    // surface: only `Table` or `Schema.Table` identifiers outside system
    // schemas are accepted, and they are always bracket-quoted.
    $writeResource = ['name' => 'dbo.Customers', 'schema' => 'dbo', 'table' => 'Customers', 'identityColumn' => 'Id'];
    foreach (['../customers', 'customers;DROP', 'C:\\customers', 'master.dbo.Customers', '[dbo].[Customers]', 'dbo .Customers', ''] as $table) {
        securityTestingFailure(fn () => $queryValidator->validate([
            'action' => 'insert', 'table' => $table, 'data' => ['Name' => 'x'],
        ]), 'INVALID_REQUEST');
    }
    foreach (['sys.objects', 'SYS.sql_logins', 'INFORMATION_SCHEMA.TABLES'] as $table) {
        securityTestingFailure(fn () => DatabaseObjectName::parse($table, 'table', 'INVALID_WRITE_TABLE', 'Invalid write table.'), 'INVALID_WRITE_TABLE');
    }
    securityTestingAssert(DatabaseObjectName::parse('Customers', 'table', 'INVALID_WRITE_TABLE', 'x') === ['schema' => 'dbo', 'name' => 'Customers'],
        'Unqualified tables no longer default to dbo.');
    securityTestingFailure(fn () => $queryValidator->validate([
        'action' => 'insert', 'table' => 'Customers', 'data' => ['Name]; DROP TABLE Users;--' => 'x'],
    ]), 'INVALID_REQUEST');
    foreach ([
        fn () => $queryValidator->validate(['action' => 'update', 'table' => 'Customers', 'data' => ['Name' => 'x']]),
        fn () => $queryValidator->validate(['action' => 'delete', 'table' => 'Customers', 'filters' => []]),
    ] as $unsafeWrite) securityTestingFailure($unsafeWrite, 'UNSAFE_WRITE');
    $insert = (new InsertBuilder())->build($writeResource, ['CustomerCode' => 'C1', 'Name' => $injection]);
    $update = (new UpdateBuilder())->build($writeResource, ['Name' => $injection], [['field' => 'Id', 'operator' => '=', 'value' => 7]], 'AND');
    $delete = (new DeleteBuilder())->build($writeResource, [['field' => 'CustomerCode', 'operator' => '=', 'value' => $injection]], 'AND');
    $upsert = (new UpsertBuilder())->build($writeResource, ['CustomerCode' => 'C1', 'Name' => $injection], ['CustomerCode']);
    foreach ([$insert, $update, $delete, $upsert] as $statement) {
        securityTestingAssert(!str_contains($statement['sql'], $injection) && in_array($injection, $statement['params'], true),
            'CRUD value was not preserved as a prepared parameter.');
    }
    securityTestingAssert(str_contains($upsert['sql'], 'WITH (HOLDLOCK)'), 'UPSERT uniqueness/race protection changed.');

    // Request-size/pagination limits, CORS validation, safe methods, and connection-string injection.
    $configured = $adminConfiguration->load();
    $configured['runtime']['request']['maxBodyBytes'] = 1024;
    $configured['runtime']['request']['defaultPageSize'] = 10;
    $configured['runtime']['request']['maxPageSize'] = 10;
    $adminConfiguration->save($configured);
    $oversized = fopen('php://temp', 'w+b');
    fwrite($oversized, str_repeat('x', 1025)); rewind($oversized);
    securityTestingFailure(fn () => RequestBodyReader::read(1025, $oversized), 'REQUEST_TOO_LARGE', 413);
    fclose($oversized);
    securityTestingFailure(fn () => $queryValidator->validate([
        'action' => 'sql', 'resource' => 'reports/allowed', 'pagination' => ['page' => 1, 'pageSize' => 11],
    ]), 'INVALID_REQUEST');
    foreach (['*', 'https://good.example.test/path', 'https://user:pass@good.example.test',
        'https://good.example.test?query=1', 'https://good.example.test#fragment', 'javascript://bad'] as $origin) {
        securityTestingFailure(fn () => AdminConfigurationRepository::normalizeOrigin($origin));
    }
    putenv('GENERIC_API_ALLOWED_ORIGINS=*');
    securityTestingAssert(SecurityConfiguration::allowedOrigins() === [], 'Wildcard production CORS override did not fail closed.');
    $databaseRequest = [
        'action' => 'admin.database.test',
        'database' => ['provider' => 'sqlserver', 'driver' => 'auto', 'server' => 'db;UID=attacker',
            'port' => '1433', 'database' => 'App', 'authentication' => 'sql', 'username' => 'user',
            'password' => 'fake-password', 'encrypt' => true, 'trustServerCertificate' => false],
    ];
    securityTestingFailure(fn () => (new AdminRequestValidator())->validate($databaseRequest), 'INVALID_ADMIN_REQUEST');

    // Client errors, logs, health, headers, backups, and encrypted secrets reveal no protected values.
    $secrets = ['fake-password', $createdKey['apiKey'], 'fake-session-id', 'fake-csrf-token',
        '/private/application/path', 'SELECT passwordHash FROM users', 'GENERIC_SQL_API_ENCRYPTION_KEY'];
    [$status, $payload] = ExceptionHandler::responseFor(new RuntimeException(implode(' ', $secrets)));
    $serializedError = json_encode($payload, JSON_THROW_ON_ERROR);
    securityTestingAssert($status === 500 && $payload['error']['code'] === 'INTERNAL_ERROR'
        && $payload['meta']['requestId'] === API_REQUEST_ID, 'Unexpected error was not safely mapped.');
    foreach ($secrets as $secret) securityTestingAssert(!str_contains($serializedError, $secret), "Error exposed {$secret}.");
    $logger->write('password=fake-password x-api-key=' . $createdKey['apiKey']
        . ' session_id=fake-session-id csrf_token=fake-csrf-token Authorization: Bearer fake-bearer Cookie: sid=fake-cookie');
    $logContents = (string)file_get_contents($logs . '/' . date('Y-m-d') . '.log');
    foreach (['fake-password', $createdKey['apiKey'], 'fake-session-id', 'fake-csrf-token', 'fake-bearer', 'fake-cookie'] as $secret) {
        securityTestingAssert(!str_contains($logContents, $secret), "Audit/diagnostic log exposed {$secret}.");
    }
    $healthEndpoint = (string)file_get_contents(__DIR__ . '/../api/health.php');
    securityTestingAssert(str_contains($healthEndpoint, "['status' => \$payload['status'], 'service' => \$payload['service'], 'version' => \$payload['version']]")
        && !str_contains($healthEndpoint, 'password'), 'Public liveness response boundary is unsafe.');
    $adminBoundary = (string)file_get_contents(__DIR__ . '/../admin/api.php');
    securityTestingAssert(str_contains($adminBoundary, "'admin.health'")
        && str_contains($adminBoundary, 'AdminAuthorizationMiddleware'), 'Detailed health is not administrator-protected.');
    $hosting = implode("\n", array_map(static fn (string $file): string => (string)file_get_contents($file),
        glob(__DIR__ . '/../deployment/{iis,nginx}/*', GLOB_BRACE) ?: []));
    foreach (['Strict-Transport-Security', 'X-Content-Type-Options', 'X-Frame-Options',
        'Content-Security-Policy', 'Referrer-Policy', 'Permissions-Policy'] as $header) {
        securityTestingAssert(str_contains($hosting, $header), "Production templates omit {$header}.");
    }

    $key = base64_encode(random_bytes(32));
    $wrongKey = base64_encode(random_bytes(32));
    $encryption = new DatabaseCredentialEncryption($key);
    $database = ['provider' => 'sqlserver', 'server' => 'fake-db', 'database' => 'Fake',
        'authentication' => 'sql', 'username' => 'fake-user', 'password' => 'fake-db-password'];
    $encrypted = $encryption->encryptConfiguration($database);
    securityTestingAssert($encryption->decryptConfiguration($encrypted) === $database, 'Encrypted configuration did not round-trip.');
    securityTestingFailure(fn () => (new DatabaseCredentialEncryption($wrongKey))->decryptConfiguration($encrypted));
    $tampered = $encrypted;
    $tampered['ciphertext'] = base64_encode(strrev((string)base64_decode($tampered['ciphertext'], true)));
    securityTestingFailure(fn () => $encryption->decryptConfiguration($tampered));
    $unsupported = $encrypted; $unsupported['version'] = 999;
    securityTestingFailure(fn () => $encryption->decryptConfiguration($unsupported));
    putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE);
    securityTestingFailure(fn () => new DatabaseCredentialEncryption());
    securityTestingAssert(DatabaseCredentialResolver::resolve('legacy-plaintext') === 'legacy-plaintext',
        'Documented plaintext compatibility behavior changed.');

    $applicationRoot = $root . '/application';
    mkdir($applicationRoot . '/config', 0700, true);
    mkdir($applicationRoot . '/backups', 0700, true);
    $backupSources = [
        'config/auth.json' => $runtime . '/auth.json',
        'config/installation.json' => $runtime . '/installation.json',
        'config/admin.json' => $runtime . '/admin.json',
        'config/authorization.json' => $runtime . '/authorization.json',
        'config/api-keys.json' => $runtime . '/api-keys.json',
        'database/config/databases.json' => $root . '/databases.json',
    ];
    $backupManager = new ApplicationBackupManager($applicationRoot, $backupSources, 'security-test');
    securityTestingFailure(fn () => $backupManager->create($root . '/outside-backup.zip'));
    $backupSource = (string)file_get_contents(__DIR__ . '/../app/Backup/ApplicationBackupManager.php');
    foreach (['encryption_key', 'sessions', 'runtime_process_state', 'rate_limit_state', 'logs'] as $excluded) {
        securityTestingAssert(str_contains($backupSource, "'{$excluded}'"), "Backup exclusion {$excluded} is missing.");
    }
    securityTestingAssert(str_contains((string)file_get_contents(__DIR__ . '/../.gitignore'), 'runtime/health/'),
        'Generated health state is not excluded from version control.');

    // Static high-risk primitive review: no eval/unserialize; process execution stays in the fixed local process manager.
    $phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../app', FilesystemIterator::SKIP_DOTS));
    $applicationSource = '';
    foreach ($phpFiles as $file) if ($file->isFile() && $file->getExtension() === 'php') {
        $applicationSource .= (string)file_get_contents($file->getPathname());
    }
    securityTestingAssert(preg_match('/\beval\s*\(/i', $applicationSource) !== 1
        && preg_match('/\bunserialize\s*\(/i', $applicationSource) !== 1, 'Unsafe evaluation/deserialization primitive exists.');
    $processSource = (string)file_get_contents(__DIR__ . '/../app/Runtime/ApiProcessManager.php');
    securityTestingAssert(str_contains($processSource, "['bypass_shell' => true]")
        && str_contains($processSource, "(int)\$pid") && str_contains($processSource, 'belongsToService'),
        'Local process execution lost command-array, numeric-PID, or ownership safeguards.');

} finally {
    PrincipalContext::clear();
    unset($_SERVER['HTTP_X_API_KEY'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['GENERIC_AUTH_PROVIDER']);
    securityTestingCloseSession($sessionName);
    $oldRuntime === false ? putenv('GENERIC_RUNTIME_CONFIG_DIR') : putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $oldRuntime);
    $oldEnvironment === false ? putenv('GENERIC_APP_ENV') : putenv('GENERIC_APP_ENV=' . $oldEnvironment);
    $oldOrigins === false ? putenv('GENERIC_API_ALLOWED_ORIGINS') : putenv('GENERIC_API_ALLOWED_ORIGINS=' . $oldOrigins);
    $oldKey === false ? putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE)
        : putenv(DatabaseCredentialEncryption::ENVIRONMENT_VARIABLE . '=' . $oldKey);
    foreach ($oldIsolated as $name => $value) $value === false ? putenv($name) : putenv($name . '=' . $value);
    if ($oldRemoteAddress === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $oldRemoteAddress;
    if ($oldUserAgent === null) unset($_SERVER['HTTP_USER_AGENT']); else $_SERVER['HTTP_USER_AGENT'] = $oldUserAgent;
    securityTestingRemove($root);
}

echo "Comprehensive security testing passed.\n";
