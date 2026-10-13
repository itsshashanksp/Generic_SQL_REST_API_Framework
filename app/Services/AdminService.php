<?php

require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../Database/DatabaseRegistry.php';
require_once __DIR__ . '/../Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../Database/DatabaseConnectionException.php';
require_once __DIR__ . '/../Database/DatabaseServerProfile.php';
require_once __DIR__ . '/../Security/DatabaseCredentialEncryption.php';
require_once __DIR__ . '/../Security/ApiKeyAuthenticator.php';
require_once __DIR__ . '/ApiKeyService.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';
require_once __DIR__ . '/../Repositories/InstallationRepository.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Runtime/ApiProcessManager.php';
require_once __DIR__ . '/../Runtime/SqlParserProcessManager.php';
require_once __DIR__ . '/../Runtime/DatabaseAuthenticationSupport.php';
require_once __DIR__ . '/../Runtime/RuntimeDetector.php';
require_once __DIR__ . '/../Runtime/DatabaseAvailabilityManager.php';
require_once __DIR__ . '/../Runtime/ApplicationRuntimeManager.php';
require_once __DIR__ . '/../Health/ApplicationHealthMonitor.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/../../database/drivers/SqlServerDriver.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../Backup/BackupRecoveryService.php';
require_once __DIR__ . '/../../core/OperationalLogger.php';
require_once __DIR__ . '/../Database/DatabaseConnectionManager.php';
require_once __DIR__ . '/../../core/QueryEngine.php';
require_once __DIR__ . '/DatabaseAdministrationService.php';

final class AdminService
{
    private AdminConfigurationRepository $configuration;
    private string $databasePath;
    private DatabaseRegistry $databaseRegistry;
    private DatabaseContextResolver $databaseResolver;
    private bool $runtimeDatabasePath;
    private $connectionTester;
    private ApiProcessManager $processManager;
    private SqlParserProcessManager $parserProcessManager;
    private RuntimeDetector $runtimeDetector;
    private DatabaseAuthenticationSupport $databaseAuthentication;
    private DatabaseAvailabilityManager $databaseAvailability;
    private ApplicationRuntimeManager $applicationRuntime;
    private Logger $logger;
    private ApplicationHealthMonitor $healthMonitor;
    private BackupRecoveryService $backupRecovery;
    private ?DatabaseAdministrationService $databaseAdministration = null;

    public function __construct(
        ?AdminConfigurationRepository $configuration = null,
        ?string $databasePath = null,
        ?callable $connectionTester = null,
        ?ApiProcessManager $processManager = null,
        ?RuntimeDetector $runtimeDetector = null,
        ?SqlParserProcessManager $parserProcessManager = null,
        ?DatabaseAuthenticationSupport $databaseAuthentication = null,
        ?DatabaseAvailabilityManager $databaseAvailability = null,
        ?Logger $logger = null,
        ?ApplicationHealthMonitor $healthMonitor = null,
        ?ApplicationRuntimeManager $applicationRuntime = null,
        ?BackupRecoveryService $backupRecovery = null,
        ?callable $serverInspector = null
    ) {
        $this->configuration = $configuration ?? new AdminConfigurationRepository();
        $this->runtimeDatabasePath = $databasePath === null;
        $this->databasePath = $databasePath
            ?? dirname(__DIR__, 2) . '/database/config/database.json';
        // The single-database Admin actions manage the registry's default
        // database; the registry lives beside the V2 database.json path.
        $this->databaseRegistry = DatabaseRegistry::forLegacyPath($this->databasePath);
        $this->connectionTester = $connectionTester ?? function (array $database): void {
            $driver = new SqlServerDriver($database);
            try {
                $driver->connect();
            } finally {
                $driver->disconnect();
            }
        };
        $this->processManager = $processManager ?? new ApiProcessManager($this->configuration);
        $this->runtimeDetector = $runtimeDetector ?? new RuntimeDetector();
        $this->parserProcessManager = $parserProcessManager ?? new SqlParserProcessManager($this->configuration);
        $this->databaseAuthentication = $databaseAuthentication ?? new DatabaseAuthenticationSupport();
        $this->databaseAvailability = $databaseAvailability ?? new DatabaseAvailabilityManager(null, $this->databaseRegistry);
        $this->databaseResolver = new DatabaseContextResolver($this->databaseRegistry, $this->databaseAvailability);
        $this->applicationRuntime = $applicationRuntime ?? new ApplicationRuntimeManager();
        $this->logger = $logger ?? new Logger();
        // A server profile check: one request-scoped connection to the profile's
        // master catalog, reporting only version, edition, and server name.
        $serverInspector ??= static function (DatabaseContext $server): array {
            $connections = new DatabaseConnectionManager();
            try {
                $row = (new QueryEngine($connections->connection($server)))->executePrepared(
                    "SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(128)) AS ProductVersion,"
                        . " CAST(SERVERPROPERTY('Edition') AS nvarchar(128)) AS Edition,"
                        . " CAST(@@SERVERNAME AS nvarchar(128)) AS ServerName",
                    [],
                    ['queryPhase' => 'metadata']
                )['data'][0] ?? [];
                return ['productVersion' => $row['ProductVersion'] ?? null, 'edition' => $row['Edition'] ?? null, 'serverName' => $row['ServerName'] ?? null];
            } finally {
                $connections->closeAll();
            }
        };
        $this->healthMonitor = $healthMonitor ?? new ApplicationHealthMonitor([
            'databasePath' => $this->databasePath,
            'registry' => $this->databaseRegistry,
            'databaseAvailable' => fn (?string $databaseId = null): bool => $this->databaseAvailability->available($databaseId),
            'databaseTester' => $this->connectionTester,
            'serverInspector' => $serverInspector,
        ]);
        $this->backupRecovery = $backupRecovery ?? new BackupRecoveryService();
    }

