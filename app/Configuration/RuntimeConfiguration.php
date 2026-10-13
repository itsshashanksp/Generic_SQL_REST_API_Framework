<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/JsonFileStore.php';
require_once __DIR__ . '/RuntimeControls.php';
require_once __DIR__ . '/../Backup/BackupSchedule.php';

final class RuntimeConfiguration
{
    public const AUTH_FILE = 'auth.json';
    public const INSTALLATION_FILE = 'installation.json';
    public const ADMIN_FILE = 'admin.json';
    public const AUTHORIZATION_FILE = 'authorization.json';
    public const API_KEYS_FILE = 'api-keys.json';
    public const DATABASE_STATE_FILE = 'database-state.json';
    public const APPLICATION_RUNTIME_STATE_FILE = 'application-runtime-state.json';
    public const ACCESS_SCOPES_FILE = 'access-scopes.json';

    public static function directory(): string
    {
        $override = getenv('GENERIC_RUNTIME_CONFIG_DIR');
        return $override !== false && trim($override) !== ''
            ? rtrim(trim($override), '/\\')
            : ROOT_PATH . '/config';
    }

    public static function path(string $file): string
    {
        if (!in_array($file, [self::AUTH_FILE, self::INSTALLATION_FILE, self::ADMIN_FILE,
            self::AUTHORIZATION_FILE, self::API_KEYS_FILE, self::DATABASE_STATE_FILE,
            self::APPLICATION_RUNTIME_STATE_FILE, self::ACCESS_SCOPES_FILE], true)) {
            throw new InvalidArgumentException('Unsupported runtime configuration file.');
        }
        return self::directory() . DIRECTORY_SEPARATOR . $file;
    }

    public static function ensure(): array
    {
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Runtime configuration directory is unavailable.');
        }
        @chmod($directory, 0700);

        $lockPath = $directory . DIRECTORY_SEPARATOR . '.bootstrap.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) throw new RuntimeException('Runtime configuration bootstrap lock is unavailable.');
        @chmod($lockPath, 0600);

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Runtime configuration bootstrap lock could not be acquired.');
            }
            $created = [];
            foreach (self::defaults() as $file => $configuration) {
                $path = $directory . DIRECTORY_SEPARATOR . $file;
                if (is_file($path)) continue;
                JsonFileStore::save($path, $configuration);
                $created[] = $file;
            }
            return ['directory' => $directory, 'created' => $created];
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function authDefaults(): array
    {
        return ['version' => 4, 'users' => []];
    }

    public static function authorizationDefaults(): array
    {
        return [
            'version' => 4,
            // Anonymous mode grants nothing unless a deployment assigns roles.
            'publicRoles' => [],
            'legacyApiKeyRoles' => ['read-only'],
            'roles' => [
                'read-only' => ['name' => 'Read Only', 'domain' => 'backend', 'permissions' => ['data.read', 'metadata.read', 'sql.execute', 'routine.execute']],
                'data-operator' => ['name' => 'Data Operator', 'domain' => 'backend', 'permissions' => ['data.read', 'data.write', 'metadata.read', 'sql.execute', 'routine.execute']],
                'api-administrator' => ['name' => 'Admin', 'domain' => 'backend', 'permissions' => ['data.read', 'data.write', 'metadata.read', 'sql.execute', 'routine.execute']],
                'system-administrator' => ['name' => 'System Administrator', 'domain' => 'backend', 'permissions' => ['admin.manage', 'frontend.users.manage', 'data.read', 'data.write', 'metadata.read', 'sql.execute', 'routine.execute']],
                'application-administrator' => ['name' => 'Application Administrator', 'domain' => 'frontend', 'permissions' => ['frontend.read', 'frontend.users.manage']],
            ],
        ];
    }

    public static function installationDefaults(): array
    {
        return [
            'version' => 1,
            'installationId' => bin2hex(random_bytes(32)),
            'initialized' => false,
        ];
    }

    public static function adminDefaults(): array
    {
        return [
            'version' => 6,
            'server' => [
                'apiPortMinimum' => 8000,
                'apiPortMaximum' => 8100,
                'parserPortMinimum' => 8101,
                'parserPortMaximum' => 8199,
                'adminPort' => 8090,
                'bindAddress' => '127.0.0.1',
            ],
            'cors' => [
                'allowedOrigins' => [
                    'http://127.0.0.1:5173',
                    'http://localhost:5173',
                    'http://127.0.0.1:5314',
                    'http://localhost:5314',
                    'http://127.0.0.1:5341',
                    'http://localhost:5341',
                ],
                'credentialsEnabled' => true,
                'allowedMethods' => ['POST', 'OPTIONS'],
            ],
            'authentication' => ['mode' => 'session'],
            'runtime' => RuntimeControls::defaults(),
            'backup' => BackupSchedule::defaults(),
        ];
    }

    private static function defaults(): array
    {
        return [
            self::AUTH_FILE => self::authDefaults(),
            self::INSTALLATION_FILE => self::installationDefaults(),
            self::ADMIN_FILE => self::adminDefaults(),
            self::AUTHORIZATION_FILE => self::authorizationDefaults(),
            self::API_KEYS_FILE => ['version' => 3, 'keys' => []],
            // Per-database availability; every database starts disconnected.
            self::DATABASE_STATE_FILE => ['version' => 2, 'databases' => new stdClass()],
            self::APPLICATION_RUNTIME_STATE_FILE => [
                'version' => 1,
                'generation' => 0,
                'services' => [
                    'api' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
                    'sqlParser' => ['enabled' => true, 'updatedAt' => null, 'reloadedAt' => null],
                ],
            ],
        ];
    }
}
