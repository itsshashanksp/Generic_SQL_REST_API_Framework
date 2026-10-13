<?php

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/../Services/AuthSessionService.php';
require_once __DIR__ . '/../Requests/ApiRequestException.php';
require_once __DIR__ . '/../Repositories/AuthRepository.php';
require_once __DIR__ . '/../Security/ApiKeyAuthenticator.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';
require_once __DIR__ . '/../Security/ApiRateLimiter.php';
require_once __DIR__ . '/../Authorization/PrincipalContext.php';
require_once __DIR__ . '/../Services/AuthorizationService.php';
require_once __DIR__ . '/../Services/ApiKeyService.php';
require_once __DIR__ . '/../../core/Logger.php';
require_once __DIR__ . '/../../core/OperationalLogger.php';

final class AuthenticationMiddleware extends Middleware
{
    private bool $enforce;
    private array $publicActions;
    private AuthSessionService $session;
    private AuthRepository $authRepository;
    private ApiKeyAuthenticator $apiKeys;
    private AuthorizationService $authorization;
    private ApiKeyService $managedApiKeys;
    private Logger $logger;
    private ApiRateLimiter $unauthenticatedRateLimiter;

    public function __construct(
        bool $enforce = false,
        array $publicActions = [],
        ?AuthSessionService $session = null,
        ?AuthRepository $authRepository = null,
        ?ApiKeyAuthenticator $apiKeys = null,
        ?AuthorizationService $authorization = null,
        ?ApiKeyService $managedApiKeys = null,
        ?Logger $logger = null,
        ?ApiRateLimiter $unauthenticatedRateLimiter = null
    ) {
        $this->enforce = $enforce;
        $this->publicActions = $publicActions;
        $this->session = $session ?? new AuthSessionService();
        $this->authRepository = $authRepository ?? new AuthRepository();
        $this->apiKeys = $apiKeys ?? new ApiKeyAuthenticator();
        $this->authorization = $authorization ?? new AuthorizationService();
        $this->managedApiKeys = $managedApiKeys ?? new ApiKeyService(null, $this->authRepository, $this->authorization);
        $this->logger = $logger ?? new Logger();
        $this->unauthenticatedRateLimiter = $unauthenticatedRateLimiter ?? new ApiRateLimiter();
    }

    public function handle(array $request): void
    {
        unset($_SERVER['GENERIC_AUTH_PROVIDER']);
        PrincipalContext::clear();
        if (!$this->enforce || in_array($request['action'] ?? null, $this->publicActions, true)) {
            (new OperationalLogger())->info('api', 'API authentication not required', ['action' => $request['action'] ?? null]);
            return;
        }

        $action = (string)($request['action'] ?? '');
        if (str_starts_with($action, 'admin.') || str_starts_with($action, 'auth.')) {
            $this->requireSession();
            return;
        }

        $mode = SecurityConfiguration::authenticationMode();
        if ($mode === 'none') {
            if (!SecurityConfiguration::anonymousAuthenticationPermitted()) {
                (new OperationalLogger())->warning('api', 'Anonymous API authentication refused in production', [
                    'error_code' => 'AUTHENTICATION_UNAVAILABLE',
                ]);
                throw new ApiRequestException('Anonymous API access is disabled in production.', 'AUTHENTICATION_UNAVAILABLE', [], 503);
            }
            $_SERVER['GENERIC_AUTH_PROVIDER'] = 'none';
            $roles = $this->authorization->publicRoles();
            $role = $roles[0] ?? null;
            PrincipalContext::set(new Principal(null, 'public', 'none', $role, false, null, true, $this->authorization->permissionsForRoles($roles)));
            (new OperationalLogger())->info('api', 'API authentication accepted', ['authentication' => 'none']);
            return;
        }
        if (($mode === 'api_key' || $mode === 'session+api_key') && $this->resolveApiKey()) {
            $_SERVER['GENERIC_AUTH_PROVIDER'] = 'api_key';
            (new OperationalLogger())->info('api', 'API authentication accepted', ['authentication' => 'api_key']);
            return;
        }
        if ($mode === 'api_key') {
            if (!$this->apiKeys->configured() && !$this->managedApiKeys->configured()) {
                throw new ApiRequestException(
                    'API key authentication is not configured.',
                    'AUTHENTICATION_UNAVAILABLE',
                    [],
                    503
                );
            }
            $this->authenticationRequired();
        }
        $this->requireSession();
    }

