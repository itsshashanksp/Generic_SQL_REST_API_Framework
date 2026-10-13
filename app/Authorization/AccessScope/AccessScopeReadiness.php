<?php

require_once __DIR__ . '/AccessScopeConfiguration.php';

/**
 * Whether a valid access scope configuration is ready for production
 * enforcement. A valid file can still be incomplete (it names a database or
 * SQL Resource that does not exist) or broad (grants that need an explicit
 * owner decision). Read-only: the caller supplies the registry, resource, and
 * principal facts.
 */
final class AccessScopeReadiness
{
    /**
     * @param list<string> $databaseIds registered logical database ids
     * @param list<string> $resourceIds discovered SQL Resource ids
     * @param array<string, int> $subjectsInUse subject → enabled principals relying on it
     * @return array{blocking: list<string>, review: list<string>}
     *         blocking: must be fixed before enforcement; review: needs an explicit owner decision
     */
    public static function review(AccessScopeConfiguration $configuration, array $databaseIds, array $resourceIds, array $subjectsInUse): array
    {
        $blocking = [];
        $review = [];
        if ($configuration->state !== AccessScopeConfiguration::STATE_CONFIGURED) {
            $blocking[] = "configuration is {$configuration->state}";
            return ['blocking' => $blocking, 'review' => $review];
        }
        foreach ($configuration->scopes as $subject => $scope) {
            if ($scope['unrestricted']) {
                $review[] = "scopes.{$subject}: unrestricted (every database, table, column, routine, and write)";
                continue;
            }
            if ($scope['listDatabases']) $review[] = "scopes.{$subject}.listDatabases: lists every registered database";
            foreach ($scope['databases'] as $databaseId => $database) {
                $path = "scopes.{$subject}.databases.{$databaseId}";
                if (!in_array($databaseId, $databaseIds, true)) $blocking[] = "{$path}: not a registered database";
                foreach ($database['tables'] as $table => $columns) {
                    if ($columns === '*') $review[] = "{$path}.tables.{$table}: every column, including columns added later";
                }
                foreach ($database['resources'] as $resource => $rule) {
                    if (str_ends_with($resource, '/*')) {
                        $prefix = substr($resource, 0, -1);
                        $matches = array_filter($resourceIds, fn (string $id): bool => str_starts_with($id, $prefix) && !str_contains(substr($id, strlen($prefix)), '/'));
                        if ($matches === []) $blocking[] = "{$path}.resources.{$resource}: matches no SQL Resource";
                        else $review[] = "{$path}.resources.{$resource}: every current and future SQL Resource in " . rtrim($prefix, '/');
                    } elseif (!in_array($resource, $resourceIds, true)) {
                        $blocking[] = "{$path}.resources.{$resource}: no such SQL Resource";
                    }
                    if ($rule['filters'] !== []) {
                        $review[] = "{$path}.resources.{$resource}.filters: confirm each column may be filtered (" . implode(', ', $rule['filters']) . ')';
                    }
                }
                if ($database['metadata'] !== []) {
                    $review[] = "{$path}.metadata: lists every object for " . implode(', ', $database['metadata']);
                }
            }
        }
        foreach ($subjectsInUse as $subject => $count) {
            if ($count > 0 && !isset($configuration->scopes[$subject])) {
                $review[] = "{$subject}: {$count} enabled principal(s) rely on it but it has no scope; they are denied when enforced";
            }
        }
        return ['blocking' => $blocking, 'review' => $review];
    }
}
