<?php

require_once __DIR__ . '/../../Database/DatabaseReferenceCollector.php';
require_once __DIR__ . '/../../Database/DatabaseContextResolver.php';

/**
 * The logical databases a validated request names, without reading SQL
 * Resource files or the database: a SQL Resource's placeholder databases are
 * known only after the resource itself was authorized and planned.
 */
final class AccessScopeRequestDatabases
{
    /** @return list<string> primary (explicit or default) first */
    public static function named(array $request, ?DatabaseContextResolver $resolver = null): array
    {
        $action = (string)($request['action'] ?? '');
        $explicit = is_string($request['database'] ?? null) ? $request['database'] : null;
        if ($action === 'sql') {
            return $explicit === null ? [] : [$explicit];
        }
        $ids = [];
        $primary = $explicit;
        if (!str_starts_with($action, 'metadata.')) {
            $references = (new DatabaseReferenceCollector())->collect($request);
            $primary = $references->explicitPrimary()?->id;
            $ids = $references->ids();
        }
        $primary ??= ($resolver ?? new DatabaseContextResolver())->defaultDatabaseId();
        return array_values(array_unique(array_filter([$primary, ...$ids], fn ($id): bool => is_string($id))));
    }
}
