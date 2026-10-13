<?php

/**
 * Read-only SQL Server privilege diagnostic for registered logical databases.
 * Prints role memberships as booleans; never prints login names, credentials,
 * or data. A reporting login should not be sysadmin, db_owner, or
 * db_datawriter. Not run at startup: other drivers report NOT EXECUTED.
 *
 * Usage: php scripts/check-database-privileges.php <database-id> [<database-id> ...]
 * Requires the same GENERIC_SQL_API_ENCRYPTION_KEY the API uses.
 */

require_once __DIR__ . '/../app/Database/DatabaseContextResolver.php';
require_once __DIR__ . '/../app/Database/DatabaseConnectionManager.php';

$ids = array_slice($argv, 1);
if ($ids === []) {
    fwrite(STDERR, "Usage: php scripts/check-database-privileges.php <database-id> [...]\n");
    exit(2);
}
$sql = "SELECT IS_SRVROLEMEMBER('sysadmin') AS sysadmin, IS_ROLEMEMBER('db_owner') AS db_owner, "
    . "IS_ROLEMEMBER('db_datawriter') AS db_datawriter, IS_ROLEMEMBER('db_ddladmin') AS db_ddladmin, "
    . "IS_ROLEMEMBER('db_datareader') AS db_datareader";
$report = [];
$elevated = false;
foreach ($ids as $id) {
    $manager = new DatabaseConnectionManager();
    try {
        $connection = $manager->connection((new DatabaseContextResolver())->resolve($id))->getConnection();
        $result = function_exists('odbc_exec') ? @odbc_exec($connection, $sql) : false;
        $row = $result ? odbc_fetch_array($result) : false;
        if (!is_array($row)) {
            $report[$id] = ['status' => 'NOT EXECUTED', 'reason' => 'Role functions unavailable for this driver.'];
            continue;
        }
        $flags = array_map(fn ($value): bool => (string)$value === '1', $row);
        $risky = $flags['sysadmin'] || $flags['db_owner'] || $flags['db_datawriter'] || $flags['db_ddladmin'];
        $elevated = $elevated || $risky;
        $report[$id] = ['status' => $risky ? 'ELEVATED' : 'LEAST PRIVILEGE CANDIDATE'] + $flags;
    } catch (Throwable $exception) {
        $report[$id] = ['status' => 'NOT EXECUTED', 'reason' => get_class($exception)];
    } finally {
        $manager->closeAll();
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($elevated ? 3 : 0);
