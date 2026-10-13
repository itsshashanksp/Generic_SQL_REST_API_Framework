<?php

require_once __DIR__ . '/../RoleModel.php';
require_once __DIR__ . '/../../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../../Database/DatabaseRegistry.php';
require_once __DIR__ . '/../../Security/SecurityConfiguration.php';

/**
 * Validated `config/access-scopes.json`.
 *
 * A scope names, per logical database, the tables (and optionally columns),
 * SQL Resources (and their filterable columns), and metadata operations a
 * subject may use. Subjects are `frontend` (every principal with frontend
 * access) and authorization role ids. A scope is either restricted or the
 * explicit `{"unrestricted": true}`; nothing is unrestricted by omission.
 *
 * Missing file: enforced with no scopes in production (fail closed), log-only
 * elsewhere. Invalid file: enforced with no scopes everywhere.
 */
final class AccessScopeConfiguration
{
    public const MODE_LOG_ONLY = 'log-only';
    public const MODE_ENFORCE = 'enforce';
    public const STATE_CONFIGURED = 'configured';
    public const STATE_MISSING = 'missing';
    public const STATE_INVALID = 'invalid';
    public const FRONTEND_SUBJECT = 'frontend';

    /** Actions a restricted scope may grant; routines and writes need an unrestricted scope. */
    public const RESTRICTED_ACTIONS = ['select', 'union', 'unionAll', 'sql', 'metadata'];
    public const METADATA_OPERATIONS = ['tables', 'columns', 'views', 'procedures', 'schema'];

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const RESOURCE_ID = '/^[A-Za-z0-9][A-Za-z0-9_-]*(?:\/[A-Za-z0-9][A-Za-z0-9_-]*)*$/';
    private const SYSTEM_SCHEMAS = ['sys', 'information_schema'];

    /**
     * @param array<string, array> $scopes subject → normalized scope
     */
    private function __construct(
        public readonly string $mode,
        public readonly string $state,
        public readonly array $scopes,
        public readonly ?string $error = null
    ) {}

    public static function path(): string
    {
        return RuntimeConfiguration::path(RuntimeConfiguration::ACCESS_SCOPES_FILE);
    }