    /** Server profiles and database contexts, managed through the registry (admin.servers.*, admin.databases.*). */
    public function databaseAdministration(): DatabaseAdministrationService
    {
        return $this->databaseAdministration ??= new DatabaseAdministrationService(
            $this->databaseRegistry,
            $this->databaseAvailability,
            $this->healthMonitor,
            fn (string $databaseId): array => $this->testCurrentDatabase($databaseId),
            fn (string $operation, string $databaseId): array => $this->controlDatabase($operation, $databaseId),
            $this->logger
        );
    }

    public function status(): array
    {
        $production = SecurityConfiguration::isProduction();
        $databaseAvailable = $this->databaseAvailability->available();
        $api = $production
            ? $this->applicationRuntime->status('api') : $this->processManager->status();
        $parser = $production
            ? $this->applicationRuntime->status('sqlParser') : $this->parserProcessManager->status();
        // In production the Admin Console is a web-server application: the
        // answering FastCGI/FPM worker's PID and listener port are not its own.
        $admin = $production
            ? [
                'running' => true,
                'healthy' => true,
                'status' => 'running',
                'controlMode' => 'application',
                'infrastructure' => [
                    'managedExternally' => true,
                    'status' => 'externally managed',
                    'healthy' => null,
                ],
                'pid' => null,
                'port' => null,
                'startedAt' => null,
            ]
            : [
                'running' => true,
                'healthy' => true,
                'status' => 'running',
                'pid' => getmypid(),
                'port' => isset($_SERVER['SERVER_PORT']) && filter_var($_SERVER['SERVER_PORT'], FILTER_VALIDATE_INT) !== false
                    ? (int)$_SERVER['SERVER_PORT']
                    : null,
                'startedAt' => getenv('GENERIC_ADMIN_STARTED_AT') ?: null,
            ];
        $monitoring = $this->healthMonitor->detailed([
            'adminConsole' => $admin,
            'api' => $api,
            'sqlParser' => $parser,
        ], $this->backupHealth());
        $databaseCheck = $monitoring['checks']['database'];
        $databaseConnected = $databaseAvailable && $databaseCheck['status'] === 'healthy';
        $database = [...$this->databaseStatus(),
            'connected' => $databaseConnected,
            'healthy' => $databaseConnected,
            'status' => $databaseConnected ? 'connected'
                : ($databaseAvailable ? $databaseCheck['category'] : 'disconnected'),
            'available' => $databaseAvailable,
            // disabled: access turned off by an administrator; unhealthy:
            // access enabled but the configured database cannot be used.
            'state' => !$databaseAvailable ? 'disabled' : ($databaseConnected ? 'connected' : 'unhealthy'),
            'reason' => !$databaseAvailable ? 'application_access_disabled'
                : ($databaseConnected ? null : $databaseCheck['category']),
        ];
        return [
            'hosting' => $this->hostingInformation($production),
            'adminConsole' => $admin,
            'api' => $api,
            'sqlParser' => $parser,
            'database' => $database,
            'phpRuntime' => [
                'running' => true,
                'healthy' => true,
                'status' => 'healthy',
                'phpVersion' => PHP_VERSION,
                'odbcAvailable' => extension_loaded('odbc'),
                'opensslAvailable' => extension_loaded('openssl'),
            ],
            'monitoring' => $monitoring,
        ];
    }

