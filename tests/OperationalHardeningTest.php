<?php

require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Deployment/ProductionValidator.php';

/*
 * Regression coverage for the v2.1.5 security architecture and operational
 * hardening findings (docs/security/Security-Verification.md).
 *
 * SAOH-01: runtime state must live outside the code tree so the PHP worker never
 *          needs write access to Backend/config, which holds executable PHP
 *          allowlists (query, routine, write, and SQL Resource sources).
 * SAOH-02: the bundled development PHP runtimes must stay read-only for the
 *          production worker identity.
 * SAOH-03: readiness and liveness must be identified the same way at the site
 *          root and under an IIS application path such as /api.
 */

function hardeningAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function hardeningRemove(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') hardeningRemove($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function hardeningCopy(string $from, string $to): void
{
    if (!is_dir($from)) { copy($from, $to); return; }
    if (!is_dir($to)) mkdir($to, 0700, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') hardeningCopy($from . DIRECTORY_SEPARATOR . $entry, $to . DIRECTORY_SEPARATOR . $entry);
    }
}

/** Names and hashes of a directory's files, or null when it does not exist. */
function hardeningSnapshot(string $path): ?array
{
    if (!is_dir($path)) return null;
    $files = [];
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $file = $path . DIRECTORY_SEPARATOR . $entry;
        $files[$entry] = is_file($file) ? sha1_file($file) . ':' . decoct(fileperms($file) & 0777) : 'dir';
    }
    ksort($files);
    return $files;
}

/** Run api/health.php in an isolated PHP process for one request path. */
function hardeningHealth(string $uri, string $directory): array
{
    $entry = dirname(__DIR__) . '/api/health.php';
    $program = '$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = getenv("HARDENING_REQUEST_URI");'
        . ' include ' . var_export($entry, true) . '; echo "\n", http_response_code();';
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) $command[] = '-n';
    array_push($command, '-d', 'display_errors=0', '-r', $program);
    $environment = array_merge(is_array(getenv()) ? getenv() : [], [
        'GENERIC_APP_ENV' => 'development',
        'GENERIC_RUNTIME_CONFIG_DIR' => $directory,
        'GENERIC_LOG_DIR' => $directory . DIRECTORY_SEPARATOR . 'logs',
        'GENERIC_OPERATIONAL_LOG_DIR' => $directory . DIRECTORY_SEPARATOR . 'operational',
        'HARDENING_REQUEST_URI' => $uri,
    ]);
    unset($environment['GENERIC_SQL_API_ENCRYPTION_KEY']);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    hardeningAssert(is_resource($process), 'Unable to run the health endpoint.');
    $output = (string)stream_get_contents($pipes[1]);
    $error = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    hardeningAssert(proc_close($process) === 0 && $error === '', "Health endpoint failed for {$uri}: {$error}");
    $separator = strrpos($output, "\n");
    $payload = json_decode(substr($output, 0, (int)$separator), true);
    hardeningAssert(is_array($payload), "Health endpoint returned no JSON for {$uri}.");
    return [(int)substr($output, (int)$separator + 1), $payload];
}

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'generic-operational-hardening-' . bin2hex(random_bytes(8));
// The validator bootstraps missing runtime files in the directory it inspects,
// so its runtime-location cases use a temporary code tree (holding the
// deployment templates it validates) and never the real Backend/config or a
// directory beside the repository.
$validatorRoot = $directory . DIRECTORY_SEPARATOR . 'code-tree';
$realConfigBefore = hardeningSnapshot($root . DIRECTORY_SEPARATOR . 'config');
$realSiblingBefore = hardeningSnapshot($root . '-sibling');

