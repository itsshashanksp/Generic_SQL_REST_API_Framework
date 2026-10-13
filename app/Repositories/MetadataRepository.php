<?php

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../core/QueryEngine.php';
require_once __DIR__ . '/../Database/QualifiedObject.php';

class MetadataRepository
{
    private QueryEngine $queryEngine;

    public function __construct(?QueryEngine $queryEngine = null)
    {
        $this->queryEngine = $queryEngine ?? new QueryEngine();
    }

    /**
     * Get Tables
     */
    public function getTables()
    {
        return $this->queryEngine->executeFile(
            QUERY_PATH . '/system/Tables.sql'
        );
    }

    /**
     * Get Columns
     */
    public function getColumns($tableName)
    {
        $sql = $this->queryEngine->getQuery(
            QUERY_PATH . '/system/Columns.sql'
        );

        return $this->queryEngine->executePrepared(
            $sql,
            [$tableName],
            ['queryPhase' => 'metadata']
        );
    }

    /**
     * Get Views
     */
    public function getViews()
    {
        return $this->queryEngine->executeFile(
            QUERY_PATH . '/system/Views.sql'
        );
    }

    /**
     * Get Stored Procedures
     */
    public function getProcedures()
    {
        return $this->queryEngine->executeFile(
            QUERY_PATH . '/system/Procedures.sql'
        );
    }

    /**
     * Check Table Exists
     */
    public function tableExists($table)
    {
        $sql = "
            SELECT
                COUNT(*) AS Total
            FROM
                INFORMATION_SCHEMA.TABLES
            WHERE
                TABLE_NAME = ?
        ";

        $result = $this->queryEngine->executePrepared(
            $sql,
            [$table],
            ['queryPhase' => 'metadata']
        );

        return (
            ($result["data"][0]["Total"] ?? 0) > 0
        );
    }

    /**
     * Structured lookups for a physical object. A schema-less object of the
     * connected database uses the name-based lookups above. Otherwise the
     * schema (when given) must match too, and an object of another database
     * on the same server profile is read from that database's catalog views
     * (`[Database].INFORMATION_SCHEMA`), on this connection. The database
     * part is a registry identifier rendered by QualifiedObject's rules;
     * schema, object, and column names are bound values.
     */
    public function objectExists(QualifiedObject $object): bool
    {
        if ($this->isLocalUnqualified($object)) return $this->tableExists($object->objectName());
        [$where, $params] = $this->objectPredicate($object);
        $result = $this->queryEngine->executePrepared(
            'SELECT COUNT(*) AS Total FROM ' . $this->catalog($object) . 'INFORMATION_SCHEMA.TABLES WHERE ' . $where,
            $params,
            ['queryPhase' => 'metadata']
        );
        return ($result['data'][0]['Total'] ?? 0) > 0;
    }

    public function objectColumnExists(QualifiedObject $object, $column): bool
    {
        if ($this->isLocalUnqualified($object)) return $this->columnExists($object->objectName(), $column);
        return $this->objectColumnDataType($object, $column) !== null;
    }

    public function objectColumnDataType(QualifiedObject $object, $column)
    {
        if ($this->isLocalUnqualified($object)) return $this->getColumnDataType($object->objectName(), $column);
        [$where, $params] = $this->objectPredicate($object);
        $result = $this->queryEngine->executePrepared(
            'SELECT DATA_TYPE FROM ' . $this->catalog($object) . 'INFORMATION_SCHEMA.COLUMNS WHERE ' . $where . ' AND COLUMN_NAME = ?',
            [...$params, $column],
            ['queryPhase' => 'metadata']
        );
        return $result['data'][0]['DATA_TYPE'] ?? null;
    }

    public function objectColumns(QualifiedObject $object)
    {
        if ($this->isLocalUnqualified($object)) return $this->getColumns($object->objectName());
        [$where, $params] = $this->objectPredicate($object);
        return $this->queryEngine->executePrepared(
            'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM ' . $this->catalog($object) . 'INFORMATION_SCHEMA.COLUMNS'
                . ' WHERE ' . $where . ' ORDER BY ORDINAL_POSITION',
            $params,
            ['queryPhase' => 'metadata']
        );
    }

    private function isLocal(QualifiedObject $object): bool
    {
        return $this->queryEngine->databaseContextId() === $object->databaseId;
    }

    private function isLocalUnqualified(QualifiedObject $object): bool
    {
        return $object->schemaName() === null && $this->isLocal($object);
    }

    /** `` for the connected database, else `[Database].`; never other text. */
    private function catalog(QualifiedObject $object): string
    {
        return $this->isLocal($object) ? '' : MssqlIdentifier::database($object->databaseName())->quoted() . '.';
    }

