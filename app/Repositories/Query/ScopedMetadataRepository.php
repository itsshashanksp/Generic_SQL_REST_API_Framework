<?php

require_once __DIR__ . '/../MetadataRepository.php';

/**
 * Adds request-local CTE output metadata without weakening physical-table
 * validation: names that are not CTEs of this request are checked against the
 * database catalog by the delegate. Source names resolved to physical objects
 * are looked up by object (schema-aware); other names by name, as before.
 */
class ScopedMetadataRepository extends MetadataRepository
{
    private MetadataRepository $delegate;
    private array $virtualTables = [];
    /** @var array<string, QualifiedObject> lower-case source name or reference → object */
    private array $physicalSources = [];
    private ?string $primaryDatabaseId = null;

    public function __construct(MetadataRepository $delegate)
    {
        $this->delegate = $delegate;
    }

    public function setVirtualTables(array $tables): void
    {
        $this->virtualTables = $tables;
    }

    public function getVirtualTables(): array
    {
        return $this->virtualTables;
    }

    /**
     * @param array<string, QualifiedObject> $sources lower-case source name or reference → object
     * @param ?string $primaryDatabaseId the connected (primary) database of the plan
     */
    public function setPhysicalSources(array $sources, ?string $primaryDatabaseId = null): void
    {
        $this->physicalSources = $sources;
        $this->primaryDatabaseId = $primaryDatabaseId;
    }

    public function getPhysicalSources(): array
    {
        return $this->physicalSources;
    }

    public function tableExists($table)
    {
        if ($this->findVirtualTable((string)$table) !== null) {
            return true;
        }
        $object = $this->physicalSource($table);
        if ($object === null) return $this->delegate->tableExists($this->tableName($table));
        return $this->delegate->objectExists($object);
    }

    public function columnExists($table, $column)
    {
        $virtualTable = $this->findVirtualTable((string)$table);
        if ($virtualTable === null) {
            $object = $this->physicalSource($table);
            return $object !== null
                ? $this->delegate->objectColumnExists($object, $column)
                : $this->delegate->columnExists($this->tableName($table), $column);
        }
        foreach ($this->virtualTables[$virtualTable] as $virtualColumn) {
            if (strcasecmp($virtualColumn, (string)$column) === 0) {
                return true;
            }
        }
        return false;
    }

    public function getColumnDataType($table, $column)
    {
        if ($this->findVirtualTable((string)$table) !== null) {
            return null;
        }
        $object = $this->physicalSource($table);
        return $object !== null
            ? $this->delegate->objectColumnDataType($object, $column)
            : $this->delegate->getColumnDataType($this->tableName($table), $column);
    }

    public function getColumns($table)
    {
        $virtualTable = $this->findVirtualTable((string)$table);
        if ($virtualTable === null) {
            $object = $this->physicalSource($table);
            return $object !== null ? $this->delegate->objectColumns($object) : $this->delegate->getColumns($this->tableName($table));
        }
        return [
            'data' => array_map(
                fn (string $column): array => ['COLUMN_NAME' => $column],
                $this->virtualTables[$virtualTable]
            ),
        ];
    }

    /** CTE outputs come from an already-checked body; physical sources are forwarded. */
    public function assertAllColumnsReadable($table): void
    {
        if ($this->findVirtualTable((string)$table) !== null) return;
        $object = $this->physicalSource($table);
        if ($object !== null) {
            $this->delegate->assertAllObjectColumnsReadable($object);
            return;
        }
        $this->delegate->assertAllColumnsReadable($this->tableName($table));
    }

    /**
     * The object a source key needs an object lookup for: schema-qualified
     * sources and sources of another planned database. Sources of the
     * connected database without a schema keep V2's name lookups.
     */
    private function physicalSource($table): ?QualifiedObject
    {
        $object = $this->physicalSources[strtolower((string)$table)] ?? null;
        if ($object === null) return null;
        return $object->schemaName() !== null || $object->databaseId !== $this->primaryDatabaseId ? $object : null;
    }

    /** The table name for a name lookup: a source alias key maps to its object's name. */
    private function tableName($table)
    {
        $object = $this->physicalSources[strtolower((string)$table)] ?? null;
        return $object === null ? $table : $object->objectName();
    }

    private function findVirtualTable(string $table): ?string
    {
        foreach (array_keys($this->virtualTables) as $candidate) {
            if (strcasecmp($candidate, $table) === 0) {
                return $candidate;
            }
        }
        return null;
    }
}
