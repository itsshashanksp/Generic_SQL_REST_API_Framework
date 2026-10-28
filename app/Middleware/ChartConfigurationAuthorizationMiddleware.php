<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Authorization/PrincipalContext.php';
require_once __DIR__ . '/../Services/AuthorizationService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';

final class ChartConfigurationAuthorizationMiddleware extends Middleware
{
    public function __construct(private ?AuthorizationService $authorization = null) { $this->authorization ??= new AuthorizationService(); }
    public function handle(array $request): void
    {
        $action = $request['action'] ?? null;
        if (!in_array($action, ChartConfigurationRequestValidator::ACTIONS, true)) return;
        $principal = PrincipalContext::current();
        if ($principal === null) throw new ApiRequestException('Authentication required.', 'AUTHENTICATION_REQUIRED', [], 401);
        $this->authorization->authorize($principal, $action === 'configuration.chartColors.update' ? 'frontend.users.manage' : 'frontend.read');
    }
}
