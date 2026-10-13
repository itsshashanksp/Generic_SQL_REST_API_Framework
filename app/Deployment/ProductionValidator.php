<?php

require_once __DIR__ . '/../Security/DatabaseConfigurationResolver.php';
require_once __DIR__ . '/../Database/DatabaseRegistry.php';
require_once __DIR__ . '/../Security/DatabaseTransportSecurity.php';
require_once __DIR__ . '/../Configuration/RuntimeConfiguration.php';
require_once __DIR__ . '/../Authorization/AccessScope/AccessScopeConfiguration.php';
require_once __DIR__ . '/../Security/SecurityConfiguration.php';

final class ProductionValidator
{
    public const VALIDATED = 'VALIDATED';
    public const PARTIAL = 'PARTIALLY VALIDATED';
    public const NOT_EXECUTED = 'NOT EXECUTED';
    public const OPERATOR = 'REQUIRES OPERATOR VALIDATION';

    public function __construct(private ?string $root = null)
    {
        $this->root = rtrim($root ?? dirname(__DIR__, 2), '/\\');
    }

    public function report(): array
    {
        $windows = PHP_OS_FAMILY === 'Windows';
        $linux = PHP_OS_FAMILY === 'Linux';
        $nginx = $this->commandPath('nginx');
        $fpm = $this->firstCommand(['php-fpm', 'php-fpm8.4', 'php-fpm8.3', 'php-fpm8.2']);
        $iis = $windows && is_file((string)getenv('SystemRoot') . '\\System32\\inetsrv\\appcmd.exe');
        $checks = $this->staticChecks();
        return [
            'generatedAt' => gmdate(DATE_ATOM),
            'environment' => [
                'operatingSystem' => ['status' => self::VALIDATED, 'value' => PHP_OS_FAMILY,
                    'wsl' => $linux && str_contains(strtolower((string)@file_get_contents('/proc/version')), 'microsoft')],
                'php' => ['status' => self::VALIDATED, 'version' => PHP_VERSION, 'sapi' => PHP_SAPI],
                'extensions' => ['status' => self::VALIDATED, 'required' => $this->extensionStates()],
                'iis' => ['status' => $iis ? self::PARTIAL : self::NOT_EXECUTED,
                    'available' => $iis, 'reason' => $iis ? 'IIS tooling detected; live site validation is still operator-owned.' : 'IIS is unavailable in this environment.'],
                'nginx' => ['status' => $nginx !== null ? self::PARTIAL : self::NOT_EXECUTED,
                    'available' => $nginx !== null, 'reason' => $nginx !== null ? 'Nginx binary detected; deployed configuration was not assumed.' : 'Nginx is unavailable in this environment.'],
                'phpFpm' => ['status' => $fpm !== null ? self::PARTIAL : self::NOT_EXECUTED,
                    'available' => $fpm !== null, 'reason' => $fpm !== null ? 'PHP-FPM binary detected; no production pool was assumed.' : 'PHP-FPM is unavailable in this environment.'],
                'odbc' => ['status' => extension_loaded('odbc') ? self::VALIDATED : self::NOT_EXECUTED,
                    'available' => extension_loaded('odbc')],
                'sqlServer' => ['status' => self::NOT_EXECUTED, 'available' => false,
                    'reason' => 'No authorized live SQL Server target was supplied.'],
                'databaseTransport' => $this->databaseTransport(),
                'runtimeConfiguration' => $this->runtimeConfigurationLocation(),
                'anonymousAuthentication' => $this->anonymousAuthentication(),
                'accessScopes' => $this->accessScopes(),
                'tls' => ['status' => extension_loaded('openssl') ? self::PARTIAL : self::NOT_EXECUTED,
                    'reason' => 'Cryptographic capability does not validate a deployed certificate, hostname, or HTTPS binding.'],
            ],
            'staticValidation' => $checks,
            'liveValidation' => [
                'windowsIisFastCgi' => self::NOT_EXECUTED,
                'linuxNginxPhpFpm' => self::NOT_EXECUTED,
                'sqlServer' => self::NOT_EXECUTED,
                'trustedTls' => self::NOT_EXECUTED,
                'productionLoad' => self::NOT_EXECUTED,
            ],
        ];
    }