    public function systemInformation(): array
    {
        $runtime = $this->runtimeDetector->information();
        $installation = (new InstallationRepository())->load();
        $api = SecurityConfiguration::isProduction()
            ? $this->applicationRuntime->status('api') : $this->processManager->status();
        $parser = SecurityConfiguration::isProduction()
            ? $this->applicationRuntime->status('sqlParser') : $this->parserProcessManager->status();
        $databaseAvailable = $this->databaseAvailability->available();
        $database = $databaseAvailable ? $this->databaseHealth() : ['status' => 'disconnected'];
        return [
            'application' => 'Generic SQL REST API Framework',
            'status' => 'running',
            'platform' => $runtime['operatingSystem'] . ' ' . $runtime['architecture'],
            'phpRuntime' => $runtime['phpRuntime'] . ' · PHP ' . $runtime['phpVersion'],
            'configurationStatus' => $installation['initialized'] ? 'initialized' : 'setup required',
            'databaseStatus' => $database['status'] ?? 'unknown',
            'services' => [
                'adminConsole' => 'running',
                'api' => $api['status'],
                'sqlParser' => $parser['status'],
            ],
        ];
    }

    public function restartAdminConsole(): array
    {
        try {
            $this->configuration->load();
            clearstatcache(true);
            if (function_exists('opcache_reset')) @opcache_reset();
            $result = [
                'accepted' => true,
                'status' => 'restarting',
                'controlMode' => 'application',
                'checkAfterMilliseconds' => 750,
            ];
            $this->runtimeAudit('admin_console', 'restart', 'success', $result);
            (new OperationalLogger())->info('admin', 'Admin Console application restart accepted');
            return $result;
        } catch (Throwable $exception) {
            $this->runtimeAudit('admin_console', 'restart', 'failure', [], 'operation_failed');
            (new OperationalLogger())->error('admin', 'Admin Console application restart failed', [
                'error_code' => 'ADMIN_CONSOLE_RESTART_FAILED',
            ]);
            throw new ApiRequestException('Admin Console restart could not be initiated.', 'ADMIN_CONSOLE_RESTART_FAILED', [], 503);
        }
    }

    public function databaseConfiguration(): array
    {
        if ($this->databaseRegistry->source() === DatabaseRegistry::SOURCE_NONE) {
            return [
                'configured' => false,
                'encrypted' => false,
                'provider' => 'sqlserver',
                'driver' => 'auto',
                'server' => '',
                'port' => '1433',
                'database' => '',
                'authentication' => 'sql',
                'username' => '',
                'passwordConfigured' => false,
                'encrypt' => true,
                'trustServerCertificate' => false,
                'loginTimeoutSeconds' => DatabaseServerProfile::DEFAULT_LOGIN_TIMEOUT_SECONDS,
                'availableDrivers' => array_merge(['auto'], SqlServerDriver::supportedDrivers()),
                'availableAuthenticationModes' => $this->databaseAuthentication->modes(),
            ];
        }
        try {
            $database = $this->databaseRegistry->connectionConfiguration();
        } catch (Throwable $exception) {
            $this->logger->audit('database.connection_test', 'failure', 'WARNING', [
                'reason' => 'connection_failed', 'component' => 'database',
            ]);
            throw new ApiRequestException(
                'Database configuration is unavailable.',
                'DATABASE_CONFIGURATION_UNAVAILABLE',
                [],
                503
            );
        }
        return [
            'configured' => true,
            'encrypted' => $this->databaseRegistry->usesEncryption(),
            'provider' => $database['provider'],
            'driver' => $database['driver'] ?? 'auto',
            'server' => $database['server'] ?? '',
            'port' => isset($database['port']) ? (string)$database['port'] : '',
            'database' => $database['database'] ?? '',
            'authentication' => $database['authentication'] ?? 'sql',
            'username' => $database['username'] ?? '',
            'passwordConfigured' => (string)($database['password'] ?? '') !== '',
            'encrypt' => ($database['options']['encrypt'] ?? true) === true,
            'trustServerCertificate' => ($database['options']['trustServerCertificate'] ?? false) === true,
            'loginTimeoutSeconds' => DatabaseServerProfile::loginTimeoutFrom($database),
            'availableDrivers' => array_merge(['auto'], SqlServerDriver::supportedDrivers()),
            'availableAuthenticationModes' => $this->databaseAuthentication->modes(),
        ];
    }