    private function objectPredicate(QualifiedObject $object): array
    {
        return $object->schemaName() === null
            ? ['TABLE_NAME = ?', [$object->objectName()]]
            : ['TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$object->schemaName(), $object->objectName()]];
    }

    /**
     * Check Column Exists
     */
    public function columnExists($table, $column)
    {
        $sql = "
            SELECT
                COUNT(*) AS Total
            FROM
                INFORMATION_SCHEMA.COLUMNS
            WHERE
                TABLE_NAME = ?
            AND
                COLUMN_NAME = ?
        ";

        $result = $this->queryEngine->executePrepared(
            $sql,
            [
                $table,
                $column
            ],
            ['queryPhase' => 'metadata']
        );

        return (
            ($result["data"][0]["Total"] ?? 0) > 0
        );
    }

    /**
     * Get Column Data Type
     *
     * Returns the SQL Server DATA_TYPE for
     * the specified table column.
     */
    public function getColumnDataType(
        $table,
        $column
    ) {
        $sql = "
            SELECT
                DATA_TYPE
            FROM
                INFORMATION_SCHEMA.COLUMNS
            WHERE
                TABLE_NAME = ?
            AND
                COLUMN_NAME = ?
        ";

        $result = $this->queryEngine->executePrepared(
            $sql,
            [
                $table,
                $column
            ],
            ['queryPhase' => 'metadata']
        );

        return $result["data"][0]["DATA_TYPE"]
            ?? null;
    }

    /**
     * Return SQL Server column properties required to validate safe writes.
     */
    public function getWriteColumns(string $schema, string $table): array
    {
        $sql = "
            SELECT
                c.name AS ColumnName,
                ty.name AS DataType,
                c.max_length AS MaxLength,
                c.precision AS NumericPrecision,
                c.scale AS NumericScale,
                c.is_nullable AS IsNullable,
                c.is_identity AS IsIdentity,
                c.is_computed AS IsComputed,
                c.generated_always_type AS GeneratedAlwaysType,
                c.is_hidden AS IsHidden,
                CASE WHEN c.default_object_id = 0 THEN 0 ELSE 1 END AS HasDefault
            FROM sys.columns c
            INNER JOIN sys.tables t ON t.object_id = c.object_id
            INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
            INNER JOIN sys.types ty ON ty.user_type_id = c.user_type_id
            WHERE s.name = ? AND t.name = ?
            ORDER BY c.column_id
        ";

        return $this->queryEngine->executePrepared(
            $sql,
            [$schema, $table],
            ['queryPhase' => 'metadata', 'action' => 'write']
        );
    }

    /**
     * Return the type and declared parameter count of a user-defined routine in
     * the configured database, or null when it does not exist. System routines
     * are not listed by INFORMATION_SCHEMA.ROUTINES.
     */
    public function getRoutine(string $schema, string $name): ?array
    {
        $sql = "
            SELECT
                r.ROUTINE_TYPE AS RoutineType,
                r.DATA_TYPE AS DataType,
                (
                    SELECT COUNT(*)
                    FROM INFORMATION_SCHEMA.PARAMETERS p
                    WHERE p.SPECIFIC_SCHEMA = r.SPECIFIC_SCHEMA
                        AND p.SPECIFIC_NAME = r.SPECIFIC_NAME
                        AND p.ORDINAL_POSITION > 0
                ) AS ParameterCount
            FROM INFORMATION_SCHEMA.ROUTINES r
            WHERE r.ROUTINE_SCHEMA = ? AND r.ROUTINE_NAME = ?
        ";
        $result = $this->queryEngine->executePrepared(
            $sql,
            [$schema, $name],
            ['queryPhase' => 'metadata', 'action' => 'routine']
        );
        $row = $result['data'][0] ?? null;
        if (!is_array($row)) return null;
        $routineType = strtoupper((string)$this->metadataValue($row, 'RoutineType'));
        $type = match (true) {
            $routineType === 'PROCEDURE' => 'procedure',
            $routineType === 'FUNCTION' && strtoupper((string)$this->metadataValue($row, 'DataType')) === 'TABLE' => 'tableFunction',
            $routineType === 'FUNCTION' => 'function',
            default => null,
        };
        return $type === null ? null : ['type' => $type, 'parameters' => (int)$this->metadataValue($row, 'ParameterCount')];
    }

    public function hasUniqueKey(string $schema, string $table, array $columns): bool
    {
        $sql = "
            SELECT
                i.name AS IndexName,
                c.name AS ColumnName,
                ic.key_ordinal AS KeyOrdinal
            FROM sys.indexes i
            INNER JOIN sys.tables t ON t.object_id = i.object_id
            INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
            INNER JOIN sys.index_columns ic
                ON ic.object_id = i.object_id AND ic.index_id = i.index_id
            INNER JOIN sys.columns c
                ON c.object_id = ic.object_id AND c.column_id = ic.column_id
            WHERE s.name = ? AND t.name = ?
                AND i.is_unique = 1 AND i.is_hypothetical = 0
                AND i.has_filter = 0 AND ic.key_ordinal > 0
            ORDER BY i.index_id, ic.key_ordinal
        ";
        $result = $this->queryEngine->executePrepared(
            $sql,
            [$schema, $table],
            ['queryPhase' => 'metadata', 'action' => 'upsert']
        );
        $indexes = [];
        foreach ($result['data'] ?? [] as $row) {
            $index = $this->metadataValue($row, 'IndexName');
            $column = $this->metadataValue($row, 'ColumnName');
            if (is_string($index) && is_string($column)) {
                $indexes[$index][] = strtolower($column);
            }
        }
        $expected = array_map('strtolower', $columns);
        sort($expected);
        foreach ($indexes as $indexColumns) {
            sort($indexColumns);
            if ($indexColumns === $expected) return true;
        }
        return false;
    }

    private function metadataValue(array $row, string $key)
    {
        foreach ($row as $name => $value) {
            if (strcasecmp((string)$name, $key) === 0) return $value;
        }
        return null;
    }

    /**
     * Called before a `*` projection of a table is rendered. A plain catalog
     * repository has nothing to check; access-scope decorators override it.
     */
    public function assertAllColumnsReadable($table): void
    {
    }

    public function assertAllObjectColumnsReadable(QualifiedObject $object): void
    {
    }

    /**
     * Get Schema
     */
    public function schema()
    {
        return $this->queryEngine->executeFile(
            QUERY_PATH . '/system/Schema.sql'
        );
    }
}