    private function staticChecks(): array
    {
        $files = [
            'iisApi' => 'deployment/iis/api.web.config.example',
            'iisAdmin' => 'deployment/iis/admin.web.config.example',
            'iisFrontend' => 'deployment/iis/frontend.web.config.example',
            'iisParser' => 'deployment/iis/sqlparser.web.config.example',
            'iisRedirect' => 'deployment/iis/http-redirect.web.config.example',
            'nginx' => 'deployment/nginx/generic-sql-api.linux.example.conf',
            'phpIni' => 'deployment/php-production-security.ini',
        ];
        $contents = [];
        foreach ($files as $name => $relative) {
            $path = $this->root . '/' . $relative;
            if (!is_file($path)) throw new RuntimeException("Required production template is missing: {$relative}.");
            $contents[$name] = (string)file_get_contents($path);
        }
        foreach (['iisApi', 'iisAdmin', 'iisFrontend', 'iisParser', 'iisRedirect'] as $name) {
            if (!$this->balancedXml($contents[$name])) throw new RuntimeException("IIS XML structure is invalid: {$files[$name]}.");
        }
        $nginxMarkers = [
            'fastcgi_pass generic_sql_api_php', 'ssl_protocols TLSv1.2 TLSv1.3',
            'return 308 https://reports.example.internal$request_uri',
            'location ~ ^/health/(?:live|ready)$', 'client_max_body_size 10m',
            'GENERIC_APP_ENV production', 'location ^~ /api/ { return 404; }',
        ];
        foreach ($nginxMarkers as $marker) {
            if (!str_contains($contents['nginx'], $marker)) throw new RuntimeException("Nginx template is missing: {$marker}.");
        }
        foreach (['Route health to API application', '/api/health/{R:1}'] as $marker) {
            if (!str_contains($contents['iisFrontend'], $marker)) throw new RuntimeException("IIS frontend health routing is missing: {$marker}.");
        }
        foreach (['Route API health', '^(?:index|health)\\.php$', 'maxAllowedContentLength="10485760"'] as $marker) {
            if (!str_contains($contents['iisApi'], $marker)) throw new RuntimeException("IIS API validation failed: {$marker}.");
        }
        foreach (['Strict-Transport-Security', 'X-Content-Type-Options', 'Content-Security-Policy'] as $header) {
            if (!str_contains($contents['iisApi'], $header)) throw new RuntimeException("IIS security header is missing: {$header}.");
        }
        $ini = parse_ini_file($this->root . '/' . $files['phpIni'], false, INI_SCANNER_TYPED);
        if (!is_array($ini)) throw new RuntimeException('Production PHP INI fragment is invalid.');
        foreach (['display_errors', 'display_startup_errors', 'expose_php', 'file_uploads'] as $setting) {
            if (($ini[$setting] ?? null) !== false) throw new RuntimeException("Production PHP setting must be disabled: {$setting}.");
        }
        foreach (['log_errors', 'opcache.enable', 'session.use_only_cookies', 'session.use_strict_mode', 'session.cookie_secure', 'session.cookie_httponly'] as $setting) {
            if (!in_array($ini[$setting] ?? null, [true, 1], true)) throw new RuntimeException("Production PHP setting must be enabled: {$setting}.");
        }
        if (!in_array($ini['session.use_trans_sid'] ?? null, [false, 0], true)
            || ($ini['session.cookie_samesite'] ?? null) !== 'Lax'
            || ($ini['date.timezone'] ?? null) !== 'UTC') {
            throw new RuntimeException('Production PHP session or timezone settings are invalid.');
        }
        $combined = implode("\n", $contents);
        if (preg_match('/BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY|password\s*=|GENERIC_SQL_API_ENCRYPTION_KEY\s*=/i', $combined)) {
            throw new RuntimeException('Production templates contain secret material.');
        }
        return [
            'templates' => self::VALIDATED,
            'iisXmlStructure' => self::PARTIAL,
            'nginxStructure' => self::VALIDATED,
            'phpProductionIni' => self::VALIDATED,
            'healthRoutes' => self::VALIDATED,
            'httpsRedirects' => self::VALIDATED,
            'securityHeaders' => self::VALIDATED,
            'requestLimits' => self::VALIDATED,
            'sensitivePathDenial' => self::VALIDATED,
            'secretScan' => self::VALIDATED,
            'note' => 'IIS XML is structurally checked here; use an XML parser and IIS tooling on the target Windows host.',
        ];
    }

    /**
     * Saved SQL Server transport settings of every server profile, reported
     * without any secret values or profile identities.
     */
    private function databaseTransport(): array
    {
        $registry = DatabaseRegistry::forLegacyPath(($this->root ?? dirname(__DIR__, 2)) . '/database/config/database.json');
        if ($registry->source() === DatabaseRegistry::SOURCE_NONE) {
            return ['status' => self::NOT_EXECUTED, 'reason' => 'No saved database configuration.'];
        }
        try {
            $warnings = [];
            foreach (array_keys($registry->metadata()['servers']) as $serverId) {
                $warnings = [...$warnings, ...DatabaseTransportSecurity::warnings($registry->serverConnection((string)$serverId))];
            }
            $warnings = array_values(array_unique($warnings));
        } catch (Throwable $exception) {
            return ['status' => self::NOT_EXECUTED, 'reason' => 'Saved database configuration could not be read.'];
        }
        return $warnings === []
            ? ['status' => self::VALIDATED, 'warnings' => []]
            : ['status' => self::OPERATOR, 'warnings' => $warnings];
    }