    public function testDatabase(array $database): array
    {
        (new OperationalLogger())->info('database', 'Database connection test started');
        $resolved = $this->withExistingPassword($database);
        $this->validateDatabaseAuthentication($resolved);
        try {
            ($this->connectionTester)($resolved);
        } catch (Throwable $exception) {
            throw $this->connectionTestFailure($exception);
        }
        $this->logger->audit('database.connection_test', 'success', 'INFO', ['component' => 'database']);
        (new OperationalLogger())->info('database', 'Database connection test successful');
        return ['connected' => true];
    }

    public function testCurrentDatabase(?string $databaseId = null): array
    {
        $this->verifyDatabase($databaseId);
        return ['connected' => true];
    }

    /**
     * Resolve a configured, enabled database (the default when null) and open
     * and close one connection to it. Availability is not required: this is
     * what decides it.
     */
    private function verifyDatabase(?string $databaseId): DatabaseContext
    {
        (new OperationalLogger())->info('database', 'Database connection test started', ['database_context' => $databaseId]);
        try {
            $context = $this->databaseResolver->resolve($databaseId);
        } catch (ApiRequestException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->databaseConfigurationFailure();
        }
        $configuration = $context->driverConfiguration();
        $this->validateDatabaseAuthentication($configuration);
        try {
            ($this->connectionTester)($configuration);
        } catch (DatabaseCredentialException $exception) {
            // A legacy encrypted password is decrypted only when the driver connects.
            throw $this->databaseConfigurationFailure();
        } catch (Throwable $exception) {
            throw $this->connectionTestFailure($exception, $context->id);
        }
        $this->logger->audit('database.connection_test', 'success', 'INFO', ['component' => 'database']);
        (new OperationalLogger())->info('database', 'Database connection test successful', ['database_context' => $context->id]);
        return $context;
    }

    /** Safe failure for a connection test; a login timeout is reported separately. */
    private function connectionTestFailure(Throwable $exception, ?string $databaseId = null): ApiRequestException
    {
        $timeout = $exception instanceof DatabaseConnectionException && $exception->isTimeout();
        (new OperationalLogger())->error('database', 'Database connection test failed', array_filter([
            'database_context' => $databaseId,
            'error_code' => $timeout ? 'DATABASE_CONNECTION_TIMEOUT' : 'DATABASE_CONNECTION_FAILED',
        ]));
        $this->logger->audit('database.connection_test', 'failure', 'WARNING', [
            'reason' => $timeout ? 'connection_timeout' : 'connection_failed', 'component' => 'database',
        ]);
        return $timeout
            ? new ApiRequestException('Database connection timed out.', 'DATABASE_CONNECTION_TIMEOUT', [], 504)
            : new ApiRequestException('Database connection failed.', 'DATABASE_CONNECTION_FAILED', [], 422);
    }

    public function saveDatabase(array $database): array
    {
        $resolved = $this->withExistingPassword($database);
        $this->validateDatabaseAuthentication($resolved);
        if ($resolved['authentication'] === 'sql' && $resolved['password'] === '') {
            throw new ApiRequestException(
                'Invalid admin request.',
                'INVALID_ADMIN_REQUEST',
                [['path' => 'database.password', 'message' => 'Password is required for SQL authentication.']]
            );
        }
        try {
            // Encrypts, verifies, and saves; a V2 database.json is migrated
            // into the registry by this first write.
            $this->databaseRegistry->saveDefaultConnection($resolved);
        } catch (DatabaseCredentialException $exception) {
            $this->logger->audit('configuration.database', 'failure', 'ERROR', [
                'configurationCategory' => 'database', 'reason' => 'encryption_unavailable', 'component' => 'admin',
            ]);
            throw new ApiRequestException(
                'Database encryption is unavailable.',
                'DATABASE_ENCRYPTION_UNAVAILABLE',
                [],
                503
            );
        } catch (Throwable $exception) {
            $this->logger->audit('configuration.database', 'failure', 'ERROR', [
                'configurationCategory' => 'database', 'reason' => 'save_failed', 'component' => 'admin',
            ]);
            throw new ApiRequestException(
                'Unable to save database configuration.',
                'DATABASE_CONFIGURATION_SAVE_FAILED',
                [],
                500
            );
        }
        $this->logger->audit('configuration.database', 'success', 'NOTICE', [
            'configurationCategory' => 'database',
            'reason' => $resolved['password'] !== '' ? 'password_configured' : 'integrated_authentication',
            'component' => 'admin',
        ]);
        return ['configured' => true, 'encrypted' => true, 'passwordConfigured' => $resolved['password'] !== ''];
    }

