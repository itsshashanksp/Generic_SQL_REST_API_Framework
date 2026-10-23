<?php

require_once __DIR__ . '/../../app/Resources/SqlResourceRegistry.php';

/** Tracked SQL Resources used by database-independent tests. */
function sqlResourceFixtureRoot(): string
{
    return dirname(__DIR__) . '/fixtures/sql-resources';
}

function sqlResourceFixtureRegistry(): SqlResourceRegistry
{
    return new SqlResourceRegistry([], sqlResourceFixtureRoot());
}
