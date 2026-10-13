<?php

/**
 * Read-only migration report: accounts created by the frontend before Phase 4
 * that still hold the backend `read-only` role together with frontend access
 * and no frontend role. New frontend readers have no backend role.
 *
 * Migrate each listed account in the Admin Console (Users → authorization):
 * remove the backend role and keep frontend access. That increments the
 * account's authVersion, ending its current sessions. API keys are unaffected.
 * Nothing is changed by this script; no secret is printed.
 */

require_once __DIR__ . '/../app/Repositories/AuthRepository.php';
require_once __DIR__ . '/../app/Authorization/RoleModel.php';

$users = (new AuthRepository())->load()['users'] ?? [];
$accounts = array_values(array_filter($users, fn (array $user): bool =>
    ($user['backendRole'] ?? null) === RoleModel::READ_ONLY
    && ($user['frontendAccess'] ?? false) === true
    && ($user['frontendRole'] ?? null) === null
));
echo json_encode([
    'accountsToMigrate' => count($accounts),
    'accounts' => array_map(fn (array $user): array => [
        'username' => $user['username'],
        'enabled' => $user['enabled'] ?? null,
    ], $accounts),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