    public function settings(): array
    {
        $settings = $this->configuration->load();
        $production = SecurityConfiguration::isProduction();
        return [
            'hostingMode' => $production ? 'production' : 'development',
            // Port ranges configure only the development launcher's managed
            // processes. Production listeners belong to the web server.
            'server' => $production ? null : $settings['server'],
            'cors' => $settings['cors'],
            'authentication' => [
                'mode' => $settings['authentication']['mode'],
                'apiKeyConfigured' => (new ApiKeyAuthenticator())->configured() || (new ApiKeyService())->configured(),
            ],
            'runtime' => $settings['runtime'],
            'backup' => $settings['backup'],
            'security' => [
                'csrfEnabled' => true,
                'session' => SecurityConfiguration::sessionOptions(),
            ],
            'advanced' => [
                'debugMode' => $this->runtimeDetector->information()['debugMode'],
                'logging' => 'server-managed',
            ],
        ];
    }

    public function saveServer(array $server): array
    {
        if (SecurityConfiguration::isProduction()) {
            // Saving development ranges cannot change IIS/Nginx bindings, so
            // reject instead of reporting a change that was never applied.
            $this->logger->audit('configuration.changed', 'failure', 'WARNING', [
                'configurationCategory' => 'server', 'reason' => 'deployment_managed', 'component' => 'admin',
            ]);
            throw new ApiRequestException(
                'Server listeners are managed by the production web server deployment.',
                'SERVER_CONFIGURATION_DEPLOYMENT_MANAGED',
                [],
                409
            );
        }
        $currentServer = $this->configuration->load()['server'];
        $runtime = $this->processManager->status();
        $parserRuntime = $this->parserProcessManager->status();
        if (($runtime['running'] ?? false) === true && ($runtime['port'] ?? null) === $server['adminPort']) {
            throw new ApiRequestException(
                'Invalid admin request.',
                'INVALID_ADMIN_REQUEST',
                [['path' => 'server.adminPort', 'message' => 'Admin port conflicts with the running API port.']]
            );
        }
        if (($parserRuntime['running'] ?? false) === true && ($parserRuntime['port'] ?? null) === $server['adminPort']) {
            throw new ApiRequestException(
                'Invalid admin request.',
                'INVALID_ADMIN_REQUEST',
                [['path' => 'server.adminPort', 'message' => 'Admin port conflicts with the running SQL Parser port.']]
            );
        }
        $apiRestartRequired = $server['apiPortMinimum'] !== $currentServer['apiPortMinimum']
            || $server['apiPortMaximum'] !== $currentServer['apiPortMaximum'];
        $parserRestartRequired = $server['parserPortMinimum'] !== $currentServer['parserPortMinimum']
            || $server['parserPortMaximum'] !== $currentServer['parserPortMaximum'];
        $adminRestartRequired = $server['adminPort'] !== $currentServer['adminPort'];
        $result = $this->configuration->update(function (array &$settings) use (
            $server,
            $apiRestartRequired,
            $parserRestartRequired,
            $adminRestartRequired
        ): array {
            $settings['server'] = $server;
            return [
                'server' => $server,
                'apiRestartRequired' => $apiRestartRequired,
                'parserRestartRequired' => $parserRestartRequired,
                'adminRestartRequired' => $adminRestartRequired,
            ];
        });
        $this->configurationAudit('server');
        return $result;
    }

    public function saveCors(array $cors): array
    {
        $result = $this->configuration->update(function (array &$settings) use ($cors): array {
            $settings['cors'] = $cors;
            return $cors;
        });
        $this->configurationAudit('cors');
        return $result;
    }

    public function saveAuthentication(string $mode): array
    {
        if ($mode === 'none' && !SecurityConfiguration::anonymousAuthenticationPermitted()) {
            throw new ApiRequestException('Invalid request.', 'INVALID_REQUEST', [[
                'path' => 'mode',
                'message' => 'Anonymous authentication is disabled in production. Set GENERIC_ALLOW_ANONYMOUS_PRODUCTION=true only for an approved exceptional deployment.',
            ]]);
        }
        $result = $this->configuration->update(function (array &$settings) use ($mode): array {
            $settings['authentication']['mode'] = $mode;
            return [
                'mode' => $mode,
                'apiKeyConfigured' => (new ApiKeyAuthenticator())->configured() || (new ApiKeyService())->configured(),
            ];
        });
        $this->configurationAudit('authentication');
        return $result;
    }