    private function requireSession(): void
    {
        if (!$this->session->resume() || !$this->session->isAuthenticated()) {
            $this->authenticationRequired();
        }

        try {
            $user = $this->authRepository->findUserById((string)$this->session->authenticatedUserId());
        } catch (Throwable $exception) {
            throw new ApiRequestException(
                'Unable to validate authentication.',
                'AUTHENTICATION_UNAVAILABLE',
                [],
                500
            );
        }
        if ($user === null
            || $user['enabled'] !== true
            || $user['username'] !== $this->session->authenticatedUsername()
            || $user['authVersion'] !== $this->session->authenticatedAuthVersion()) {
            $this->logger->audit('auth.session', 'invalidated', 'NOTICE', [
                'actorType' => 'user',
                'actorId' => $this->session->authenticatedUserId(),
                'actorUsername' => $this->session->authenticatedUsername(),
                'authenticationMethod' => 'session',
                'reason' => $user === null ? 'identity_missing'
                    : ($user['enabled'] !== true ? 'account_disabled'
                    : ($user['username'] !== $this->session->authenticatedUsername() ? 'identity_changed' : 'auth_version_changed')),
                'component' => 'authentication',
            ]);
            $this->session->destroy();
            $this->authenticationRequired();
        }
        PrincipalContext::set(new Principal(
            $user['id'], $user['username'], 'session', $user['backendRole'], $user['frontendAccess'],
            $user['frontendRole'], true, $this->authorization->permissionsForRoles(array_values(array_filter([$user['backendRole'], $user['frontendRole']])))
        ));
        (new OperationalLogger())->info('api', 'API authentication accepted', ['authentication' => 'session']);
    }

    private function resolveApiKey(): bool
    {
        $provided = $this->apiKeys->provided();
        if ($provided === null) return false;
        try {
            $resolved = $this->managedApiKeys->authenticate($provided);
        } catch (Throwable $exception) {
            throw new ApiRequestException('Unable to validate authentication.', 'AUTHENTICATION_UNAVAILABLE', [], 500);
        }
        if ($resolved !== null) {
            $owner = $resolved['owner'];
            $role = $resolved['key']['roles'][0] ?? null;
            PrincipalContext::set(new Principal($owner['id'], $owner['username'], 'api_key', $role, false, null, true, $this->authorization->permissionsForRoles([$role])));
            return true;
        }
        if ($this->apiKeys->authenticate($provided)) {
            $roles = $this->authorization->legacyApiKeyRoles();
            $role = $roles[0] ?? null;
            PrincipalContext::set(new Principal(null, 'legacy-api-key', 'api_key', $role, false, null, true, $this->authorization->permissionsForRoles($roles)));
            return true;
        }
        $this->logger->audit('auth.api_key', 'failure', 'NOTICE', [
            'actorType' => 'api_key',
            'fingerprint' => substr(hash('sha256', $provided), 0, 12),
            'reason' => 'invalid_credential',
            'component' => 'authentication',
        ]);
        return false;
    }

    private function authenticationRequired(): never
    {
        (new OperationalLogger())->warning('api', 'API authentication failed', ['error_code' => 'AUTHENTICATION_REQUIRED']);
        $identity = 'anonymous:' . SecurityConfiguration::clientIp();
        try {
            $this->unauthenticatedRateLimiter->consume($identity);
        } catch (ApiRequestException $exception) {
            if ($exception->getErrorCode() === 'RATE_LIMIT_EXCEEDED') {
                $this->logger->audit('rate_limit.api', 'rejected', 'WARNING', [
                    'identityType' => 'anonymous',
                    'identityHash' => substr(hash('sha256', $identity), 0, 16),
                    'component' => 'api_protection',
                ]);
            }
            throw $exception;
        }
        throw new ApiRequestException(
            'Authentication required.',
            'AUTHENTICATION_REQUIRED',
            [],
            401
        );
    }
}
