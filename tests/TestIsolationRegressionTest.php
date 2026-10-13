<?php

/*
 * Regression coverage for test-suite isolation: suites must keep their
 * artifacts in temporary directories, never in the real code tree's runtime
 * configuration, beside the repository, or in the deployment's logs, and
 * suites that start managed processes must stop them in `finally`.
 *
 * Source-level checks only: running these suites to detect a regression would
 * itself write to the real tree.
 */

function isolationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$tests = __DIR__;
$source = fn (string $file): string => (string)file_get_contents($tests . '/' . $file);

// OperationalHardeningTest: the validator's runtime-location cases use a
// temporary code tree, never Backend/config or a sibling of the repository.
$hardening = $source('OperationalHardeningTest.php');
isolationAssert(!preg_match('/new ProductionValidator\(\$root\)/', $hardening),
    'OperationalHardeningTest validates the real code tree (bootstraps runtime files into Backend/config).');
isolationAssert(!preg_match("/putenv\('GENERIC_RUNTIME_CONFIG_DIR=' \. \\\$root\b/", $hardening),
    'OperationalHardeningTest points the runtime directory into the real code tree or beside it.');
isolationAssert(str_contains($hardening, "\$validatorRoot = \$directory . DIRECTORY_SEPARATOR . 'code-tree';")
    && preg_match_all('/new ProductionValidator\(\$validatorRoot\)/', $hardening) === 4,
    'OperationalHardeningTest no longer runs every runtime-location case against its temporary code tree.');
isolationAssert(str_contains($hardening, "hardeningSnapshot(\$root . DIRECTORY_SEPARATOR . 'config') === \$realConfigBefore")
    && str_contains($hardening, "hardeningSnapshot(\$root . '-sibling') === \$realSiblingBefore"),
    'OperationalHardeningTest no longer asserts that the real config directory and its sibling are unchanged.');

// SecurityTestingTest: default loggers and security storage are redirected
// before any work and restored afterwards, so a direct run writes no real logs.
$security = $source('SecurityTestingTest.php');
$redirect = strpos($security, "foreach (\$isolatedPaths as \$name => \$path) putenv(\$name . '=' . \$path);");
$firstWork = strpos($security, '$logger = new Logger($logs);');
isolationAssert($redirect !== false && $firstWork !== false && $redirect < $firstWork,
    'SecurityTestingTest does not redirect default log and storage directories before its first logger.');
foreach (['GENERIC_LOG_DIR', 'GENERIC_OPERATIONAL_LOG_DIR', 'GENERIC_SECURITY_STORAGE_DIR'] as $name) {
    isolationAssert(preg_match("/'{$name}' => \\\$root \. '\/[a-z-]+'/", $security) === 1,
        "SecurityTestingTest does not place {$name} inside its temporary directory.");
}
$finally = strrpos($security, '} finally {');
isolationAssert($finally !== false && strpos($security, 'foreach ($oldIsolated as $name => $value)', $finally) !== false,
    'SecurityTestingTest does not restore the isolated directories in finally.');

// Managed-process suites stop every manager they create in finally, even after a failed assertion.
foreach (['AdminRuntimeManagementTest.php' => ['$manager', '$parserManager'], 'RuntimeConcurrencyTest.php' => ['$manager']] as $file => $managers) {
    $text = $source($file);
    $block = strrpos($text, '} finally {');
    isolationAssert($block !== false, "{$file} has no finally block.");
    foreach ($managers as $manager) {
        isolationAssert(preg_match('/try \{ ' . preg_quote($manager, '/') . '->stop\(\); \} catch \(Throwable \$exception\) \{\}/', substr($text, $block)) === 1,
            "{$file} does not stop {$manager} in finally.");
    }
}

echo "Test isolation regression tests passed.\n";