    public function saveRuntime(array $runtime): array
    {
        try {
            $result = $this->configuration->update(function (array &$settings) use ($runtime): array {
                $settings['runtime'] = $runtime;
                return $runtime;
            });
            $this->configurationAudit('runtime_security');
            return $result;
        } catch (Throwable $exception) {
            $this->logger->audit('configuration.changed', 'failure', 'ERROR', [
                'configurationCategory' => 'runtime_security', 'reason' => 'save_failed', 'component' => 'admin',
            ]);
            throw new ApiRequestException(
                'Unable to save runtime configuration.',
                'RUNTIME_CONFIGURATION_SAVE_FAILED',
                [],
                500
            );
        }
    }

    public function saveBackupSchedule(array $backup): array
    {
        try {
            $result = $this->configuration->update(function (array &$settings) use ($backup): array {
                $settings['backup'] = $backup;
                return $backup;
            });
            $this->configurationAudit('backup_schedule');
            return $result;
        } catch (Throwable $exception) {
            $this->logger->audit('configuration.changed', 'failure', 'ERROR', [
                'configurationCategory' => 'backup_schedule', 'reason' => 'save_failed', 'component' => 'admin',
            ]);
            throw new ApiRequestException('Unable to save backup schedule.', 'BACKUP_SCHEDULE_SAVE_FAILED', [], 500);
        }
    }

    public function controlApi(string $operation): array
    {
        if (SecurityConfiguration::isProduction()) {
            return $this->controlApplicationRuntime('api', $operation, 'API_RUNTIME_OPERATION_FAILED');
        }
        try {
            $result = $operation === 'start' ? $this->processManager->start()
                : ($operation === 'stop' ? $this->processManager->stop() : $this->processManager->restart());
            $this->runtimeAudit('api', $operation, 'success', $result);
            return $result;
        } catch (RuntimeException $exception) {
            $this->runtimeAudit('api', $operation, 'failure', [], 'operation_failed');
            throw new ApiRequestException($exception->getMessage(), 'API_PROCESS_OPERATION_FAILED', [], 409);
        }
    }

    public function controlSqlParser(string $operation): array
    {
        if (SecurityConfiguration::isProduction()) {
            return $this->controlApplicationRuntime(
                'sqlParser',
                $operation,
                'SQL_PARSER_RUNTIME_OPERATION_FAILED'
            );
        }
        try {
            $result = $operation === 'start' ? $this->parserProcessManager->start()
                : ($operation === 'stop' ? $this->parserProcessManager->stop() : $this->parserProcessManager->restart());
            $this->runtimeAudit('sql_parser', $operation, 'success', $result);
            return $result;
        } catch (RuntimeException $exception) {
            $this->runtimeAudit('sql_parser', $operation, 'failure', [], 'operation_failed');
            throw new ApiRequestException($exception->getMessage(), 'SQL_PARSER_PROCESS_OPERATION_FAILED', [], 409);
        }
    }

    /**
     * Database Connect/Disconnect/Restart control only the application's
     * request-availability gate. Connect enables access after one verified,
     * immediately closed test connection; requests still open their own
     * connections. SQL Server itself is never started or stopped.
     */
    public function controlDatabase(string $operation, ?string $databaseId = null): array
    {
        (new OperationalLogger())->info('database', 'Database runtime operation started', ['operation' => $operation]);
        $gateId = $this->availabilityGateId($databaseId);
        if ($operation === 'disconnect') {
            $result = $this->databaseAvailability->setAvailable(false, $gateId);
            $this->healthMonitor->forgetDatabaseHealth($gateId);
            $this->runtimeAudit('database', $operation, 'success', $result);
            (new OperationalLogger())->info('database', 'Database disconnect successful', ['database_context' => $gateId]);
            return [...$result, 'connected' => false, 'state' => 'disabled'];
        }
        if ($operation === 'restart') $this->databaseAvailability->setAvailable(false, $gateId);
        try {
            $context = $this->verifyDatabase($databaseId);
        } catch (Throwable $exception) {
            $this->databaseAvailability->setAvailable(false, $gateId);
            $this->healthMonitor->forgetDatabaseHealth($gateId);
            $reason = $this->databaseFailureReason($exception);
            $this->runtimeAudit('database', $operation, 'failure', [], $reason);
            (new OperationalLogger())->error('database', 'Database runtime operation failed', [
                'operation' => $operation,
                'database_context' => $gateId,
                'error_code' => $exception instanceof ApiRequestException ? $exception->getErrorCode() : 'DATABASE_UNAVAILABLE',
            ]);
            throw $exception;
        }
        $result = $this->databaseAvailability->setAvailable(true, $context->id);
        $this->healthMonitor->forgetDatabaseHealth($context->id);
        $this->runtimeAudit('database', $operation, 'success', $result);
        (new OperationalLogger())->info('database', 'Database runtime operation successful', [
            'operation' => $operation, 'database_context' => $context->id,
        ]);
        return [...$result, 'connected' => true, 'state' => 'connected'];
    }

