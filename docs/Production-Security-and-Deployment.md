# Production security and deployment

This is the production hosting reference for both supported deployment models:
Windows with IIS and PHP FastCGI, and Linux with Nginx and PHP-FPM. The complete
step-by-step Windows procedure is [Windows Server IIS deployment](Windows-IIS-Deployment.md);
the security model behind these requirements is
[Security model](security/Security-Model.md).

Production uses a web-server and FastCGI worker model. IIS or Nginx terminates HTTPS, redirects production HTTP to HTTPS, and is authoritative for HSTS and browser security headers. Certificates, private keys, hostnames, and trust policy remain deployment-owned values and are not stored in the repository.

## Development and production boundaries

```text
Development                         Production

start-windows.bat / start-linux.sh  Windows: HTTPS -> IIS -> FastCGI -> PHP
  -> HTTP php -S                    Linux:  HTTPS -> Nginx -> PHP-FPM -> PHP
  -> local Admin Console
  -> Admin-managed local API/parser
```

The launchers remain the supported local-development workflow and intentionally use PHP's built-in server. It is single-process and is not a production host.

Local development remains HTTP on `localhost`/`127.0.0.1`. It has no HTTPS redirect and no HSTS. The PHP entry points keep their existing local defense-in-depth headers when `GENERIC_APP_ENV` is not `production`.

Production web-server services are started, stopped, monitored, and restarted by IIS, Windows Service Control, systemd, Nginx, and PHP-FPM—not by `ApiProcessManager`, `SqlParserProcessManager`, or arbitrary Admin Console commands. The local child-process managers remain available only for development. When the applications are hosted by FastCGI, infrastructure monitoring is authoritative for the IIS/Nginx/FPM lifecycle; do not interpret the Admin Console's local child-process state as IIS or FPM worker state.

## Application boundaries and routes

Keep the applications as separate web-server sites, applications, or listeners. On Windows the documented layout is one HTTPS site whose root is the built frontend with `/api`, `/admin`, and `/sqlparser` IIS applications ([Windows Server IIS deployment](Windows-IIS-Deployment.md)); separate loopback sites for Admin and the parser, as in the Nginx example, are equally supported:

| Boundary | Production route | PHP entry point | Static files |
| --- | --- | --- | --- |
| Reporting/API | `/api` and `/api/index.php` | `Backend/api/index.php` | built frontend `dist/` only |
| Admin Console | loopback-only at its mount path, e.g. `/admin/`, `/admin/<page>`, `/admin/api.php` | `Backend/admin/index.php`, `Backend/admin/api.php` | `Backend/admin/assets/` |
| SQL Parser | loopback/internal only: an application path such as `/sqlparser/` or a separate internal site at `/` | `Backend/sqlparser/index.php` | `Backend/sqlparser/assets/` |

The development-only `router.php` files express the same route boundaries for `php -S`, but IIS and Nginx use their own fixed routing rules. They must not send arbitrary `.php` paths to FastCGI. SQL Parser remains database-free and does not inherit Admin or API authentication.

The Admin Console does not assume a public path. `admin/index.php` derives its mount path from the web server's `SCRIPT_NAME` (for example `/admin/index.php` gives `/admin`) and addresses its assets, pages, and `api.php` below that path on the same origin. It works at a site root, as an IIS application at `/admin` or any other path, and under the Nginx example's `/admin/` prefix. A reverse proxy that strips its public prefix before forwarding cannot be detected from the request, so set `GENERIC_ADMIN_BASE_PATH` (for example `/internal-admin`) in the Admin worker environment; invalid values are ignored. Host and forwarded headers are never used to build Admin URLs.

The Admin Console remains loopback-only. Both its application middleware and the provided web-server examples enforce that boundary. Remote production administration needs a separately designed, authenticated access path and is not introduced here.

## HTTPS and security-header ownership

Production IIS/Nginx configuration is the source of truth for these response headers on static files and PHP responses:

- `Strict-Transport-Security: max-age=31536000`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: no-referrer`
- `X-Frame-Options: DENY`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- a boundary-specific `Content-Security-Policy`

HSTS is sent only by HTTPS application sites, not HTTP redirect sites or local development. `includeSubDomains` is deliberately absent because the framework cannot assert that every deployment subdomain is HTTPS-only. `preload` is deliberately absent because browser preload enrollment is difficult to reverse and must be a separate organization-wide decision.

`frame-ancestors 'none'` is the modern clickjacking control; `X-Frame-Options: DENY` is the compatible fallback and has the same intent. No current page requires framing. `no-referrer` prevents full paths and query data from crossing origins. The permissions policy disables only camera, microphone, and geolocation, which the inspected frontend, Admin Console, and SQL Parser do not use.

### Content Security Policy

| Boundary | Important directives | Reason |
| --- | --- | --- |
| React frontend | `script-src 'self'`; `style-src 'self' 'unsafe-inline'`; same-origin connections | Vite emits external scripts, but React style attributes and Emotion/MUI inject runtime styles. Inline/eval scripts and wildcards remain forbidden. |
| Admin Console | same-origin scripts, styles, images, and API connections | All Admin resources are local external files; no inline allowance is needed. |
| SQL Parser | same-origin scripts, styles, images, and connections | Parser resources are local and independently hosted. |
| API | `default-src 'none'` | JSON responses require no browser resource loading. |

All policies deny plugins/objects and framing. Browser console CSP violations must be reviewed during staging; do not solve violations by adding `script-src 'unsafe-inline'`, `'unsafe-eval'`, or `*`.

The application continues to own content type, cache behavior, exact-origin CORS, credentials, CSRF, and JSON errors. Production PHP suppresses its duplicate browser security headers because IIS/Nginx supplies them. This division preserves local `php -S` behavior without conflicting production header values.

## Filesystem layout and permissions

Keep the repository/backend outside the public frontend document root. A representative layout is:

```text
/srv/generic-reporting/                 C:\GenericReporting\
  Frontend/.../dist/                      Frontend\...\dist\
  Backend/                                Backend\
    api/ admin/ sqlparser/                  api\ admin\ sqlparser\
    app/ core/ database/ config/            app\ core\ database\ config\
    logs/ runtime/ storage/                  logs\ runtime\ storage\
