<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Security/CsrfTokenService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class CsrfProtectionMiddleware extends Middleware
{
    private const PROTECTED_ACTIONS = [
        'auth.login',
        'auth.logout',
        'auth.users.create',
        'auth.users.update',
        'auth.users.enable',
        'auth.users.disable',
        'auth.users.delete',
        'auth.users.changePassword',
        'auth.users.assignAuthorization',
        'auth.frontendUsers.create',
        'auth.frontendUsers.update',
        'auth.frontendUsers.enable',
        'auth.frontendUsers.disable',
        'auth.frontendUsers.delete',
        'auth.frontendUsers.changePassword',
        'auth.frontendUsers.assignRole',
        'configuration.chartColors.update',
        'auth.apiKeys.create',
        'auth.apiKeys.enable',
        'auth.apiKeys.disable',
        'auth.apiKeys.revoke',
        'setup.createAdmin',
        'admin.database.test',
        'admin.database.save',
        'admin.database.connect',
        'admin.database.disconnect',
        'admin.database.restart',
        'admin.servers.save',
        'admin.servers.enable',
        'admin.servers.disable',
        'admin.servers.delete',
        'admin.servers.test',
        'admin.databases.save',
        'admin.databases.enable',
        'admin.databases.disable',
        'admin.databases.default',
        'admin.databases.delete',
        'admin.databases.test',
        'admin.databases.connect',
        'admin.databases.disconnect',
        'admin.server.save',
        'admin.console.restart',
        'admin.api.start',
        'admin.api.stop',
        'admin.api.restart',
        'admin.sqlParser.start',
        'admin.sqlParser.stop',
        'admin.sqlParser.restart',
        'admin.cors.save',
        'admin.authentication.save',
        'admin.runtime.save',
        'admin.backup.create',
        'admin.backup.download',
        'admin.backup.preview',
        'admin.backup.restore',
        'admin.backup.schedule.save',
        'admin.operational.event',
        'insert',
        'update',
        'delete',
        'upsert',
        // Stored procedures can change data, so they are protected like writes.
        // Scalar and table-valued functions cannot, and stay unprotected like select.
        'procedure',
    ];

    private CsrfTokenService $tokens;

    public function __construct(?CsrfTokenService $tokens = null)
    {
        $this->tokens = $tokens ?? new CsrfTokenService();
    }

    public function handle(array $request): void
    {
        if (in_array($_SERVER['GENERIC_AUTH_PROVIDER'] ?? null, ['api_key', 'none'], true)) {
            return;
        }
        if (!in_array($request['action'] ?? null, self::PROTECTED_ACTIONS, true)) {
            return;
        }
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $this->tokens->validate(is_string($header) ? $header : null);
    }
}