try {
    // SAOH-03: the database starts disconnected, so readiness must be 503 at
    // every mount path, and liveness must stay minimal.
    mkdir($directory, 0700, true);
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory);
    RuntimeConfiguration::ensure();
    putenv('GENERIC_RUNTIME_CONFIG_DIR');
    foreach (['/health/ready', '/api/health/ready', '/internal/api/health/ready', '/api/health/ready?probe=1'] as $uri) {
        [$status, $payload] = hardeningHealth($uri, $directory);
        hardeningAssert($status === 503 && $payload['status'] === 'unhealthy'
            && ($payload['checks']['database']['category'] ?? null) === 'database_disconnected',
            "Readiness at {$uri} did not report the disconnected database (status {$status}).");
    }
    foreach (['/health/live', '/api/health/live'] as $uri) {
        [$status, $payload] = hardeningHealth($uri, $directory);
        hardeningAssert($status === 200 && array_keys($payload) === ['status', 'service', 'version'],
            "Liveness at {$uri} was not the minimal public response.");
    }
    foreach (['/api/health/ready/extra', '/api/xhealth/ready'] as $uri) {
        [$status, $payload] = hardeningHealth($uri, $directory);
        hardeningAssert(!isset($payload['checks']), "{$uri} was treated as a readiness probe.");
    }

    // SAOH-01: the validation report flags runtime state inside the code tree
    // without printing any path. Without an override, the runtime directory is
    // the code tree's config directory (checked without bootstrapping it).
    putenv('GENERIC_RUNTIME_CONFIG_DIR');
    hardeningAssert(str_replace('\\', '/', RuntimeConfiguration::directory()) === str_replace('\\', '/', $root . '/config'),
        'The default runtime directory is not the code tree config directory.');
    hardeningCopy($root . DIRECTORY_SEPARATOR . 'deployment', $validatorRoot . DIRECTORY_SEPARATOR . 'deployment');
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $validatorRoot . DIRECTORY_SEPARATOR . 'config');
    $inside = (new ProductionValidator($validatorRoot))->report()['environment']['runtimeConfiguration'];
    hardeningAssert($inside['status'] === ProductionValidator::OPERATOR && $inside['location'] === 'inside_code_tree',
        'The validator accepted runtime configuration inside the code tree.');
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $validatorRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR);
    $explicit = (new ProductionValidator($validatorRoot))->report()['environment']['runtimeConfiguration'];
    hardeningAssert($explicit['location'] === 'inside_code_tree', 'An explicit code-tree config runtime directory was accepted.');
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $directory . DIRECTORY_SEPARATOR . 'state');
    $outside = (new ProductionValidator($validatorRoot))->report()['environment']['runtimeConfiguration'];
    hardeningAssert($outside === ['status' => ProductionValidator::VALIDATED, 'location' => 'outside_code_tree'],
        'The validator rejected runtime configuration outside the code tree.');
    putenv('GENERIC_RUNTIME_CONFIG_DIR=' . $validatorRoot . '-sibling' . DIRECTORY_SEPARATOR . 'config');
    hardeningAssert((new ProductionValidator($validatorRoot))->report()['environment']['runtimeConfiguration']['location'] === 'outside_code_tree',
        'A sibling directory sharing the code-tree prefix was treated as inside it.');
    putenv('GENERIC_RUNTIME_CONFIG_DIR');
    foreach ([$inside, $explicit, $outside] as $result) {
        hardeningAssert(!str_contains(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), str_replace('\\', '/', $validatorRoot))
            && !str_contains(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $directory), 'The validator reported a filesystem path.');
    }

    // SAOH-01 / SAOH-02: production guidance keeps Backend/config and the
    // development runtimes read-only and moves runtime state out of the code tree.
    $iis = (string)file_get_contents($root . '/docs/Windows-IIS-Deployment.md');
    $hosting = (string)file_get_contents($root . '/docs/Production-Security-and-Deployment.md');
    preg_match('/foreach \(\$path in @\((.*?)\)\) \{/s', $iis, $writable);
    hardeningAssert(isset($writable[1]) && str_contains($writable[1], '"$root\\state\\config"')
        && !str_contains($writable[1], 'Backend\\config"'), 'The IIS guide grants the pool Modify on Backend\\config.');
    hardeningAssert(str_contains($iis, "environmentVariables.[name='GENERIC_RUNTIME_CONFIG_DIR',value='C:\\GenericReporting\\state\\config']"),
        'The IIS FastCGI registrations do not set GENERIC_RUNTIME_CONFIG_DIR.');
    hardeningAssert(str_contains($iis, '| `Backend\\config` | Read & execute |')
        && str_contains($iis, '| `Backend\\runtime\\windows`, `Backend\\runtime\\linux` | Read & execute |')
        && str_contains($iis, '"$root\\Backend\\runtime\\windows", "$root\\Backend\\runtime\\linux"'),
        'The IIS guide no longer keeps code directories read-only.');
    hardeningAssert(str_contains($hosting, '| `config/` (shipped PHP configuration and allowlists) | no | read only |')
        && str_contains($hosting, 'GENERIC_RUNTIME_CONFIG_DIR=<absolute path to a runtime state directory outside Backend')
        && !str_contains($hosting, 'GENERIC_RUNTIME_CONFIG_DIR=<absolute path to Backend/config>'),
        'Production hosting guidance places runtime state in Backend/config.');

    // Regression: the runtime-location cases never touched the real code tree's
    // config directory or created a directory beside the repository.
    hardeningAssert(hardeningSnapshot($root . DIRECTORY_SEPARATOR . 'config') === $realConfigBefore,
        'The test changed files in the real Backend/config directory.');
    hardeningAssert(hardeningSnapshot($root . '-sibling') === $realSiblingBefore,
        'The test created or changed a directory beside the repository.');

    echo "Operational hardening tests passed.\n";
} finally {
    putenv('GENERIC_RUNTIME_CONFIG_DIR');
    hardeningRemove($directory);
}