```

Apply least privilege to the PHP service identity:

| Path | Web addressable | PHP service permission |
| --- | ---: | --- |
| frontend `dist/` | yes, static only | read |
| `api/index.php` | fixed FastCGI target only | read/execute |
| `admin/index.php`, `admin/api.php`, `admin/assets/` | loopback site only | read/execute |
| `sqlparser/index.php`, `sqlparser/assets/` | selected internal listener | read/execute |
| `app/`, `core/`, `database/drivers/`, `queries/` | no | read |
| `config/` (shipped PHP configuration and allowlists) | no | read only |
| runtime configuration directory (`GENERIC_RUNTIME_CONFIG_DIR`, outside the code tree) | no | read/write |
| `database/config/` | no | read/write (encrypted `databases.json` registry saved by the Admin Console) |
| `logs/`, `runtime/`, `storage/security/`, PHP session directory | no | read/write |
| `runtime/windows/`, `runtime/linux/` (development PHP runtimes) | no | read only |
| `runtime/secrets/` or external secret store | no | narrowly restricted read |
| `.git/`, backups, temporary files | never | no web-server access |

Do not place `.env`, keys, logs, JSON configuration, backups, or repository metadata under a static document root. The supplied configurations use fixed public roots and fixed FastCGI targets as defense in depth.

Users, password and API-key hashes, roles, Admin settings, availability state, and their lock files are runtime configuration. Store them in a dedicated directory outside the code tree, named by `GENERIC_RUNTIME_CONFIG_DIR` for every boundary and every command-line script, rather than in `Backend/config`. `Backend/config` holds PHP configuration the application executes (`app.php`, `constants.php`, and the SQL Resource discovery settings); it must stay read-only to the worker so that a file-write flaw, or anyone acting as that identity, cannot change executable configuration. The bundled development runtimes are code too and are not used by IIS or PHP-FPM. Run `php scripts/validate-production.php` to confirm the runtime configuration directory is outside the code tree.

## PHP runtime requirements

Use a maintained PHP 8.2-or-newer runtime compatible with the project's tested syntax. Production PHP requires:

- JSON and session support;
- OpenSSL for AES-256-GCM database configuration;
- ODBC plus a compatible Microsoft SQL Server ODBC driver for API database access;
- OPcache for production execution;
- optional `mbstring` for exact multibyte write-length validation (the application has a safe byte-length fallback).

The SQL Parser itself does not require ODBC or database credentials. If one shared FPM pool or IIS FastCGI application hosts all boundaries, its runtime still needs the union of required extensions.

Merge [`deployment/php-production-security.ini`](../deployment/php-production-security.ini) into the installed production `php.ini`; do not replace distribution extension configuration blindly. The example disables displayed errors and uploads, enables server-side error logging and OPcache, uses UTC, and supplies bounded example memory/request/execution values. Confirm the effective configuration through an offline administrative command, not a public `phpinfo()` page.

### OPcache deployment behavior

The template uses `opcache.validate_timestamps=0`. After an atomic code deployment, recycle the IIS application pool or reload/restart PHP-FPM so workers cannot execute stale bytecode. If an operator chooses timestamp validation instead, select the revalidation interval as an operational policy and test its deployment consistency. OPcache sizing and worker counts must be measured from the deployed code and workload; the repository does not claim universal production sizing values.

## Windows: IIS and PHP FastCGI

The complete installation procedure (IIS role services, URL Rewrite, PHP NTS
x64, `php.ini`, ODBC driver, application pool, NTFS permissions, FastCGI
registrations, site and applications, `web.config` files, HTTPS, firewall, SQL
Server permissions, first-run Admin setup, verification, updates, and rollback)
is [Windows Server IIS deployment](Windows-IIS-Deployment.md). The principles it
implements are:

- Use a supported 64-bit **Non-Thread-Safe** PHP runtime with its Visual C++
  runtime; the bundled `runtime/windows/php` build is for the development
  launcher only. Enable `odbc`, `openssl`, `session`, and OPcache, and merge
  `deployment/php-production-security.ini` into the production `php.ini`.
- Register one dedicated `C:\PHP\php-cgi.exe` FastCGI application per boundary,
  distinguished by the `-d generic_sql_api.boundary=api|admin|sqlparser`
  arguments used in the matching `deployment/iis/*.web.config.example` handler.
  Set `PHPRC` and `GENERIC_APP_ENV=production` explicitly on every registration;
  production mode is never assumed to be inherited from a machine-wide or
  application-pool default. Add `GENERIC_ADMIN_ENABLED=1` only to the Admin
  registration and the database encryption key only to the API and Admin
  registrations. The template comments contain the `appcmd.exe` commands.
- The templates declare their FastCGI handler and (Admin) IP restrictions, so
  the `system.webServer/handlers` and `system.webServer/security/ipSecurity`
  sections must be delegated, and IIS URL Rewrite must be installed.
- Use a dedicated, non-administrator application-pool identity and grant only
  the filesystem permissions listed above.
- Bind HTTPS with an exact hostname and certificate; never use a catch-all
  production certificate binding. HTTP, if accepted at all, is a separate
  fixed-host redirect site using `deployment/iis/http-redirect.web.config.example`;
  its `{HTTPS}=OFF` condition prevents redirect loops.
- Keep Admin loopback-only and restrict or omit the SQL Parser on any site with
  a public binding.
- Build the frontend with `VITE_API_URL=/api`. `VITE_*` values are public; never
  place secrets in them.

The templates disable directory listing, allow only the intended entry
points/assets, cap request size, prevent access to parser source/common
sensitive extensions, and install boundary-specific security headers. Keep
detailed IIS errors local and let PHP application responses pass through
unchanged.

IIS TLS protocol/cipher policy is controlled by Windows Schannel, not these application `web.config` files. Enable TLS 1.2 and TLS 1.3 where the installed Windows/IIS version supports them, disable SSL and obsolete TLS versions through the approved operating-system policy, then reboot/restart as required by that policy. Do not copy registry values without validating the target Windows release.

Use any organization-approved public or private certificate issuer. Automate renewal using the issuer/tooling appropriate to the host, bind the renewed certificate, and verify the live binding before removing the old certificate. Monitor expiry and validate hostname/SAN, chain, key access, and client trust from a separate client.

IIS FastCGI starts and maintains multiple `php-cgi.exe` workers through the application pool. Configure queue length, instance limits, idle timeouts, and recycling from measured request duration, memory usage, and database capacity. The project's query timeout and request limits are application controls, not worker-pool sizing recommendations.

Validate on Windows after installing the examples:

```bat
C:\Windows\System32\inetsrv\appcmd.exe list config /section:system.webServer/fastCgi
C:\Windows\System32\inetsrv\appcmd.exe list site
%windir%\system32\inetsrv\appcmd.exe list apppool
```

Recycle the application pool after code or OPcache configuration changes. Review IIS access and failed-request logs without enabling detailed remote error pages.

## Linux: Nginx and PHP-FPM

1. Install Nginx, PHP-FPM 8.2 or newer, OPcache, OpenSSL/session/JSON support, PHP ODBC, and the Microsoft SQL Server ODBC driver.
2. Deploy the application outside Nginx's public frontend root and assign it to a dedicated service account/group.
3. Configure a PHP-FPM pool socket owned by the Nginx worker group. Replace `/run/php/php-fpm.sock` in `deployment/nginx/generic-sql-api.linux.example.conf` with the distribution's actual socket.
4. Put secrets and environment values in the service manager or FPM pool environment, subject to the host's `clear_env` policy. Do not put secrets in the Nginx file.
5. Obtain certificate chains and private keys covering the exact public/Admin/Parser hostnames. Store private keys outside web roots with narrowly restricted ownership and permissions.
6. Copy the four `security-headers.*.example.conf` files to the `/etc/nginx/snippets/` names referenced by the server template.
7. Replace every `REPLACE_WITH_*` certificate/key placeholder, example hostname, path, and socket. Install the server block and run `nginx -t` before reload.
8. Merge the production PHP INI fragment, validate `php-fpm -t`, then reload/restart PHP-FPM so OPcache and environment changes apply.

The Nginx example has fixed-host HTTP redirect blocks and three independent HTTPS application blocks. The public block serves only built frontend files and fixed `/api` targets. Admin and SQL Parser default to loopback listeners. There is no generic `location ~ \.php$`; internal PHP files therefore cannot become executable merely because they exist. TLS is restricted to TLS 1.2 and TLS 1.3, and session tickets are disabled in the example.

Certificate paths are intentionally placeholders. Renewal may be performed by any approved ACME/client/PKI workflow, but it must atomically update the configured chain/key, pass `nginx -t`, reload Nginx, and confirm the served certificate. Never make the private key readable by the web content user unless the service design explicitly requires it.

PHP-FPM pool mode and `pm.max_children` are infrastructure sizing choices. Determine them from per-worker memory, CPU, database connection capacity, request latency, and desired queueing. Monitor FPM saturation and Nginx upstream timing before changing them. Multiple FPM workers provide request concurrency; synchronous ODBC work still occupies one worker per active request.

## Runtime concurrency and resource ownership

Each PHP request constructs its own controllers, repositories, `QueryEngine`, ODBC driver, connection, and statements. Persistent ODBC connections and application-level connection pooling are not used. Statements are released in `finally`, including preparation, execution, fetch, and timeout failures; the request-owned connection closes when the engine is explicitly closed or destroyed. A driver-level operation blocked inside `odbc_execute` cannot be safely cancelled by another request, so production worker, proxy, and SQL Server timeout behavior must also be validated under load.

Runtime JSON, authentication, API-key, availability, process-state, and rate-limit files are single-host state. JSON readers and writers coordinate through adjacent I/O lock files; writers encode and validate complete values before replacement, and the Windows overwrite fallback remains hidden behind the same lock. Read-modify-write repositories, application-runtime lifecycle changes, and rate-limit identities use additional operation locks so concurrent mutations are serialized. Malformed rate-limit records recover to an empty bounded window; malformed application runtime state fails requests closed with a sanitized 503, while malformed configuration remains a controlled error and never silently replaces valid configuration.

The runtime configuration directories must therefore permit the PHP identity to create narrowly permissioned `*.lock` and temporary files even when configuration changes are rare. Lock files contain no secrets. Keep all participating IIS FastCGI or PHP-FPM workers on the same local filesystem. These advisory file locks are not distributed locks and do not make the deployment safe across multiple application hosts or network filesystems with unreliable locking semantics.

API and login rate limiting is exact only for workers sharing that local filesystem. Concurrent counter updates are serialized per hashed identity; login reset uses the same identity lock and cannot race a failed-attempt write. Development process start, stop, status recovery, and restart are serialized per managed local service. Restart holds one lifecycle lock across stop and start, verifies forced termination before clearing state, and supports Linux runtimes without the POSIX extension through the host `kill` utility. In production, a separate command-free application-runtime manager atomically serializes Enable, Disable, and Reload state. Neither path manages IIS, Nginx, FastCGI, PHP-FPM, systemd, Windows services, or SQL Server.

PHP's file-session handler normally locks one session while a request is active. Concurrent requests carrying the same browser session may therefore serialize until the first request writes/closes the session; requests using different sessions and API-key requests are independent. This expected consistency behavior is not application-wide serialization. Long authenticated requests should be included in staging load tests before changing session-lock behavior.

Production load testing remains deployment-owned. Exercise representative reads, pagination counts, writes, authentication bursts, distinct and same-session traffic, API-key traffic, configured rate limits, database blocking, timeout paths, worker saturation, memory, file-lock latency, log throughput, and client/proxy disconnects. Repository concurrency tests use isolated local processes and fake/database-independent execution; they do not establish IIS/FPM sizing or live SQL Server capacity.

## Environment and secrets

Set server-side values on the IIS FastCGI application/application-pool identity or PHP-FPM service/pool:

```text
GENERIC_APP_ENV=production
GENERIC_RUNTIME_CONFIG_DIR=<absolute path to a runtime state directory outside Backend, e.g. /srv/generic-reporting/state/config>
GENERIC_FRONTEND_DASHBOARD_CONFIG_DIR=<absolute path to the deployed frontend dashboard JSON directory>
GENERIC_SQL_API_ENCRYPTION_KEY=<secret supplied outside the repository>
GENERIC_SQL_API_KEY=<legacy key only when that authentication path is used>
```

Optional validated overrides such as `GENERIC_API_ALLOWED_ORIGINS`, session/login limits, and `DB_QUERY_TIMEOUT_SECONDS` retain their documented behavior. Prefer the Admin Console runtime settings (stored in `admin.json` in the runtime configuration directory) unless deployment automation intentionally owns an environment override.

The encryption key must be available to the PHP worker identity but stored separately from `database/config/databases.json`. Never place credentials, keys, session data, or real production hostnames in repository templates.

On IIS, provision the key outside the site content and grant the application-pool
identity only the access it needs. Apply NTFS ACLs to `database/config`,
`runtime/secrets`, the runtime configuration directory, sessions, and logs, and
keep `Backend/config` read-only for the pool; inherited broad read access is
not an acceptable production default. Do not put the key in `web.config`, a
batch file, an IIS-visible directory, or a process command line. Recycle the
FastCGI application pool after changing its environment and verify the effective
identity can decrypt configuration without returning any secret in diagnostics.

On Linux, provide the key through the PHP-FPM service/pool environment using a
root-controlled deployment mechanism outside the repository and document the
distribution-specific `clear_env` behavior. The code tree, including
`Backend/config`, stays owned by the deployment account and read-only to the PHP
worker. The runtime configuration, session, log, and
runtime directories should be owned by the deployment account and PHP worker
group with the narrowest usable modes; secret files should be owner-only where
the service model permits. Never export the key in an interactive shell history
or pass it as a CLI argument. Restart PHP-FPM after rotation and validate the
effective pool environment without printing it.

Database configuration is written as an authenticated encrypted envelope using
owner-restricted files. Its adjacent lock file contains no secret. The complete
configuration is decrypted only in request memory for validation and ODBC
connection setup; Admin responses return safe metadata and a
`passwordConfigured` flag, never a password, ciphertext, connection string, or
encryption key. Application logs defensively redact common credential/header
forms, while database and Admin error sources continue to emit fixed sanitized
messages.

## Session storage and lifetime

Configure PHP `session.save_path` as a dedicated directory outside every frontend or backend web root. It must be readable and writable only by the PHP FastCGI/FPM worker identity and the operating-system account responsible for session cleanup; it must never be served by IIS/Nginx or included in application logs or backups without equivalent secret-data controls. All workers serving the same application instance must use the same local session directory. Multi-host deployment would require a shared session store, which is not provided.

The application enforces cookie-only transport, strict mode, disabled transparent URL session IDs, and a garbage-collection lifetime equal to `runtime.session.absoluteTimeoutSeconds` before starting a session. The production INI contains the secure defaults as defense in depth. Verify the effective `session.save_path`, ownership, free space, and cleanup mechanism using an offline command under the actual worker identity. Some distributions use an operating-system cleanup job instead of request-probability garbage collection; its retention threshold must be at least the configured absolute timeout while still removing expired files.

Idle and absolute expiration are checked against server-side timestamps. Successful login regenerates the session identifier and deletes the prior server-side session. Logout and timeout clear state, expire the host-only cookie, and destroy the server-side session. Username, password, enabled-state, or authorization changes increment the stored authentication version or remove the identity, so the next protected request destroys the stale session.

## Cookies, CORS, CSRF, and trusted proxies

Production sessions use a host-only cookie with path `/`, no `Domain` attribute, no persistent lifetime, `Secure`, `HttpOnly`, and `SameSite=Lax`. Root path is intentional because the SPA, `/api`, and Admin entry points share the session architecture; the host-only scope prevents exposure to sibling subdomains. Local HTTP development omits `Secure` but retains every other protection. Keep frontend and API on the same `localhost` or `127.0.0.1` hostname—the frontend client normalizes those two development aliases because cookies are hostname-scoped and ports do not create separate cookie scopes.

CSRF tokens are generated from 256 bits of randomness, stored only in the PHP session, and compared in constant time. Login rotates the token after session-ID regeneration; logout and expiration destroy it with the session. The frontend and Admin clients reject stale response-token updates so an older concurrent response cannot replace a newer token. Session-authenticated mutations require the token, while API-key requests remain independent and do not create browser-session authorization. Exact-origin CORS remains application-owned; configure the production HTTPS origin exactly and never replace it with `*`. Browsers send an `Origin` header on same-origin `fetch` POSTs too, and the API rejects any origin that is not listed with `403 CORS_ORIGIN_DENIED`, so the production origin (for example `https://reports.example.internal`) must be added under Admin → Configuration → Security → CORS, or supplied through `GENERIC_API_ALLOWED_ORIGINS`, even when the frontend and API share a host.

The supplied templates terminate TLS directly at IIS/Nginx and pass the authoritative server HTTPS state to FastCGI (`HTTPS=on` in Nginx). The PHP application does not trust `X-Forwarded-Proto`, `X-Forwarded-Host`, or `Forwarded` from arbitrary clients. This prevents spoofed forwarded headers from changing cookie or redirect behavior.

The loopback Admin gates and rate-limit identities use `REMOTE_ADDR` only. Never place the Admin Console (or a same-host reverse proxy such as IIS ARR, a local TLS terminator, or a tunnel agent) in front of these entry points so that remote clients arrive as `127.0.0.1`; that would make every client appear local and share one rate-limit identity. The supplied templates use direct FastCGI, where `REMOTE_ADDR` is the real client.

If an approved load balancer terminates TLS before IIS/Nginx, make that edge responsible for the HTTP-to-HTTPS redirect and overwrite—not append—forwarded metadata. Configure IIS ARR or Nginx real-IP/proxy handling to trust only explicit load-balancer addresses. Do not retain the origin redirect if it sees every trusted edge request as HTTP, or it will loop. The current application does not require forwarded-protocol trust because production cookies are Secure by environment and redirects occur at the trusted web layer.

## Error handling and logging

`api/index.php` disables displayed errors in production, and `ExceptionHandler` preserves the safe JSON error envelope and request correlation ID. The web server must pass application error responses through rather than replace them with detailed remote pages.

Keep separate logs with separate rotation policy:

- IIS or Nginx access/error logs for request and upstream failures;
- PHP-FastCGI/PHP-FPM error logs for runtime/startup failures;
- `Backend/logs/{api,admin,database,sqlparser}/YYYY-MM-DD.txt` for human-readable
  operational diagnostics and request IDs;
- `Backend/logs/audit/YYYY-MM-DD.jsonl` for structured security audit events.

Grant the PHP identity write access to application/PHP log targets and deny browser access. Rotate and retain logs according to volume and organizational policy. Existing application logging records parameter counts/types rather than values; operators must also avoid adding passwords, encryption keys, API keys, cookies, authorization headers, session identifiers, or raw credentials to web-server log formats.

Audit taxonomy, redaction, file modes, and rotation responsibilities are in
[Logging](Logging.md). No centralized collector or SIEM is configured.

Backup scope, signing and key custody, and restore are in
[Backup and recovery](Backup-and-Recovery.md); keep backup bundles outside all
document roots and the repository. Liveness, readiness, and detailed health are
in [Monitoring and health](Monitoring-and-Health.md). The error envelope and
failure handling are in [Errors and validation](Errors-and-Validation.md).

## Deployment verification

1. Bootstrap runtime configuration offline and provision the encryption key through the service identity.
2. Verify PHP version and required extensions from the same FastCGI/FPM installation used by the web server.
3. Validate IIS bindings/Schannel policy on Windows or run `nginx -t` and `php-fpm -t` on Linux.
4. Start/recycle the web-server and worker services through the operating system.
5. Verify every HTTP binding permanently redirects once to its fixed HTTPS hostname without a loop.
6. Verify frontend history fallback, `POST /api`, Admin loopback pages, assets, and `api.php` under the Admin mount path (`/admin/` in the examples), and the loopback-restricted SQL Parser and its assets over HTTPS.
7. Verify non-entry-point PHP files, `config/`, `database/config/`, `logs/`, `runtime/`, `storage/`, `.git/`, `.env`, backups, and temporary files are unreachable.
8. Inspect frontend, API, Admin, Parser, static-asset, error, and redirect responses for the intended headers; HSTS must occur only over HTTPS.
9. Exercise authentication, session-ID regeneration, logout/replay rejection, idle/absolute expiration, Secure/HttpOnly/SameSite host-only cookies, CSRF rotation, exact-origin CORS, authorization, API-key separation, database availability, SQL resource reads, and an allowed CRUD operation in staging.
10. Validate certificate hostname/SAN, chain, expiry, TLS 1.2/1.3, rejected obsolete protocols, and renewal/reload behavior from a separate client.
11. Confirm production responses contain no PHP warnings, filesystem paths, stack traces, SQL credentials, private-key paths, or secrets.
12. Review permissions and recycle workers after deployment before serving traffic.

## Troubleshooting

- **502/500 from IIS or Nginx:** verify the FastCGI executable/socket, service identity, PHP error log, and entry-point filesystem access.
- **404 for a valid route:** confirm the expected application/site boundary and install IIS URL Rewrite where applicable.
- **Admin returns 404:** access it from loopback, set `GENERIC_ADMIN_ENABLED=1` only for that Admin FastCGI boundary, and verify the web-server loopback restriction.
- **ODBC unavailable:** enable PHP ODBC and install a driver supported by `SqlServerDriver` for the worker architecture.
- **Encrypted configuration unavailable:** restore the external encryption key for the worker identity; never generate a replacement for existing ciphertext.
- **Stale code after deployment:** recycle the IIS application pool or reload/restart PHP-FPM because production OPcache timestamp checks are disabled.
- **Requests serialize or queue:** confirm traffic is not using `php -S`, then inspect IIS FastCGI/FPM worker saturation and downstream database capacity.
- **Permission failures:** verify code and `config/` are readable while only the runtime configuration directory, `database/config`, logs, runtime, storage, and session locations that actually require mutation are writable.
- **Redirect loop:** confirm TLS terminates on the server applying the redirect. If a trusted edge terminates TLS, move the redirect there and restrict forwarded-header trust to that edge.
- **Browser CSP violation:** identify the exact blocked resource. Do not add script wildcards, `'unsafe-eval'`, or inline-script allowances; update the narrow boundary policy only when the application genuinely requires it.
- **Secure cookie not returned:** confirm the browser is using the intended HTTPS hostname and the certificate is trusted; production cookies are intentionally not usable over plain HTTP.
- **Session disappears early:** verify all workers use the same `session.save_path`, the directory remains writable, and the operating-system cleanup threshold is not shorter than `runtime.session.absoluteTimeoutSeconds`.
- **Local CSRF mismatch:** use the same hostname form for the browser and API. `localhost` and `127.0.0.1` are different cookie hosts even when they resolve to the same machine.
- **Wrong/expired certificate:** verify the active IIS binding or Nginx chain/key placeholders, SANs, renewal job, service read permissions, and successful reload.

Live IIS/FastCGI, Schannel, Nginx/PHP-FPM, certificates, private-key permissions, renewal, client trust, DNS, firewall, and protocol negotiation must be verified on target hosts. Repository tests validate template structure, redirect guards, header policies, application fallbacks, and sensitive-path intent but cannot prove a live TLS deployment. Review the accepted risks in [Security model](security/Security-Model.md#accepted-risks-and-deployment-responsibilities), especially resource-level rather than per-column read authorization and single-host state, before production use.

## Operator checklists

Run `php scripts/validate-production.php` for a non-mutating discovery and
static-validation report. It never installs services, changes configuration,
connects to SQL Server, or prints secrets. A passing template check is never a
substitute for the target-host checks below. Replace example paths and hosts,
and never paste secrets into a command line or transcript.

### Windows / IIS

1. Record Windows and IIS capability, and confirm CGI/FastCGI, URL Rewrite, and
   IP restrictions are installed:

   ```powershell
   Get-ComputerInfo | Select-Object WindowsProductName,WindowsVersion,OsBuildNumber
   Get-WindowsFeature Web-Server,Web-CGI,Web-IP-Security
   & "$env:windir\System32\inetsrv\appcmd.exe" list modules
   ```

2. Parse the templates before deployment:

   ```powershell
   Get-ChildItem .\deployment\iis\*.web.config.example |
     ForEach-Object { [xml](Get-Content -Raw $_.FullName) | Out-Null }
   ```

3. Validate the installed FastCGI runtime, not the bundled development CLI:

   ```powershell
   C:\PHP\php-cgi.exe -c C:\PHP\php.ini -v
   C:\PHP\php-cgi.exe -c C:\PHP\php.ini -m
   C:\PHP\php-cgi.exe -c C:\PHP\php.ini -i |
     Select-String 'display_errors|display_startup_errors|log_errors|expose_php|session.save_path|opcache.enable'
   ```

4. Use `icacls` to confirm the dedicated application-pool identity has the
   permissions in [Filesystem layout and permissions](#filesystem-layout-and-permissions):
   code and `Backend\config` read-only, configuration and key access restricted,
   runtime, logs, and sessions writable, backups outside web roots, and no broad
   `Everyone` or inherited write access.

5. Validate IIS configuration and bindings:

   ```powershell
   & "$env:windir\System32\inetsrv\appcmd.exe" list apppool
   & "$env:windir\System32\inetsrv\appcmd.exe" list site
   & "$env:windir\System32\inetsrv\appcmd.exe" list config /section:system.webServer/fastCgi
   netsh http show sslcert
   ```

6. With trusted DNS and certificates, test without bypassing certificate checks:

   ```powershell
   curl.exe -i https://reports.example.internal/health/live
   curl.exe -i https://reports.example.internal/health/ready
   curl.exe -i https://reports.example.internal/api/not-a-route
   curl.exe -i -X GET https://reports.example.internal/api
   curl.exe -i -H "Content-Type: application/json" --data-binary "{broken" https://reports.example.internal/api
   ```

   Verify status codes, JSON content type, request IDs on errors, HSTS and
   security headers, and that no detailed IIS page or internal detail appears.
   Test Admin login, CSRF, logout, and the SQL Parser interactively without
   recording cookie or token values.

7. Validate the Schannel protocol and cipher policy and the certificate SAN and
   chain with approved Windows tooling. Recycle the application pool after
   code, secret, `php.ini`, or OPcache changes.

### Linux / Nginx / PHP-FPM

Commands use example version numbers; substitute the installed PHP version.

1. Record installed components and locate the real pool socket:

   ```sh
   nginx -V
   php-fpm8.4 -v
   php-fpm8.4 -tt
   systemctl status nginx php8.4-fpm
   grep -R '^[[:space:]]*listen[[:space:]]*=' /etc/php/*/fpm/pool.d
   ```

2. Replace every hostname, path, certificate, and socket placeholder, install the
   header snippets, then validate before reloading:

   ```sh
   nginx -t
   systemctl reload php8.4-fpm
   systemctl reload nginx
   ```

3. Inspect the effective PHP-FPM configuration under the worker identity:

   ```sh
   sudo -u www-data php -c /etc/php/8.4/fpm/php.ini -r '
   foreach (["display_errors","display_startup_errors","log_errors","expose_php","session.save_path","session.use_strict_mode","session.cookie_secure","opcache.enable"] as $k) echo $k,"=",ini_get($k),PHP_EOL;'
   ```

   Confirm the pool environment provides `GENERIC_APP_ENV=production`,
   `GENERIC_RUNTIME_CONFIG_DIR`, and encryption-key access without printing the
   key, accounting for the pool's `clear_env` policy.

4. Inspect ownership and permissions with `namei -l` and `stat`. Code must be
   read-only; configuration and key access restricted; runtime, log, and session
   paths writable; backups outside web roots. Do not recursively `chmod` or
   `chown` an unknown tree.

5. Test real HTTPS without `-k`:

   ```sh
   curl --fail-with-body -i https://reports.example.internal/health/live
   curl -i https://reports.example.internal/health/ready
   curl -i https://reports.example.internal/api/not-a-route
   curl -i -X GET https://reports.example.internal/api
   curl -i -H 'Content-Type: application/json' --data-binary '{broken' https://reports.example.internal/api
   openssl s_client -connect reports.example.internal:443 -servername reports.example.internal -verify_return_error </dev/null
   ```

   Confirm the TLS 1.2/1.3 policy, trusted chain and SAN, redirect, HSTS,
   boundary CSP, JSON application errors, `no-store`, and no PHP warning, path,
   SQL, or ODBC leakage.

6. With a staging System Administrator, validate Admin authentication,
   authorization, CSRF rejection and acceptance, secure host-only cookies,
   session-ID regeneration, and logout invalidation. Confirm the API and SQL
   Parser show externally managed infrastructure and that Enable/Disable/Reload
   change only application availability.

### SQL Server and operations

Under the real worker identity and environment, run
`php scripts/check-database.php` and the readiness endpoint. In an isolated
staging configuration, verify invalid credentials, an unreachable server,
encryption-key failure, and a controlled timeout, then restore the valid
encrypted configuration and key. Check that clients see safe categories and
request IDs and that server logs are redacted.

Exercise a small number of simultaneous liveness, readiness, and representative
authenticated requests to check worker responsiveness, body limits, PHP and
proxy timeouts, file locks, and log writes. This is a smoke test, not a load test
or capacity result. SQL Server backup and restore validation remains a separate
operator responsibility.
