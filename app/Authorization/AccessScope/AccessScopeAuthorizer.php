<?php

require_once __DIR__ . '/AccessScopeConfiguration.php';
require_once __DIR__ . '/../Principal.php';
require_once __DIR__ . '/../../Requests/ApiRequestException.php';
require_once __DIR__ . '/../../../core/Logger.php';
require_once __DIR__ . '/../../../core/OperationalLogger.php';

/**
 * Decides whether one request stays inside the principal's access scopes.
 *
 * The candidate scopes are those of the principal's subjects (`frontend`
 * when it has frontend access, plus its roles). Every check keeps only the
 * candidates that allow it, so one scope must allow the whole request; grants
 * of different scopes are never combined. With no candidate left, enforce
 * mode denies before execution and log-only mode records the would-be denial
 * once and leaves the legacy response unchanged.
 *
 * Logs name the action, logical database, object identifiers, and reason —
 * never filter values, query text, credentials, or returned data.
 */
final class AccessScopeAuthorizer
{
    private const DATA_ACTIONS = [
        'select', 'union', 'unionAll', 'sql', 'function', 'tableFunction', 'procedure',
        'insert', 'update', 'delete', 'upsert',
    ];

    /** @var array<string, array> subject → scope still allowing everything checked so far */
    private array $candidates;
    private bool $recorded = false;

    public function __construct(
        private AccessScopeConfiguration $configuration,
        private Principal $principal,
        private ?Logger $logger = null,
        private ?OperationalLogger $operationalLogger = null
    ) {
        $subjects = $principal->roles();
        if ($principal->frontendAccess) array_unshift($subjects, AccessScopeConfiguration::FRONTEND_SUBJECT);
        $this->candidates = array_intersect_key($configuration->scopes, array_flip($subjects));
    }

    /** Whether an action reads or changes database content and is therefore scoped. */
    public static function appliesTo(string $action): bool
    {
        return in_array($action, self::DATA_ACTIONS, true) || str_starts_with($action, 'metadata.');
    }

    public function enforced(): bool
    {
        return $this->configuration->enforced();
    }

    /**
     * Phase 0, before the database availability gate: the action and the one
     * database that gate would examine, so an out-of-scope caller receives the
     * same denial whether that database is unregistered, disabled,
     * unavailable, or simply not granted.
     */
    public function authorizeAvailabilityTarget(string $action, ?string $databaseId): void
    {
        $this->authorizeAction($action);
        if ($databaseId !== null) $this->authorizeDatabases($action, [$databaseId]);
    }

    /** Configuration state, an applicable scope, and the action itself. */
    private function authorizeAction(string $action): void
    {
        if ($this->configuration->state === AccessScopeConfiguration::STATE_INVALID) {
            $this->deny('scope_configuration_invalid', $action);
        }
        if ($this->configuration->state === AccessScopeConfiguration::STATE_MISSING) {
            $this->deny('scope_configuration_missing', $action);
        }
        if ($this->candidates === []) $this->deny('no_applicable_scope', $action);
        $scopeAction = str_starts_with($action, 'metadata.') ? 'metadata' : $action;
        $this->require(
            fn (array $scope): bool => in_array($scopeAction, $scope['actions'], true),
            'action_not_in_scope', $action
        );
    }

    /**
     * Phase 1, before planning: configuration state, applicable scope, the
     * action, every database the request names, and (for `sql`) whether the
     * resource is in scope on any database — so an out-of-scope request never
     * reaches planning, which would reveal whether a database or resource exists.
     *
     * @param array $request the validated public request
     * @param list<string> $databaseIds logical databases the request names (or the default)
     */
    public function authorizeRequest(array $request, array $databaseIds): void
    {
        $action = (string)($request['action'] ?? '');
        $this->authorizeAction($action);
        if ($action === 'metadata.databases') {
            $this->require(fn (array $scope): bool => $scope['listDatabases'], 'metadata_not_in_scope', $action, operation: 'databases');
            return;
        }
        $this->authorizeDatabases($action, $databaseIds);
        if ($action === 'sql') {
            $resource = (string)($request['resource'] ?? '');
            $this->require(
                function (array $scope) use ($resource): bool {
                    foreach (array_keys($scope['databases']) as $databaseId) {
                        if (self::resourceRule($scope, (string)$databaseId, $resource) !== null) return true;
                    }
                    return false;
                },
                'resource_not_in_scope', 'sql', 'resource', $resource, $resource
            );
        }
    }

    /**
     * Phase 2, after planning and before any query runs: every planned
     * database (including SQL Resource placeholders), and on the primary
     * database the resource with its filter columns, or the metadata operation.
     *
     * @param list<string> $planDatabaseIds the plan's databases, primary first
     */
    public function authorizePlan(array $request, array $planDatabaseIds): void
    {
        $action = (string)($request['action'] ?? '');
        if ($action === 'metadata.databases') return;
        $this->authorizeDatabases($action, $planDatabaseIds);
        $primary = $planDatabaseIds[0] ?? null;
        if ($primary === null) return;
        if (str_starts_with($action, 'metadata.')) {
            $operation = substr($action, strlen('metadata.'));
            $this->require(
                fn (array $scope): bool => in_array($operation, $scope['databases'][$primary]['metadata'] ?? [], true),
                'metadata_not_in_scope', $action, 'database', $primary, operation: $operation
            );
        }
        if ($action === 'sql') $this->authorizeResource($request, $primary);
    }

