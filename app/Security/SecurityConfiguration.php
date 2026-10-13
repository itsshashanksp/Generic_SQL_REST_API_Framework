<?php

require_once __DIR__ . '/../Repositories/AdminConfigurationRepository.php';
require_once __DIR__ . '/../Configuration/RuntimeControls.php';

final class SecurityConfiguration
{
    public static function isProduction(): bool
    {
        return strtolower((string)(getenv('GENERIC_APP_ENV') ?: 'development')) === 'production';
    }

    /**
     * Anonymous (`none`) API authentication is refused in production unless
     * the deployment explicitly opts in with GENERIC_ALLOW_ANONYMOUS_PRODUCTION=true.
     */
    public static function anonymousAuthenticationPermitted(): bool
    {
        return !self::isProduction() || getenv('GENERIC_ALLOW_ANONYMOUS_PRODUCTION') === 'true';
    }

    public static function allowedOrigins(): array
    {
        $configured = trim((string)(getenv('GENERIC_API_ALLOWED_ORIGINS') ?: ''));
        if ($configured !== '') {
            $origins = [];
            foreach (array_filter(array_map('trim', explode(',', $configured))) as $origin) {
                try {
                    $normalized = AdminConfigurationRepository::normalizeOrigin($origin);
                    if ($normalized === $origin && !in_array($origin, $origins, true)) {
                        $origins[] = $origin;
                    }
                } catch (InvalidArgumentException $exception) {
                    // Invalid deployment overrides fail closed instead of being reflected.
                }
            }
            return $origins;
        }

        return self::configuration()['cors']['allowedOrigins'];
    }

    public static function corsCredentialsEnabled(): bool
    {
        return self::configuration()['cors']['credentialsEnabled'];
    }

    public static function corsAllowedMethods(): array
    {
        return self::configuration()['cors']['allowedMethods'];
    }

    public static function authenticationMode(): string
    {
        return self::configuration()['authentication']['mode'];
    }

    public static function sessionOptions(): array
    {
        $configured = self::runtime()['session'];
        $idle = self::integerEnvironment(
            'GENERIC_SESSION_IDLE_TIMEOUT',
            $configured['idleTimeoutSeconds'],
            RuntimeControls::SESSION_IDLE_MINIMUM,
            RuntimeControls::SESSION_TIMEOUT_MAXIMUM
        );
        $absolute = self::integerEnvironment(
            'GENERIC_SESSION_ABSOLUTE_TIMEOUT',
            $configured['absoluteTimeoutSeconds'],
            RuntimeControls::SESSION_ABSOLUTE_MINIMUM,
            RuntimeControls::SESSION_TIMEOUT_MAXIMUM
        );
        if ($idle > $absolute) {
            $idle = $configured['idleTimeoutSeconds'];
            $absolute = $configured['absoluteTimeoutSeconds'];
        }
        return [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => self::isProduction() || self::directHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
            'idleTimeout' => $idle,
            'absoluteTimeout' => $absolute,
        ];
    }

    public static function loginRateLimitOptions(): array
    {
        $configured = self::runtime()['rateLimit']['login'];
        return [
            // Standalone CLI jobs have no remote client boundary. The built-in
            // web server reports cli-server, so live API protection remains on.
            'enabled' => $configured['enabled'] && PHP_SAPI !== 'cli',
            'maximumAttempts' => self::integerEnvironment('GENERIC_LOGIN_MAX_ATTEMPTS', $configured['maximumAttempts'], RuntimeControls::LOGIN_ATTEMPTS_MINIMUM, RuntimeControls::LOGIN_ATTEMPTS_MAXIMUM),
            'windowSeconds' => self::integerEnvironment('GENERIC_LOGIN_WINDOW_SECONDS', $configured['windowSeconds'], RuntimeControls::LOGIN_WINDOW_MINIMUM, RuntimeControls::RATE_WINDOW_MAXIMUM),
            'lockoutSeconds' => self::integerEnvironment('GENERIC_LOGIN_LOCKOUT_SECONDS', $configured['lockoutSeconds'], RuntimeControls::LOGIN_LOCKOUT_MINIMUM, RuntimeControls::RATE_WINDOW_MAXIMUM),
        ];
    }

    public static function queryTimeoutSeconds(): int
    {
        return self::integerEnvironment(
            'DB_QUERY_TIMEOUT_SECONDS',
            self::runtime()['query']['timeoutSeconds'],
            RuntimeControls::QUERY_TIMEOUT_MINIMUM,
            RuntimeControls::QUERY_TIMEOUT_MAXIMUM
        );
    }

    public const MAX_RESULT_ROWS_DEFAULT = 10000;
    public const MAX_RESULT_ROWS_MAXIMUM = 1000000;

    /** Maximum rows an unpaginated data read may return (GENERIC_MAX_RESULT_ROWS). */
    public static function maxResultRows(): int
    {
        return self::integerEnvironment('GENERIC_MAX_RESULT_ROWS', self::MAX_RESULT_ROWS_DEFAULT, 1, self::MAX_RESULT_ROWS_MAXIMUM);
    }

    public static function apiRateLimitOptions(): array
    {
        return self::runtime()['rateLimit']['api'];
    }

    public static function requestOptions(): array
    {
        return self::runtime()['request'];
    }

    public static function runtime(): array
    {
        return self::configuration()['runtime'];
    }

    public static function clientIp(): string
    {
        $value = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return is_string($value) && $value !== '' ? substr($value, 0, 64) : 'unknown';
    }

    private static function directHttpsRequest(): bool
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        return $https === 'on' || $https === '1';
    }

    private static function configuration(): array
    {
        try {
            return (new AdminConfigurationRepository())->load();
        } catch (Throwable $exception) {
            $defaults = AdminConfigurationRepository::defaults();
            if (self::isProduction()) {
                $defaults['cors']['allowedOrigins'] = [];
            }
            return $defaults;
        }
    }

    private static function integerEnvironment(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = getenv($name);
        if ($value === false || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $default;
        }
        $value = (int)$value;
        return $value >= $minimum && $value <= $maximum ? $value : $default;
    }
}
