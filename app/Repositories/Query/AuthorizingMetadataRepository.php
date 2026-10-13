<?php

require_once __DIR__ . '/../MetadataRepository.php';
require_once __DIR__ . '/../../Authorization/AccessScope/AccessScopeAuthorizer.php';

/**
 * Applies access scopes to the catalog lookups that structured-query builders
 * make for every source table and every column they render (projections,
 * function arguments, expressions, CASE, predicates, joins, grouping, HAVING,
 * sorting, windows, subqueries, CTE bodies, and set-operation branches).
 *
 * The scope check runs before the catalog is consulted, so an out-of-scope
 * object is denied without revealing whether it exists. Names arrive already
 * resolved by ScopedMetadataRepository: request aliases map to their table,
 * CTE names never reach this class, and a name without a schema belongs to
 * the plan's primary database.
 */
class AuthorizingMetadataRepository extends MetadataRepository
{
    public function __construct(
        private MetadataRepository $delegate,
        private AccessScopeAuthorizer $authorizer,
        private string $primaryDatabaseId
    ) {}

    public function tableExists($table)
    {
        $this->authorizer->authorizeTable($this->primaryDatabaseId, null, (string)$table);
        return $this->delegate->tableExists($table);
    }

    public function objectExists(QualifiedObject $object): bool
    {
        $this->authorizer->authorizeTable($object->databaseId, $object->schemaName(), $object->objectName());
        return $this->delegate->objectExists($object);
    }

    public function columnExists($table, $column)
    {
        $this->authorizer->authorizeColumn($this->primaryDatabaseId, null, (string)$table, (string)$column);
        return $this->delegate->columnExists($table, $column);
    }

    public function objectColumnExists(QualifiedObject $object, $column): bool
    {
        $this->authorizer->authorizeColumn($object->databaseId, $object->schemaName(), $object->objectName(), (string)$column);
        return $this->delegate->objectColumnExists($object, $column);
    }

    public function getColumnDataType($table, $column)
    {
        $this->authorizer->authorizeColumn($this->primaryDatabaseId, null, (string)$table, (string)$column);
        return $this->delegate->getColumnDataType($table, $column);
    }

    public function objectColumnDataType(QualifiedObject $object, $column)
    {
        $this->authorizer->authorizeColumn($object->databaseId, $object->schemaName(), $object->objectName(), (string)$column);
        return $this->delegate->objectColumnDataType($object, $column);
    }

    /** Listing every column (`*` expansion) needs every column in scope. */
    public function getColumns($tableName)
    {
        $this->authorizer->authorizeAllColumns($this->primaryDatabaseId, null, (string)$tableName);
        return $this->delegate->getColumns($tableName);
    }

    public function objectColumns(QualifiedObject $object)
    {
        $this->authorizer->authorizeAllColumns($object->databaseId, $object->schemaName(), $object->objectName());
        return $this->delegate->objectColumns($object);
    }

    public function assertAllColumnsReadable($table): void
    {
        $this->authorizer->authorizeAllColumns($this->primaryDatabaseId, null, (string)$table);
    }

    public function assertAllObjectColumnsReadable(QualifiedObject $object): void
    {
        $this->authorizer->authorizeAllColumns($object->databaseId, $object->schemaName(), $object->objectName());
    }

    // Catalog listings, write metadata, and routines are not query-building
    // lookups; their actions are authorized before they are reached.
    public function getTables() { return $this->delegate->getTables(); }
    public function getViews() { return $this->delegate->getViews(); }
    public function getProcedures() { return $this->delegate->getProcedures(); }
    public function schema() { return $this->delegate->schema(); }
    public function getWriteColumns(string $schema, string $table): array { return $this->delegate->getWriteColumns($schema, $table); }
    public function getRoutine(string $schema, string $name): ?array { return $this->delegate->getRoutine($schema, $name); }
    public function hasUniqueKey(string $schema, string $table, array $columns): bool { return $this->delegate->hasUniqueKey($schema, $table, $columns); }
}
