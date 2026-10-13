<?php

/**
 * Validates config/access-scopes.json (or the given path) and reports whether
 * it is ready for production enforcement. Read-only: it reads the database
 * registry, the SQL Resource directory, and the user and API-key files, and
 * changes nothing.
 *
 * Exit status:
 *   0 valid, enforced, and complete (review items still need owner sign-off)
 *   1 invalid (malformed; the API fails closed)
 *   3 valid and complete, but missing or log-only (not yet fail-closed)
 *   4 valid, but incomplete: it names a database or SQL Resource that does not exist
 *
 * Usage: php scripts/validate-access-scopes.php [path]
 */

require_once __DIR__ . '/../app/Authorization/AccessScope/AccessScopeConfiguration.php';
require_once __DIR__ . '/../app/Authorization/AccessScope/AccessScopeReadiness.php';
require_once __DIR__ . '/../app/Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../app/Database/DatabaseRegistry.php';
require_once __DIR__ . '/../app/Resources/SqlResourceRegistry.php';
require_once __DIR__ . '/../app/Security/ApiKeyAuthenticator.php';

$configuration = AccessScopeConfiguration::load($argv[1] ?? null);
$report = [
    'path' => $argv[1] ?? AccessScopeConfiguration::path(),
    'state' => $configuration->state,
    'mode' => $configuration->mode,
    'production' => SecurityConfiguration::isProduction(),
    'subjects' => array_map(
        fn (array $scope): string => $scope['unrestricted'] ? 'unrestricted' : 'restricted',
        $configuration->scopes
    ),
];
if ($configuration->error !== null) $report['error'] = $configuration->error;

if ($configuration->state !== AccessScopeConfiguration::STATE_INVALID) {
    $readJson = function (string $file): array {
        $value = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        return is_array($value) ? $value : [];
    };
    try {
        $databaseIds = array_keys((new DatabaseRegistry())->metadata()['databases'] ?? []);
    } catch (Throwable $exception) {
        $databaseIds = [];
        $report['warnings'][] = 'The database registry could not be read; every database is reported as unregistered.';
    }
    try {
        $resourceIds = (new SqlResourceRegistry())->discoverResourceIds();
    } catch (Throwable $exception) {
        $resourceIds = [];
        $report['warnings'][] = 'The SQL Resource directory could not be read; every resource is reported as missing.';
    }
    // Subjects that enabled principals rely on, counted from the stored files without migrating them.
    $inUse = [];
    foreach ($readJson(RuntimeConfiguration::path(RuntimeConfiguration::AUTH_FILE))['users'] ?? [] as $user) {
        if (!is_array($user) || ($user['enabled'] ?? false) !== true) continue;
        if (($user['frontendAccess'] ?? false) === true) $inUse[AccessScopeConfiguration::FRONTEND_SUBJECT] = ($inUse[AccessScopeConfiguration::FRONTEND_SUBJECT] ?? 0) + 1;
        foreach (array_unique(array_filter([$user['backendRole'] ?? null, $user['frontendRole'] ?? null], 'is_string')) as $role) $inUse[$role] = ($inUse[$role] ?? 0) + 1;
    }
    $keys = $readJson(RuntimeConfiguration::path(RuntimeConfiguration::API_KEYS_FILE));
    foreach ($keys['keys'] ?? [] as $key) {
        if (!is_array($key) || ($key['enabled'] ?? false) !== true || isset($key['revokedAt'])) continue;
        foreach ($key['roles'] ?? [] as $role) if (is_string($role)) $inUse[$role] = ($inUse[$role] ?? 0) + 1;
    }
    if (is_string(getenv(ApiKeyAuthenticator::ENVIRONMENT_VARIABLE)) && getenv(ApiKeyAuthenticator::ENVIRONMENT_VARIABLE) !== '') {
        $authorization = $readJson(RuntimeConfiguration::path(RuntimeConfiguration::AUTHORIZATION_FILE));
        foreach ($authorization['legacyApiKeyRoles'] ?? RuntimeConfiguration::authorizationDefaults()['legacyApiKeyRoles'] as $role) {
            if (is_string($role)) $inUse[$role] = ($inUse[$role] ?? 0) + 1;
        }
    }
    ksort($inUse);
    $readiness = AccessScopeReadiness::review($configuration, $databaseIds, $resourceIds, $inUse);
    $report['subjectsInUse'] = $inUse;
    $report['blocking'] = $readiness['blocking'];
    $report['review'] = $readiness['review'];
}
// Complete: nothing named is missing. Enforcement additionally needs mode
// "enforce" and owner sign-off on every review item.
$report['complete'] = $configuration->state === AccessScopeConfiguration::STATE_CONFIGURED && ($report['blocking'] ?? []) === [];
$report['enforced'] = $configuration->state === AccessScopeConfiguration::STATE_CONFIGURED && $configuration->enforced();
$report['reviewItems'] = count($report['review'] ?? []);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

if ($configuration->state === AccessScopeConfiguration::STATE_INVALID) exit(1);
if ($configuration->state === AccessScopeConfiguration::STATE_CONFIGURED && $report['blocking'] !== []) exit(4);
exit($configuration->state === AccessScopeConfiguration::STATE_CONFIGURED && $configuration->enforced() ? 0 : 3);