    /**
     * Runtime configuration (users, keys, settings) must not share the code
     * tree: Backend/config also holds executable PHP allowlists, which the
     * worker identity must not be able to modify. Reports no path.
     */
    private function runtimeConfigurationLocation(): array
    {
        $normalize = static function (string $path): string {
            $resolved = realpath($path);
            return rtrim(str_replace('\\', '/', $resolved === false ? $path : $resolved), '/') . '/';
        };
        $directory = $normalize(RuntimeConfiguration::directory());
        $root = $normalize((string)$this->root);
        $compare = PHP_OS_FAMILY === 'Windows' ? 'strncasecmp' : 'strncmp';
        if ($compare($directory, $root, strlen($root)) !== 0) {
            return ['status' => self::VALIDATED, 'location' => 'outside_code_tree'];
        }
        return ['status' => self::OPERATOR, 'location' => 'inside_code_tree',
            'reason' => 'Set GENERIC_RUNTIME_CONFIG_DIR to a directory outside the code tree so Backend/config can stay read-only for the PHP worker.'];
    }

    private function anonymousAuthentication(): array
    {
        try {
            $mode = SecurityConfiguration::authenticationMode();
        } catch (Throwable $exception) {
            return ['status' => self::NOT_EXECUTED, 'reason' => 'Authentication configuration could not be read.'];
        }
        if ($mode !== 'none') return ['status' => self::VALIDATED, 'mode' => $mode];
        return [
            'status' => self::OPERATOR,
            'mode' => $mode,
            'optIn' => getenv('GENERIC_ALLOW_ANONYMOUS_PRODUCTION') === 'true',
            'reason' => 'Anonymous mode is refused in production unless GENERIC_ALLOW_ANONYMOUS_PRODUCTION=true is an approved exception.',
        ];
    }

    /** Access scopes must be configured and enforced before production is fail-closed by design. */
    private function accessScopes(): array
    {
        $configuration = AccessScopeConfiguration::load(null, true);
        if ($configuration->state !== AccessScopeConfiguration::STATE_CONFIGURED) {
            return ['status' => self::OPERATOR, 'state' => $configuration->state,
                'reason' => 'Scoped data actions are denied until a valid access-scopes.json exists.'];
        }
        return $configuration->enforced()
            ? ['status' => self::VALIDATED, 'state' => $configuration->state, 'mode' => $configuration->mode]
            : ['status' => self::OPERATOR, 'state' => $configuration->state, 'mode' => $configuration->mode,
                'reason' => 'Log-only mode records would-be denials; switch to enforce after the scopes are reviewed.'];
    }

    private function extensionStates(): array
    {
        $states = [];
        foreach (['json', 'openssl', 'session', 'odbc', 'Zend OPcache'] as $extension) {
            $states[$extension] = extension_loaded($extension);
        }
        return $states;
    }

    private function firstCommand(array $commands): ?string
    {
        foreach ($commands as $command) {
            $path = $this->commandPath($command);
            if ($path !== null) return $path;
        }
        return null;
    }

    private function commandPath(string $command): ?string
    {
        $path = getenv('PATH');
        if (!is_string($path)) return null;
        $suffixes = PHP_OS_FAMILY === 'Windows' ? ['', '.exe', '.bat', '.cmd'] : [''];
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            foreach ($suffixes as $suffix) {
                $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $command . $suffix;
                if (is_file($candidate) && (PHP_OS_FAMILY === 'Windows' || is_executable($candidate))) return $candidate;
            }
        }
        return null;
    }

    private function balancedXml(string $xml): bool
    {
        $xml = (string)preg_replace('/<\?.*?\?>|<!--.*?-->/s', '', $xml);
        preg_match_all('/<\s*(\/)?([A-Za-z_][A-Za-z0-9_.:-]*)(?:\s[^<>]*?)?(\/)?\s*>/s', $xml, $matches, PREG_SET_ORDER);
        $stack = [];
        foreach ($matches as $match) {
            if (($match[3] ?? '') === '/') continue;
            if (($match[1] ?? '') === '/') {
                if (array_pop($stack) !== $match[2]) return false;
            } else $stack[] = $match[2];
        }
        return $stack === [];
    }
}