    /**
     * The availability gate an operation changes: an explicit configured
     * database, else the default database (or "default" before one exists).
     */
    private function availabilityGateId(?string $databaseId): string
    {
        if ($databaseId === null) {
            return $this->databaseRegistry->defaultDatabaseId() ?? DatabaseRegistry::DEFAULT_ID;
        }
        try {
            $known = array_key_exists($databaseId, $this->databaseRegistry->metadata()['databases']);
        } catch (Throwable $exception) {
            throw $this->databaseConfigurationFailure();
        }
        if (!$known) {
            throw new ApiRequestException('Database not found.', 'DATABASE_NOT_FOUND', [
                ['path' => 'database', 'message' => 'The database is not configured.'],
            ], 404);
        }
        return $databaseId;
    }

    public function backupHistory(): array
    {
        return $this->backupRecovery->history();
    }

    public function backupScheduleInformation(): array
    {
        return $this->backupRecovery->scheduleInformation();
    }

    public function createBackup(): array
    {
        return $this->backupRecovery->create();
    }

    public function downloadBackup(string $recoveryPointId): array
    {
        return $this->backupRecovery->download($recoveryPointId);
    }

    public function previewBackupRestore(string $filename, string $archive): array
    {
        return $this->backupRecovery->previewUpload($filename, $archive);
    }

    public function restoreBackup(string $uploadToken, bool $confirmed): array
    {
        $result = $this->backupRecovery->restore($uploadToken, $confirmed);
        $this->healthMonitor->forgetDatabaseHealth();
        return $result;
    }

    public function recordFrontendOperationalEvent(array $event): array
    {
        (new OperationalLogger())->info('admin', $event['event'], array_filter([
            'page' => $event['page'] ?? null,
            'operation' => $event['operation'] ?? null,
            'error_code' => $event['errorCode'] ?? null,
            'browser_request_id' => $event['requestId'] ?? null,
        ], static fn ($value): bool => $value !== null));
        return ['recorded' => true];
    }

    private function configurationAudit(string $category): void
    {
        $this->logger->audit('configuration.changed', 'success', 'NOTICE', [
            'configurationCategory' => $category,
            'component' => 'admin',
        ]);
    }

    private function runtimeAudit(string $component, string $action, string $outcome, array $result = [], ?string $reason = null): void
    {
        $this->logger->audit('runtime.lifecycle', $outcome, $outcome === 'success' ? 'INFO' : 'ERROR', [
            'component' => $component,
            'action' => $action,
            'pid' => is_int($result['pid'] ?? null) ? $result['pid'] : null,
            'port' => is_int($result['port'] ?? null) ? $result['port'] : null,
            'reason' => $reason,
        ]);
    }

    private function backupHealth(): array
    {
        try {
            return $this->backupRecovery->health();
        } catch (Throwable $exception) {
            return ['status' => 'unhealthy', 'category' => 'storage_unavailable'];
        }
    }

    private function hostingInformation(bool $production): array
    {
        if (!$production) {
            return [
                'mode' => 'development',
                'webServer' => 'php-built-in',
                'listenerManagement' => 'development-launcher',
            ];
        }
        return [
            'mode' => 'production',
            'webServer' => $this->runtimeDetector->productionWebServer(),
            'listenerManagement' => 'web-server',
            'services' => [
                'adminConsole' => 'web-server-managed',
                'api' => 'web-server-managed',
                'sqlParser' => 'web-server-managed',
            ],
        ];
    }

    private function controlApplicationRuntime(string $service, string $operation, string $errorCode): array
    {
        $auditComponent = $service === 'api' ? 'api' : 'sql_parser';
        try {
            $result = $this->applicationRuntime->control($service, $operation);
            $this->runtimeAudit($auditComponent, $operation, 'success', $result);
            return $result;
        } catch (Throwable $exception) {
            $this->runtimeAudit($auditComponent, $operation, 'failure', [], 'operation_failed');
            throw new ApiRequestException(
                'Application runtime operation failed.',
                $errorCode,
                [],
                409
            );
        }
    }