    private function authorizeDatabases(string $action, array $databaseIds): void
    {
        foreach (array_unique($databaseIds) as $databaseId) {
            $this->require(
                fn (array $scope): bool => isset($scope['databases'][$databaseId]),
                'database_not_in_scope', $action, 'database', (string)$databaseId
            );
        }
    }

    /** A physical source of a structured query; `$schema` is null when the request named none. */
    public function authorizeTable(string $databaseId, ?string $schema, string $table): void
    {
        $this->require(
            fn (array $scope): bool => self::tableRule($scope, $databaseId, $schema, $table) !== null,
            'table_not_in_scope', 'select', 'table', self::tableKey($databaseId, $schema, $table)
        );
    }

    public function authorizeColumn(string $databaseId, ?string $schema, string $table, string $column): void
    {
        $this->require(
            function (array $scope) use ($databaseId, $schema, $table, $column): bool {
                $rule = self::tableRule($scope, $databaseId, $schema, $table);
                return $rule === '*' || (is_array($rule) && in_array($column, $rule, true));
            },
            'column_not_in_scope', 'select', 'column', self::tableKey($databaseId, $schema, $table) . '.' . $column
        );
    }

    /** `*` projections: allowed only where every column of the table is in scope. */
    public function authorizeAllColumns(string $databaseId, ?string $schema, string $table): void
    {
        $this->require(
            fn (array $scope): bool => self::tableRule($scope, $databaseId, $schema, $table) === '*',
            'wildcard_not_in_scope', 'select', 'table', self::tableKey($databaseId, $schema, $table)
        );
    }

    private function authorizeResource(array $request, string $databaseId): void
    {
        $resource = (string)($request['resource'] ?? '');
        $this->require(
            fn (array $scope): bool => self::resourceRule($scope, $databaseId, $resource) !== null,
            'resource_not_in_scope', 'sql', 'resource', $resource, $resource
        );
        $fields = [];
        foreach ($request['filters'] ?? [] as $filter) {
            if (is_array($filter) && is_string($filter['field'] ?? null)) $fields[] = $filter['field'];
        }
        foreach ($request['execution']['filters'] ?? [] as $field => $mapping) {
            $fields[] = (string)$field;
            // A client-supplied expression could point an allowed name at
            // another column, so restricted scopes accept none.
            if (is_array($mapping) && (isset($mapping['expression']) || ($mapping['placement'] ?? null) === 'having')) {
                $this->require(fn (array $scope): bool => false, 'filter_expression_not_in_scope', 'sql', 'filter', (string)$field, $resource);
            }
        }
        foreach (array_unique($fields) as $field) {
            $this->require(
                fn (array $scope): bool => in_array($field, self::resourceRule($scope, $databaseId, $resource)['filters'] ?? [], true),
                'filter_not_in_scope', 'sql', 'filter', $field, $resource
            );
        }
    }

    private static function tableRule(array $scope, string $databaseId, ?string $schema, string $table): string|array|null
    {
        $tables = $scope['databases'][$databaseId]['tables'] ?? [];
        return $tables[$schema === null ? $table : "{$schema}.{$table}"] ?? null;
    }

    /** An exact resource entry wins over its `dir/*` pattern; `*` matches one final segment. */
    private static function resourceRule(array $scope, string $databaseId, string $resource): ?array
    {
        $resources = $scope['databases'][$databaseId]['resources'] ?? [];
        if (isset($resources[$resource])) return $resources[$resource];
        $separator = strrpos($resource, '/');
        if ($separator === false) return null;
        return $resources[substr($resource, 0, $separator) . '/*'] ?? null;
    }

    private static function tableKey(string $databaseId, ?string $schema, string $table): string
    {
        return $databaseId . ':' . ($schema === null ? '' : $schema . '.') . $table;
    }

    /** Keeps the candidates allowing a check; unrestricted scopes allow every check. */
    private function require(
        callable $allows,
        string $reason,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $resource = null,
        ?string $operation = null
    ): void {
        $remaining = array_filter(
            $this->candidates,
            fn (array $scope): bool => $scope['unrestricted'] || $allows($scope)
        );
        if ($remaining !== []) {
            $this->candidates = $remaining;
            return;
        }
        $this->deny($reason, $action, $targetType, $targetId, $resource, $operation);
    }

    private function deny(
        string $reason,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $resource = null,
        ?string $operation = null
    ): void {
        $enforced = $this->configuration->enforced();
        if ($enforced || !$this->recorded) {
            $this->recorded = true;
            $context = array_filter([
                'component' => 'authorization',
                'status' => $enforced ? 'enforced' : 'log-only',
                'errorCode' => 'ACCESS_SCOPE_DENIED',
                'reason' => $reason,
                'action' => $action,
                'targetType' => $targetType,
                'targetId' => $targetId,
                'resource' => $resource,
                'operation' => $operation,
                'check' => $this->configuration->state,
            ], fn ($value): bool => $value !== null);
            try {
                ($this->operationalLogger ?? new OperationalLogger())->warning(
                    'api',
                    $enforced ? 'Access scope denied' : 'Access scope would deny',
                    $context
                );
                ($this->logger ?? new Logger())->audit(
                    $enforced ? 'authorization.scope_denied' : 'authorization.scope_would_deny',
                    $enforced ? 'denied' : 'logged',
                    'NOTICE',
                    $context
                );
            } catch (Throwable $loggingFailure) {
                // Logging must never change the authorization decision.
            }
        }
        if ($enforced) {
            throw new ApiRequestException('Authorization denied.', 'AUTHORIZATION_DENIED', [], 403);
        }
    }
}