    public static function load(?string $path = null, ?bool $production = null): self
    {
        $path ??= self::path();
        $production ??= SecurityConfiguration::isProduction();
        if (!is_file($path)) {
            return new self($production ? self::MODE_ENFORCE : self::MODE_LOG_ONLY, self::STATE_MISSING, []);
        }
        $raw = @file_get_contents($path);
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($value)) {
            return self::invalid('Access scope configuration is not a JSON object.');
        }
        try {
            return self::fromArray($value);
        } catch (InvalidArgumentException $exception) {
            return self::invalid($exception->getMessage());
        }
    }

    /** @throws InvalidArgumentException for any malformed, unknown, or ambiguous rule */
    public static function fromArray(array $value): self
    {
        self::keys($value, ['version', 'mode', 'scopes'], ['version', 'mode', 'scopes'], 'access scopes');
        if ($value['version'] !== 1) self::fail('version must be 1.');
        if (!in_array($value['mode'], [self::MODE_LOG_ONLY, self::MODE_ENFORCE], true)) {
            self::fail('mode must be "log-only" or "enforce".');
        }
        if (!is_array($value['scopes']) || ($value['scopes'] !== [] && array_is_list($value['scopes']))) {
            self::fail('scopes must be an object.');
        }
        $subjects = array_merge([self::FRONTEND_SUBJECT], RoleModel::backendRoles(), RoleModel::frontendRoles(), RoleModel::apiKeyRoles());
        $scopes = [];
        foreach ($value['scopes'] as $subject => $scope) {
            if (!in_array($subject, $subjects, true)) self::fail("scopes.{$subject} is not a known subject.");
            $scopes[$subject] = self::scope($scope, "scopes.{$subject}");
        }
        return new self($value['mode'], self::STATE_CONFIGURED, $scopes);
    }

    public function enforced(): bool
    {
        return $this->mode === self::MODE_ENFORCE;
    }

    private static function invalid(string $error): self
    {
        return new self(self::MODE_ENFORCE, self::STATE_INVALID, [], $error);
    }

    private static function scope($scope, string $path): array
    {
        if (!is_array($scope) || array_is_list($scope)) self::fail("{$path} must be an object.");
        if (array_key_exists('unrestricted', $scope)) {
            if (array_keys($scope) !== ['unrestricted'] || $scope['unrestricted'] !== true) {
                self::fail("{$path} must be exactly {\"unrestricted\": true} or a restricted scope.");
            }
            return ['unrestricted' => true];
        }
        self::keys($scope, ['actions', 'databases', 'listDatabases'], ['actions', 'databases'], $path);
        $actions = self::uniqueList($scope['actions'], "{$path}.actions", fn ($action): bool => in_array($action, self::RESTRICTED_ACTIONS, true));
        if ($actions === []) self::fail("{$path}.actions must not be empty.");
        $listDatabases = $scope['listDatabases'] ?? false;
        if (!is_bool($listDatabases)) self::fail("{$path}.listDatabases must be a boolean.");
        if (!is_array($scope['databases']) || ($scope['databases'] !== [] && array_is_list($scope['databases']))) {
            self::fail("{$path}.databases must be an object.");
        }
        $databases = [];
        foreach ($scope['databases'] as $databaseId => $database) {
            if (!DatabaseRegistry::isValidId((string)$databaseId)) self::fail("{$path}.databases.{$databaseId} is not a logical database id.");
            $databases[$databaseId] = self::database($database, "{$path}.databases.{$databaseId}");
        }
        return ['unrestricted' => false, 'actions' => $actions, 'listDatabases' => $listDatabases, 'databases' => $databases];
    }

    private static function database($database, string $path): array
    {
        if (!is_array($database) || ($database !== [] && array_is_list($database))) self::fail("{$path} must be an object.");
        self::keys($database, ['tables', 'resources', 'metadata'], [], $path);
        $tables = [];
        foreach (self::object($database['tables'] ?? [], "{$path}.tables") as $name => $columns) {
            $parts = explode('.', (string)$name);
            if (count($parts) > 2 || array_filter($parts, fn ($part): bool => preg_match(self::IDENTIFIER, $part) !== 1)) {
                self::fail("{$path}.tables.{$name} must be Table or schema.Table.");
            }
            if (count($parts) === 2 && in_array(strtolower($parts[0]), self::SYSTEM_SCHEMAS, true)) {
                self::fail("{$path}.tables.{$name} must not be in a system schema.");
            }
            if ($columns === '*') {
                $tables[$name] = '*';
                continue;
            }
            $list = self::uniqueList($columns, "{$path}.tables.{$name}", fn ($column): bool => is_string($column) && preg_match(self::IDENTIFIER, $column) === 1);
            if ($list === []) self::fail("{$path}.tables.{$name} must be \"*\" or a non-empty column list.");
            $tables[$name] = $list;
        }
        $resources = [];
        foreach (self::object($database['resources'] ?? [], "{$path}.resources") as $id => $resource) {
            $id = (string)$id;
            $prefix = str_ends_with($id, '/*') ? substr($id, 0, -2) : $id;
            if (preg_match(self::RESOURCE_ID, $prefix) !== 1) self::fail("{$path}.resources.{$id} is not a resource id or dir/* pattern.");
            if (!is_array($resource) || array_is_list($resource) && $resource !== []) self::fail("{$path}.resources.{$id} must be an object.");
            self::keys($resource, ['filters'], ['filters'], "{$path}.resources.{$id}");
            $resources[$id] = [
                'filters' => self::uniqueList($resource['filters'], "{$path}.resources.{$id}.filters", fn ($column): bool => is_string($column) && preg_match(self::IDENTIFIER, $column) === 1),
            ];
        }
        $metadata = self::uniqueList($database['metadata'] ?? [], "{$path}.metadata", fn ($operation): bool => in_array($operation, self::METADATA_OPERATIONS, true));
        return ['tables' => $tables, 'resources' => $resources, 'metadata' => $metadata];
    }

    private static function object($value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) self::fail("{$path} must be an object.");
        return $value;
    }

    private static function uniqueList($value, string $path, callable $valid): array
    {
        if (!is_array($value) || !array_is_list($value)) self::fail("{$path} must be a list.");
        foreach ($value as $item) {
            if (!$valid($item)) self::fail("{$path} contains an invalid entry.");
        }
        if (count(array_unique($value)) !== count($value)) self::fail("{$path} contains duplicates.");
        return $value;
    }

    private static function keys(array $value, array $allowed, array $required, string $path): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) self::fail("{$path}.{$key} is not supported.");
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) self::fail("{$path}.{$key} is required.");
        }
    }

    private static function fail(string $message): never
    {
        throw new InvalidArgumentException('Invalid access scope configuration: ' . $message);
    }
}