    private function databaseStatus(): array
    {
        if ($this->databaseRegistry->source() === DatabaseRegistry::SOURCE_NONE) {
            return ['configured' => false, 'encrypted' => false, 'readable' => false];
        }
        try {
            $resolved = $this->databaseRegistry->connectionConfiguration();
            $this->databaseAuthentication->validate((string)($resolved['authentication'] ?? ''));
            return [
                'configured' => true,
                'encrypted' => $this->databaseRegistry->usesEncryption(),
                'readable' => true,
                'server' => (string)($resolved['server'] ?? ''),
                'port' => isset($resolved['port']) && $resolved['port'] !== '' ? (string)$resolved['port'] : null,
                'database' => (string)($resolved['database'] ?? ''),
            ];
        } catch (Throwable $exception) {
            return [
                'configured' => true,
                'encrypted' => $this->storedDatabaseIsEncrypted(),
                'readable' => false,
            ];
        }
    }

    private function databaseHealth(): array
    {
        $status = $this->databaseStatus();
        if (!$status['readable']) {
            return [...$status, 'connected' => false, 'healthy' => false, 'status' => 'not configured'];
        }
        try {
            ($this->connectionTester)($this->databaseRegistry->connectionConfiguration());
            return [...$status, 'connected' => true, 'healthy' => true, 'status' => 'connected'];
        } catch (Throwable $exception) {
            return [...$status, 'connected' => false, 'healthy' => false, 'status' => 'connection failed'];
        }
    }

    private function databaseConfigurationFailure(): ApiRequestException
    {
        $reason = $this->databaseConfigurationReason();
        (new OperationalLogger())->error('database', 'Database configuration load failed', [
            'error_code' => 'DATABASE_CONFIGURATION_UNAVAILABLE',
        ]);
        $this->logger->audit('database.connection_test', 'failure', 'WARNING', [
            'reason' => $reason, 'component' => 'database',
        ]);
        $messages = [
            'configuration_missing' => 'Database configuration has not been saved.',
            'encryption_key_missing' => 'The database encryption key is not available to this process.',
            'configuration_invalid' => 'Database configuration could not be decrypted or validated.',
        ];
        return new ApiRequestException(
            'Database configuration is unavailable.',
            'DATABASE_CONFIGURATION_UNAVAILABLE',
            [['path' => 'database', 'reason' => $reason, 'message' => $messages[$reason]]],
            503
        );
    }

    private function databaseConfigurationReason(): string
    {
        $state = $this->databaseRegistry->configurationState();
        return $state === 'configured' ? 'configuration_invalid' : $state;
    }

    private function databaseFailureReason(Throwable $exception): string
    {
        if (!$exception instanceof ApiRequestException) return 'connection_failed';
        if ($exception->getErrorCode() === 'DATABASE_CONFIGURATION_UNAVAILABLE') {
            return (string)($exception->getDetails()[0]['reason'] ?? 'configuration_invalid');
        }
        return $exception->getErrorCode() === 'INVALID_ADMIN_REQUEST' ? 'configuration_invalid' : 'connection_failed';
    }

    private function withExistingPassword(array $database): array
    {
        $database = $this->withExistingLoginTimeout($database);
        if ($database['password'] !== null && $database['password'] !== '') {
            return $database;
        }
        $database['password'] = '';
        if ($this->databaseRegistry->source() === DatabaseRegistry::SOURCE_NONE) return $database;
        try {
            $existing = $this->databaseRegistry->connectionConfiguration();
            $database['password'] = (string)($existing['password'] ?? '');
        } catch (Throwable $exception) {
            // Saving a replacement password remains possible when an old
            // encrypted file cannot be opened. Empty passwords fail validation.
        }
        return $database;
    }

    /** A form that omits the login timeout keeps the stored one. */
    private function withExistingLoginTimeout(array $database): array
    {
        if (array_key_exists('loginTimeoutSeconds', $database['options'] ?? [])
            || $this->databaseRegistry->source() === DatabaseRegistry::SOURCE_NONE) {
            return $database;
        }
        try {
            $existing = $this->databaseRegistry->connectionConfiguration()['options']['loginTimeoutSeconds'] ?? null;
        } catch (Throwable $exception) {
            return $database;
        }
        if (DatabaseServerProfile::isValidLoginTimeout($existing)) $database['options']['loginTimeoutSeconds'] = $existing;
        return $database;
    }

    private function storedDatabaseIsEncrypted(): bool
    {
        return $this->databaseRegistry->usesEncryption();
    }

    private function validateDatabaseAuthentication(array $database): void
    {
        try {
            $this->databaseAuthentication->validate((string)($database['authentication'] ?? ''));
        } catch (InvalidArgumentException $exception) {
            throw new ApiRequestException(
                'Invalid admin request.',
                'INVALID_ADMIN_REQUEST',
                [['path' => 'database.authentication', 'message' => $exception->getMessage()]]
            );
        }
    }
}
