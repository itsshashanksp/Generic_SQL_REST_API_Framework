<?php

$tests = [
    __DIR__ . '/UniversalApiContractTest.php',
    __DIR__ . '/OrderByWindowRegressionTest.php',
    __DIR__ . '/BackendLogicTest.php',
    __DIR__ . '/ExtendedExpressionTest.php',
    __DIR__ . '/QueryCapabilityTest.php',
    __DIR__ . '/CrudOperationsTest.php',
    __DIR__ . '/SqlControllerTest.php',
    __DIR__ . '/SqlResourceCapabilityTest.php',
    __DIR__ . '/SqlResourceFilteringTest.php',
    __DIR__ . '/SqlResourceDiscoveryTest.php',
    __DIR__ . '/ExampleLibraryTest.php',
    __DIR__ . '/AccessScopeAuthorizationTest.php',
    __DIR__ . '/AccessScopeRegressionTest.php',
    __DIR__ . '/AccessScopeHttpEnforcementTest.php',
    __DIR__ . '/SqlParserGeneratorTest.php',
    __DIR__ . '/SqlParserAssetUrlTest.php',
    __DIR__ . '/QueryExecutionIsolationTest.php',
    __DIR__ . '/LoggerTest.php',
    __DIR__ . '/SecurityAuditLoggingTest.php',
    __DIR__ . '/OperationalLoggingTest.php',
    __DIR__ . '/BackupRecoveryTest.php',
    __DIR__ . '/HealthMonitoringTest.php',
    __DIR__ . '/ProductionSystemHealthTest.php',
    __DIR__ . '/ProductionErrorHandlingTest.php',
    __DIR__ . '/ProductionValidationTest.php',
    __DIR__ . '/DatabaseCredentialEncryptionTest.php',
    __DIR__ . '/DatabaseConfigurationEncryptionTest.php',
    __DIR__ . '/DatabaseRegistryTest.php',
    __DIR__ . '/DatabaseContextResolutionTest.php',
    __DIR__ . '/DatabaseQueryPlanningTest.php',
    __DIR__ . '/SourceResolutionTest.php',
    __DIR__ . '/CrossDatabaseSelectTest.php',
    __DIR__ . '/DatabaseMetadataTest.php',
    __DIR__ . '/DatabaseResourceRoutineTest.php',
    __DIR__ . '/DatabaseWriteTest.php',
    __DIR__ . '/AdminDatabaseManagementTest.php',
    __DIR__ . '/SecurityIsolationRegressionTest.php',
    __DIR__ . '/UpgradeVerificationTest.php',
    __DIR__ . '/DatabaseAvailabilityLifecycleTest.php',
    __DIR__ . '/RuntimeConfigurationBootstrapTest.php',
    __DIR__ . '/AuthenticationFoundationTest.php',
    __DIR__ . '/FirstTimeSetupTest.php',
    __DIR__ . '/AuthenticationFlowTest.php',
    __DIR__ . '/ApiProtectionTest.php',
    __DIR__ . '/AdminUserManagementTest.php',
    __DIR__ . '/AuthorizationAndApiKeyTest.php',
    __DIR__ . '/FrontendUserMutationAuthorizationTest.php',
    __DIR__ . '/AuthorizationBoundaryTest.php',
    __DIR__ . '/ApiSecurityHardeningTest.php',
    __DIR__ . '/AuthorizationApiCoverageTest.php',
    __DIR__ . '/DastRegressionTest.php',
    __DIR__ . '/SecurityHardeningTest.php',
    __DIR__ . '/SecurityTestingTest.php',
    __DIR__ . '/UnifiedAdminConsoleTest.php',
    __DIR__ . '/AdminBasePathTest.php',
    __DIR__ . '/AdminRuntimeManagementTest.php',
    __DIR__ . '/RuntimePerformanceControlsTest.php',
    __DIR__ . '/RuntimeConcurrencyTest.php',
    __DIR__ . '/ProductionRuntimeControlTest.php',
    __DIR__ . '/ProductionApplicationAvailabilityTest.php',
    __DIR__ . '/ProductionServerConfigurationTest.php',
    __DIR__ . '/ProductionHostingTest.php',
    __DIR__ . '/HttpsSecurityTest.php',
    __DIR__ . '/OperationalHardeningTest.php',
    __DIR__ . '/TestIsolationRegressionTest.php',
    __DIR__ . '/StaticSecurityRemediationTest.php',
    __DIR__ . '/RepositoryDocumentationTest.php'
];

// Tests never write the deployment's logs or security state: unless the caller
// chose locations, each run gets temporary ones, inherited by every test and
// by the PHP servers tests start, and removed afterwards.
$isolatedRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-test-run-' . bin2hex(random_bytes(6));
foreach (['GENERIC_LOG_DIR' => 'logs', 'GENERIC_OPERATIONAL_LOG_DIR' => 'operational', 'GENERIC_SECURITY_STORAGE_DIR' => 'security'] as $name => $path) {
    $configured = getenv($name);
    if (is_string($configured) && trim($configured) !== '') continue;
    @mkdir($isolatedRoot . DIRECTORY_SEPARATOR . $path, 0700, true);
    putenv($name . '=' . $isolatedRoot . DIRECTORY_SEPARATOR . $path);
}
$removeIsolatedRoot = static function () use ($isolatedRoot): void {
    if (!is_dir($isolatedRoot)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($isolatedRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($isolatedRoot);
};

foreach ($tests as $test) {
    echo 'Running ' . basename($test) . PHP_EOL;
    $command = escapeshellarg(PHP_BINARY)
        . (php_ini_loaded_file() === false ? ' -n' : '')
        . ' ' . escapeshellarg($test);
    passthru($command, $status);
    if ($status !== 0) {
        fwrite(STDERR, basename($test) . " failed.\n");
        $removeIsolatedRoot();
        exit($status);
    }
}
$removeIsolatedRoot();

echo "All database-independent backend tests passed.\n";
