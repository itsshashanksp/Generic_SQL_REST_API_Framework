# Windows Server IIS deployment

This is the step-by-step procedure for installing Framework
on a fresh Windows Server with IIS, PHP FastCGI, the Microsoft ODBC Driver for
SQL Server, and HTTPS. It is the authoritative **procedure**; the documents it
links to remain authoritative for the underlying behavior:

| Topic | Reference |
|---|---|
| Development launchers | [Local development](Local-Development.md) |
| Production security principles, headers, sessions, CORS, secrets | [Production Security and Deployment](Production-Security-and-Deployment.md) |
| Database registry, encryption, key rotation | [Database Configuration](Database-Configuration.md) |
| Upgrading a V2 installation | [Upgrading to V3](Upgrading-to-V3.md) |
| Admin Console pages and availability controls | [Admin Console](Admin-Console.md) |
| Liveness, readiness, System Health | [Monitoring and Health](Monitoring-and-Health.md) |
| Application backups and restore | [Backup and Recovery](Backup-and-Recovery.md) |
| Operational and audit logs | [Logging](Logging.md) |
| Security model and accepted risks | [Security model](security/Security-Model.md) |

Values written like `reports.example.internal`, `C:\GenericReporting`,
`ApplicationDb`, or `<SECRET>` are examples or placeholders. Replace them
consistently with your own values. Never paste a real password or key into a
document, ticket, script file, or command line.

## Contents

1. [Target architecture](#1-target-architecture)
2. [Prerequisites and planning](#2-prerequisites-and-planning)
3. [Install IIS](#3-install-iis)
4. [Install the IIS URL Rewrite module](#4-install-the-iis-url-rewrite-module)
5. [Install PHP](#5-install-php)
6. [Configure php.ini](#6-configure-phpini)
7. [Install the Microsoft ODBC Driver for SQL Server](#7-install-the-microsoft-odbc-driver-for-sql-server)
8. [Deploy the project files](#8-deploy-the-project-files)
9. [Create the application pool](#9-create-the-application-pool)
10. [Set NTFS permissions](#10-set-ntfs-permissions)
11. [Configure FastCGI and environment variables](#11-configure-fastcgi-and-environment-variables)
12. [Create the IIS site and applications](#12-create-the-iis-site-and-applications)
13. [Install web.config files](#13-install-webconfig-files)
14. [Configure HTTPS](#14-configure-https)
15. [Configure Windows Firewall](#15-configure-windows-firewall)
16. [Prepare SQL Server](#16-prepare-sql-server)
17. [First Admin Console setup](#17-first-admin-console-setup)
18. [Configure the database connection](#18-configure-the-database-connection)
19. [Verify the deployment](#19-verify-the-deployment)
20. [Logging](#20-logging)
21. [Backups and restore](#21-backups-and-restore)
22. [Updates, OPcache, and rollback](#22-updates-opcache-and-rollback)
23. [Troubleshooting](#23-troubleshooting)
24. [Final production checklist](#24-final-production-checklist)

## 1. Target architecture

One IIS site serves everything over HTTPS. The built reporting frontend is the
site root, and the three backend boundaries are separate IIS applications:

```text
https://reports.example.internal/            Frontend dist (static files)
https://reports.example.internal/api         Backend\api        (public JSON API)
https://reports.example.internal/admin/      Backend\admin      (loopback-only Admin Console)
https://reports.example.internal/sqlparser/  Backend\sqlparser  (developer tool, restrict to loopback)
https://reports.example.internal/health/live   -> /api/health/live
https://reports.example.internal/health/ready  -> /api/health/ready
```

Ownership is split deliberately:

| IIS and PHP FastCGI own | The application owns |
|---|---|
| HTTP/HTTPS listeners, bindings, certificates | API and SQL Parser **availability** (Enable/Disable/Reload) |
| `php-cgi.exe` worker processes and their lifecycle | Database **availability** (Connect/Disconnect) |
| Application-pool identity and recycling | Admin operations, users, roles, API keys |
| Static files, URL rewriting, security headers | Health reporting, configuration, logging, backups |

The Admin Console's Start/Stop/Reload buttons never start or stop IIS or
`php-cgi.exe`; they change application availability state, and a disabled API
returns a safe `503 SERVICE_UNAVAILABLE` while Admin and health routes stay
reachable. Database Connect/Disconnect is likewise an application gate: it never
starts or stops SQL Server, and there is no connection pool. Every API request
opens and closes its own ODBC connection. See
[Admin Console](Admin-Console.md).

A separate loopback-only site for Admin or the SQL Parser (as described in
[Production Security and Deployment](Production-Security-and-Deployment.md)) is
also supported; the templates work unchanged at either a site root or an
application path.

## 2. Prerequisites and planning

### 2.1 Server and access

- Windows Server 2019, 2022, or 2025 (x64), fully patched.
- A local Administrator account. Run every command below from an **elevated**
  PowerShell window: right-click **Windows PowerShell** → **Run as
  administrator**.
- Internet access from the server, or the installers copied to it in advance.

Record the Windows version:

```powershell
Get-ComputerInfo | Select-Object WindowsProductName, WindowsVersion, OsBuildNumber, OsArchitecture
```

`OsArchitecture` must be `64-bit`.

### 2.2 Names, DNS, and ports

Decide before you start:

| Item | Example |
|---|---|
| Public hostname | `reports.example.internal` |
| Install root | `C:\GenericReporting` |
| PHP directory | `C:\PHP` |
| IIS site name | `GenericReporting` |
| Application pool | `GenericSQLAPI` |
| SQL Server | `sql01.example.internal`, port `1433` |
| Database | `ApplicationDb` |

Create a DNS `A` record for the hostname that points at the web server's IP
address, then confirm it resolves from a client machine:

```powershell
Resolve-DnsName reports.example.internal
```

Ports:

| Port | Direction | Purpose |
|---|---|---|
| 443/TCP | Inbound to web server | HTTPS (required) |
| 80/TCP | Inbound to web server | Optional HTTP → HTTPS redirect only |
| 1433/TCP (or your instance port) | Web server → SQL Server | SQL Server connections |

### 2.3 SQL Server prerequisites

- A reachable SQL Server instance and an existing application database.
- Either **Windows authentication** for the IIS application-pool identity, or
  **SQL Server authentication** (Mixed Mode) with a dedicated login. Section 16
  creates the login and permissions.
- TCP/IP enabled on the instance (SQL Server Configuration Manager → **SQL
  Server Network Configuration** → **Protocols for <instance>** → **TCP/IP** =
  Enabled), and its firewall allowing the web server.

### 2.4 Build the frontend

The site root serves the built frontend from the separate Dashboard repository.
Build it on a workstation or build
server with Node.js, pointing it at the same-origin API path:

```powershell
cd <path-to>\Dashboard
npm ci
$env:VITE_API_URL = '/api'
npm run build
Remove-Item Env:\VITE_API_URL
```

Copy the resulting `dist` folder to the server in section 8. `VITE_*` values are
public; never put secrets in them.

## 3. Install IIS

### 3.1 Server Manager (GUI)

1. Open **Server Manager** → **Manage** → **Add Roles and Features**.
2. **Installation Type**: *Role-based or feature-based installation* → **Next**.
3. **Server Selection**: select this server → **Next**.
4. **Server Roles**: tick **Web Server (IIS)** → **Add Features** → **Next**.
5. **Features**: **Next** (no extra features are required).
6. **Role Services** under *Web Server (IIS)* → *Web Server*, make sure these are ticked:
   - **Common HTTP Features**: *Default Document*, *Static Content*, *HTTP Errors*.
     Leave *Directory Browsing* unticked.
   - **Health and Diagnostics**: *HTTP Logging*, *Request Monitor*.
   - **Security**: *Request Filtering*, *IP and Domain Restrictions*.
   - **Application Development**: **CGI** (this installs FastCGI).
   - **Management Tools**: *IIS Management Console*, *IIS Management Scripts and Tools*.
7. **Install**, and wait for completion.

Do **not** install ASP.NET, WebDAV, or Directory Browsing; the application does
not use them.

### 3.2 PowerShell equivalent

```powershell
Install-WindowsFeature -Name Web-Server, Web-Default-Doc, Web-Static-Content, Web-Http-Errors, `
    Web-Http-Logging, Web-Request-Monitor, Web-Filtering, Web-IP-Security, Web-CGI, `
    Web-Mgmt-Console, Web-Scripting-Tools
```

### 3.3 Verify IIS

```powershell
Get-WindowsFeature Web-Server, Web-CGI, Web-IP-Security, Web-Filtering | Format-Table Name, InstallState
Get-Service W3SVC, WAS | Format-Table Name, Status
```

All features must show `Installed` and both services `Running`. Browse to
`http://localhost/` on the server; the IIS welcome page confirms IIS works.

### 3.4 Unlock the configuration sections the templates use

The repository `web.config` templates declare their own FastCGI handler and
(for Admin) loopback IP restrictions. IIS locks both sections at server level
by default, which produces **HTTP 500.19** ("This configuration section cannot
be used at this path"). Delegate them once:

```powershell
& "$env:windir\System32\inetsrv\appcmd.exe" unlock config -section:system.webServer/handlers
& "$env:windir\System32\inetsrv\appcmd.exe" unlock config -section:system.webServer/security/ipSecurity
```

### 3.5 Stop the Default Web Site

The new site uses port 443 (and optionally 80). Stop the sample site so it does
not answer or conflict:

```powershell
& "$env:windir\System32\inetsrv\appcmd.exe" stop site /site.name:"Default Web Site"
& "$env:windir\System32\inetsrv\appcmd.exe" set site /site.name:"Default Web Site" /serverAutoStart:false
```

## 4. Install the IIS URL Rewrite module

All templates use IIS URL Rewrite rules (entry-point allowlists, health routing,
SPA fallback). URL Rewrite is a free Microsoft add-on, not a Windows feature.

1. Download **URL Rewrite 2.1 (x64)** from
   <https://www.iis.net/downloads/microsoft/url-rewrite>.
2. Run the installer and accept the defaults.
3. Close and reopen **IIS Manager**; a **URL Rewrite** icon now appears on the
   server and site feature pages.

Verify:

```powershell
& "$env:windir\System32\inetsrv\appcmd.exe" list modules | Select-String RewriteModule
```

## 5. Install PHP

### 5.1 Choose the build

| Requirement | Value |
|---|---|
| Version | PHP **8.2 or newer** (the project's minimum; CI runs 8.2) |
| Architecture | **x64** |
| Thread safety | **Non Thread Safe (NTS)**, which IIS FastCGI requires |
| Package | **Zip** |

The repository's `runtime\windows\php` folder is the *development* runtime used
by `start-windows.bat` (a Thread Safe build). Do not point IIS at it; install a
separate NTS PHP for production.

### 5.2 Install the Visual C++ runtime

PHP for Windows needs the Microsoft Visual C++ Redistributable that matches its
compiler (shown as `VS16` or `VS17` on the download page). The current
**Visual C++ Redistributable for Visual Studio 2015–2022 (x64)** covers both:

1. Download it from <https://aka.ms/vs/17/release/vc_redist.x64.exe>.
2. Run it and finish the installation. Reboot if prompted.

### 5.3 Download and extract PHP

1. Open <https://windows.php.net/download/>.
2. Under the PHP version you selected, find **VS17 x64 Non Thread Safe** (or
   **VS16 x64 Non Thread Safe** for PHP 8.2/8.3) and download the **Zip**.
3. Verify the SHA-256 shown on the page:

   ```powershell
   Get-FileHash "$env:USERPROFILE\Downloads\php-*-nts-Win32-*-x64.zip" -Algorithm SHA256
   ```

4. Extract to `C:\PHP`:

   ```powershell
   New-Item -ItemType Directory -Path C:\PHP -Force | Out-Null
   Expand-Archive -Path "$env:USERPROFILE\Downloads\php-*-nts-Win32-*-x64.zip" -DestinationPath C:\PHP
   ```

   Afterwards `C:\PHP\php.exe`, `C:\PHP\php-cgi.exe`, and `C:\PHP\ext\` must exist.
   The templates reference `C:\PHP\php-cgi.exe`; if you choose another folder,
   change it consistently everywhere in sections 6, 11, and 13.

### 5.4 Verify the binaries

```powershell
C:\PHP\php.exe -v
C:\PHP\php-cgi.exe -v
```

Both must print the same version and `(NTS)`. A missing-DLL dialog or no output
means the Visual C++ runtime from 5.2 is missing.

## 6. Configure php.ini

### 6.1 Create php.ini

```powershell
Copy-Item C:\PHP\php.ini-production C:\PHP\php.ini
New-Item -ItemType Directory -Path C:\GenericReporting\sessions, C:\GenericReporting\php-logs -Force | Out-Null
notepad C:\PHP\php.ini
```

### 6.2 Enable the required extensions

In `C:\PHP\php.ini`, set `extension_dir` and enable these lines (remove the
leading `;` where present):

```ini
extension_dir = "ext"

extension=odbc
extension=openssl
extension=mbstring
```

| Extension | Why |
|---|---|
| `odbc` | SQL Server connectivity through the Microsoft ODBC driver |
| `openssl` | AES-256-GCM database configuration encryption |
| `mbstring` | Optional: exact multibyte write-length validation (a byte-length fallback exists) |
| `json`, `session` | Built into PHP 8; nothing to enable |

OPcache: on PHP **8.2–8.4** also add `zend_extension=opcache`. On PHP **8.5+**
OPcache is built in, so do not add that line.

### 6.3 Merge the production settings

Append the repository's production settings from
`deployment\php-production-security.ini` to the end of `C:\PHP\php.ini` (later
values override earlier ones). If the project is not on the server yet, copy it
first (section 8.2). Then add the host-specific paths below them:

```powershell
Add-Content -Path C:\PHP\php.ini -Value "`r`n; --- Framework production settings ---"
Get-Content C:\GenericReporting\Backend\deployment\php-production-security.ini | Add-Content -Path C:\PHP\php.ini
```

```ini
; --- Framework host-specific paths ---
session.save_path = "C:\GenericReporting\sessions"
error_log = "C:\GenericReporting\php-logs\php-errors.log"
```

The merged file disables displayed errors and uploads, enables error logging,
secure session cookies, OPcache with `opcache.validate_timestamps = 0`, UTC, and
bounded execution/memory limits. Keep `session.save_path` outside every web
root; see [Session storage and lifetime](Production-Security-and-Deployment.md#session-storage-and-lifetime).
Leave `fastcgi.impersonate` unset (default `0`) so PHP runs as the application
pool identity that section 10 grants file access to.

### 6.4 Verify PHP

Check the FastCGI binary with the production INI, not only `php.exe`:

```powershell
C:\PHP\php-cgi.exe -c C:\PHP\php.ini -m
C:\PHP\php-cgi.exe -c C:\PHP\php.ini -i | Select-String 'display_errors|log_errors|session.save_path|opcache.enable|opcache.validate_timestamps|date.timezone'
```

The module list must include `odbc`, `openssl`, `session`, `json`, and
`Zend OPcache`. `display_errors` must be `Off`.

## 7. Install the Microsoft ODBC Driver for SQL Server

1. Download **Microsoft ODBC Driver 18 for SQL Server (x64)** from
   <https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server>.
2. Run `msodbcsql.msi` and accept the defaults.

Verify the 64-bit driver is registered (PHP is x64, so it uses the 64-bit list):

```powershell
Get-OdbcDriver -Platform 64-bit | Where-Object Name -like '*SQL Server*' | Format-Table Name, Platform
```

`ODBC Driver 18 for SQL Server` must be listed. The application's `driver`
setting can stay `auto`, which tries supported drivers newest-first; you may
instead select the exact driver name in the Admin Console to avoid probing
older driver names. Driver 18 encrypts connections by default, so the SQL
Server certificate must be trusted by this server (see section 16.4).

## 8. Deploy the project files

### 8.1 Directory structure

```text
C:\GenericReporting\
  Frontend\Generic-Reporting-Framework\dist\   built frontend (site root)
  Backend\                                     this repository
    api\  admin\  sqlparser\                    the three web entry boundaries
    app\  core\  database\  queries\  config\  deployment\  scripts\
    logs\  runtime\  storage\  backups\         writable application state
  state\config\                                runtime configuration (GENERIC_RUNTIME_CONFIG_DIR)
  sessions\                                    PHP session files
  php-logs\                                    PHP error log
```

Runtime configuration (users, password hashes, API-key hashes, roles, Admin
settings, availability state, and their lock files) lives in `state\config`,
outside the code tree. `Backend\config` then holds only the shipped PHP files,
including the query-source, routine, write-resource, and SQL Resource
allowlists, and stays read-only for the application pool. Every FastCGI
registration (section 11.2) and every command-line script in this guide must use
the same `GENERIC_RUNTIME_CONFIG_DIR`.

### 8.2 Copy the files

1. Copy the Backend repository (a release archive or `git clone` of the `dev`
   or release branch) to `C:\GenericReporting\Backend`.
2. Copy the frontend `dist` folder from section 2.4 to
   `C:\GenericReporting\Frontend\Generic-Reporting-Framework\dist`.

Do not copy a development machine's `config\*.json`, `database\config\databases.json` (or `database.json`),
`runtime\secrets\`, `logs\`, `backups\`, or `storage\`. They are ignored by Git
for that reason and are created fresh on the server.

### 8.3 Bootstrap runtime configuration

Create the secret-free runtime configuration files (`config\admin.json`,
`auth.json`, and so on) once:

```powershell
$env:GENERIC_RUNTIME_CONFIG_DIR = 'C:\GenericReporting\state\config'
C:\PHP\php.exe C:\GenericReporting\Backend\scripts\bootstrap-runtime-configuration.php
```

Keep `GENERIC_RUNTIME_CONFIG_DIR` set in the shell for the other command-line
scripts in this guide (`validate-production.php`, `application-backup.php`), so
they use the same directory as the IIS workers.

Expected output: `READY created=...`. Running it again never overwrites existing
values. Database availability starts **disconnected** until you connect it in
section 18.

### 8.4 Validate the templates

```powershell
C:\PHP\php.exe C:\GenericReporting\Backend\scripts\validate-production.php
```

The JSON report must show `"templates": "VALIDATED"`. It never changes
configuration or connects to SQL Server.

## 9. Create the application pool

### 9.1 IIS Manager (GUI)

1. Open **IIS Manager** (`inetmgr`).
2. In **Connections**, expand the server → right-click **Application Pools** →
   **Add Application Pool…**
3. **Name**: `GenericSQLAPI`; **.NET CLR version**: **No Managed Code**;
   **Managed pipeline mode**: **Integrated** → **OK**.
4. Select `GenericSQLAPI` → **Advanced Settings…** (right pane):
   - **Enable 32-Bit Applications**: `False` (PHP and the ODBC driver are x64).
   - **Identity**: `ApplicationPoolIdentity` (the default).
   - **Start Mode**: `OnDemand` (default) is fine.
   - **Recycling** → **Regular Time Interval (minutes)**: keep the default or set
     an off-hours schedule under **Specific Times** according to your policy.

### 9.2 PowerShell equivalent

```powershell
$appcmd = "$env:windir\System32\inetsrv\appcmd.exe"
& $appcmd add apppool /name:GenericSQLAPI /managedRuntimeVersion:"" /managedPipelineMode:Integrated
& $appcmd set apppool /apppool.name:GenericSQLAPI /enable32BitAppOnWin64:false /processModel.identityType:ApplicationPoolIdentity
```

`ApplicationPoolIdentity` is a virtual account named
`IIS APPPOOL\GenericSQLAPI`. Section 10 grants it file access, and section 16
can grant it SQL Server access.

## 10. Set NTFS permissions

The pool identity needs **read/execute** on the code and **modify** only where
the application writes state. Remove broad inherited access from the install root.

```powershell
$id = 'IIS APPPOOL\GenericSQLAPI'
$root = 'C:\GenericReporting'

# Replace inherited permissions on the install root.
icacls $root /inheritance:r /grant:r "Administrators:(OI)(CI)F" "SYSTEM:(OI)(CI)F" "${id}:(OI)(CI)RX"

# Writable application state.
foreach ($path in @(
    "$root\state\config",
    "$root\Backend\database\config",
    "$root\Backend\logs",
    "$root\Backend\runtime",
    "$root\Backend\storage",
    "$root\Backend\backups",
    "$root\sessions",
    "$root\php-logs")) {
    New-Item -ItemType Directory -Path $path -Force | Out-Null
    icacls $path /grant "${id}:(OI)(CI)M"
}

# The bundled development PHP runtimes are code: keep them read-only even
# though Backend\runtime is writable. IIS uses C:\PHP, not these folders.
foreach ($dev in @("$root\Backend\runtime\windows", "$root\Backend\runtime\linux")) {
    if (Test-Path $dev) {
        icacls $dev /inheritance:r /grant:r "Administrators:(OI)(CI)F" "SYSTEM:(OI)(CI)F" "${id}:(OI)(CI)RX"
    }
}

# PHP itself is read-only for the pool.
icacls C:\PHP /grant "${id}:(OI)(CI)RX"
```

| Path | Pool access | Why |
|---|---|---|
| `Backend\` (code), `Frontend\...\dist\`, `C:\PHP` | Read & execute | Code and static files |
| `Backend\config` | Read & execute | Shipped PHP configuration and allowlists; never writable by the pool |
| `state\config` | Modify | Admin settings, users, keys, runtime/availability state, lock files |
| `Backend\database\config` | Modify | Encrypted `databases.json` registry saved by the Admin Console |
| `Backend\logs` | Modify | Operational and audit logs |
| `Backend\runtime` | Modify | Health cache, backup lock, backup-signing key (`runtime\secrets`) |
| `Backend\runtime\windows`, `Backend\runtime\linux` | Read & execute | Development PHP runtimes; not used by IIS |
| `Backend\storage` | Modify | Rate-limit counters |
| `Backend\backups` | Modify | Application recovery points |
| `sessions`, `php-logs` | Modify | PHP sessions and PHP error log |

None of the writable folders is under an IIS application path, so they are not
web-addressable. Re-run the commands after adding new folders.

The pool must not be able to modify the PHP files it executes. Granting Modify on
`Backend\config` would let any file-write flaw in the application, or anyone
acting as the pool identity, change the query-source, routine, write-resource,
and SQL Resource allowlists or `constants.php`.

**Existing installations** that keep runtime JSON in `Backend\config`:

1. In System Health, **Disable** the API.
2. Create `C:\GenericReporting\state\config`.
3. Move `admin.json`, `auth.json`, `authorization.json`, `api-keys.json`,
   `installation.json`, `database-state.json`, and
   `application-runtime-state.json` from `Backend\config` into it. Delete the
   `*.lock` files left in `Backend\config`.
4. Set `GENERIC_RUNTIME_CONFIG_DIR` on all three FastCGI registrations
   (section 11.2).
5. Re-run the commands above, which also remove the pool's Modify grant from
   `Backend\config`.
6. Recycle the application pool, confirm `/health/ready`, and
   **Enable** the API.

## 11. Configure FastCGI and environment variables

### 11.1 Why per-boundary FastCGI applications

Each `web.config` template sends PHP requests to a **dedicated** FastCGI
registration distinguished by its arguments:

| IIS application | Handler `scriptProcessor` |
|---|---|
| `/api` | `C:\PHP\php-cgi.exe\|-d generic_sql_api.boundary=api` |
| `/admin` | `C:\PHP\php-cgi.exe\|-d generic_sql_api.boundary=admin` |
| `/sqlparser` | `C:\PHP\php-cgi.exe\|-d generic_sql_api.boundary=sqlparser` |

Each registration carries its own environment variables, so
`GENERIC_ADMIN_ENABLED=1` exists only in Admin workers and production mode is set
explicitly for every boundary instead of being inherited.

> **PowerShell or System environment variables do not reach IIS workers.**
> `$env:NAME = ...` affects only the current shell, and IIS worker processes are
> started by the Windows Process Activation Service, not by your session. Set
> the variables on the FastCGI registrations below (stored in
> `applicationHost.config`), then recycle the application pool.

### 11.2 Register the three FastCGI applications

```powershell
$appcmd = "$env:windir\System32\inetsrv\appcmd.exe"
foreach ($boundary in 'api', 'admin', 'sqlparser') {
    $app = "[fullPath='C:\PHP\php-cgi.exe',arguments='-d generic_sql_api.boundary=$boundary']"
    & $appcmd set config -section:system.webServer/fastCgi /+"$app" /commit:apphost
    & $appcmd set config -section:system.webServer/fastCgi /+"$app.environmentVariables.[name='PHPRC',value='C:\PHP']" /commit:apphost
    & $appcmd set config -section:system.webServer/fastCgi /+"$app.environmentVariables.[name='GENERIC_APP_ENV',value='production']" /commit:apphost
    & $appcmd set config -section:system.webServer/fastCgi /+"$app.environmentVariables.[name='GENERIC_RUNTIME_CONFIG_DIR',value='C:\GenericReporting\state\config']" /commit:apphost
}
& $appcmd set config -section:system.webServer/fastCgi /+"[fullPath='C:\PHP\php-cgi.exe',arguments='-d generic_sql_api.boundary=admin'].environmentVariables.[name='GENERIC_ADMIN_ENABLED',value='1']" /commit:apphost
```

| Variable | `/api` | `/admin` | `/sqlparser` | Purpose |
|---|:---:|:---:|:---:|---|
| `PHPRC=C:\PHP` | ✔ | ✔ | ✔ | Use `C:\PHP\php.ini` |
| `GENERIC_APP_ENV=production` | ✔ | ✔ | ✔ | Production mode (secure cookies, application availability controls, no local process management) |
| `GENERIC_RUNTIME_CONFIG_DIR=C:\GenericReporting\state\config` | ✔ | ✔ | ✔ | Runtime configuration outside the read-only code tree (section 8.1); the parser reads its availability state there |
| `GENERIC_ADMIN_ENABLED=1` | | ✔ | | Allows `admin.*` actions; never set it on `/api` or `/sqlparser` |
| `GENERIC_SQL_API_ENCRYPTION_KEY=<SECRET>` | ✔ | ✔ | | Decrypts the `databases.json` registry (11.3). The parser never touches the database |

Optional variables such as `GENERIC_API_ALLOWED_ORIGINS`,
`GENERIC_BACKUP_SIGNING_KEY`, and `DB_QUERY_TIMEOUT_SECONDS` are described in
[Production Security and Deployment](Production-Security-and-Deployment.md#environment-and-secrets).

### 11.3 Create and install the database encryption key

The key is 32 random bytes in Base64. Generate it straight into the clipboard
so it is never displayed or written to PowerShell history:

```powershell
$bytes = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
[Convert]::ToBase64String($bytes) | Set-Clipboard
$bytes = $null
```

Store it immediately in your organization's secret vault (paste from the
clipboard). Losing it makes the saved database configuration unrecoverable.

Add it to the `api` and `admin` registrations with IIS Manager, which keeps it
off the command line:

1. **IIS Manager** → select the **server** node → **Configuration Editor**.
2. **Section**: `system.webServer/fastCgi` → click the **(Collection)** row → **…**
3. Select the entry whose **arguments** is `-d generic_sql_api.boundary=api`.
4. In its properties, click **environmentVariables** → **…** → **Add**.
5. **name**: `GENERIC_SQL_API_ENCRYPTION_KEY`; **value**: paste from the clipboard.
   Close the dialogs.
6. Repeat 3–5 for `-d generic_sql_api.boundary=admin` with the **same** key.
7. In the Configuration Editor's right pane, click **Apply**.
8. Clear the clipboard: `Set-Clipboard -Value $null`

The value is stored in `C:\Windows\System32\inetsrv\config\applicationHost.config`,
which only Administrators and SYSTEM can read. Never put the key in `web.config`,
`databases.json`, a script file, the repository, or a command line. Rotation is
described in [Database Configuration](Database-Configuration.md#secret-rotation).

### 11.4 Verify

```powershell
& "$env:windir\System32\inetsrv\appcmd.exe" list config -section:system.webServer/fastCgi
```

Three `application` entries must appear with the variables above. The output
includes the key value, so do not save or share it.

## 12. Create the IIS site and applications

### 12.1 IIS Manager (GUI)

1. **IIS Manager** → right-click **Sites** → **Add Website…**
   - **Site name**: `GenericReporting`
   - **Application pool**: click **Select…** → `GenericSQLAPI`
   - **Physical path**: `C:\GenericReporting\Frontend\Generic-Reporting-Framework\dist`
   - **Binding**: temporarily *http*, *All Unassigned*, port `80`, host name
     `reports.example.internal` (section 14 replaces it with HTTPS).
   - **OK**.
2. Right-click the new site → **Add Application…** three times:

   | Alias | Physical path | Application pool |
   |---|---|---|
   | `api` | `C:\GenericReporting\Backend\api` | `GenericSQLAPI` |
   | `admin` | `C:\GenericReporting\Backend\admin` | `GenericSQLAPI` |
   | `sqlparser` | `C:\GenericReporting\Backend\sqlparser` | `GenericSQLAPI` |

3. Select the **site** → **Authentication** → **Anonymous Authentication** →
   **Edit…** → choose **Application pool identity** → **OK**. Static files are
   then read as `IIS APPPOOL\GenericSQLAPI`, which section 10 already authorized.

### 12.2 PowerShell equivalent

```powershell
$appcmd = "$env:windir\System32\inetsrv\appcmd.exe"
& $appcmd add site /name:GenericReporting /physicalPath:"C:\GenericReporting\Frontend\Generic-Reporting-Framework\dist" /bindings:"http/*:80:reports.example.internal"
& $appcmd set app /app.name:"GenericReporting/" /applicationPool:GenericSQLAPI
& $appcmd add app /site.name:GenericReporting /path:/api /physicalPath:"C:\GenericReporting\Backend\api" /applicationPool:GenericSQLAPI
& $appcmd add app /site.name:GenericReporting /path:/admin /physicalPath:"C:\GenericReporting\Backend\admin" /applicationPool:GenericSQLAPI
& $appcmd add app /site.name:GenericReporting /path:/sqlparser /physicalPath:"C:\GenericReporting\Backend\sqlparser" /applicationPool:GenericSQLAPI
& $appcmd set config GenericReporting -section:system.webServer/security/authentication/anonymousAuthentication /userName:"" /commit:apphost
```

## 13. Install web.config files

Copy each template to its folder and rename it to `web.config`:

```powershell
$deploy = 'C:\GenericReporting\Backend\deployment\iis'
Copy-Item "$deploy\frontend.web.config.example"  'C:\GenericReporting\Frontend\Generic-Reporting-Framework\dist\web.config'
Copy-Item "$deploy\api.web.config.example"       'C:\GenericReporting\Backend\api\web.config'
Copy-Item "$deploy\admin.web.config.example"     'C:\GenericReporting\Backend\admin\web.config'
Copy-Item "$deploy\sqlparser.web.config.example" 'C:\GenericReporting\Backend\sqlparser\web.config'
```

What each one does:

| File | Behavior |
|---|---|
| Frontend `web.config` | Routes `/health/live` and `/health/ready` to the API, leaves `/api` alone, serves existing files, falls back to `index.html` for SPA routes, adds frontend security headers |
| `api\web.config` | Allows only `index.php` and `health.php`, routes `/api` and `/api/health/*`, rejects every other path, 10 MB request limit, API security headers |
| `admin\web.config` | Loopback-only IP restriction (127.0.0.1 and ::1), allows `api.php` and three fixed assets, routes Admin pages to `index.php` |
| `sqlparser\web.config` | Allows only `index.php` and the two parser assets, hides `src`, rejects everything else |

The Admin Console and SQL Parser derive their asset URLs from the IIS
application path, so `/admin` and `/sqlparser` work with or without a trailing
slash; no path needs editing.

### 13.1 Restrict the SQL Parser to loopback

The SQL Parser is a developer tool with no login. On a site with a public
binding, restrict it to the server itself (or do not create the `/sqlparser`
application at all if nobody needs it):

1. **IIS Manager** → site → select the **sqlparser** application →
   **IP Address and Domain Restrictions**.
2. **Edit Feature Settings…** → **Access for unspecified clients**: **Deny** → **OK**.
3. **Add Allow Entry…** → **Specific IP address**: `127.0.0.1` → **OK**.
4. **Add Allow Entry…** → `::1` → **OK**.

### 13.2 Recycle and smoke-test over HTTP

```powershell
& "$env:windir\System32\inetsrv\appcmd.exe" recycle apppool /apppool.name:GenericSQLAPI
curl.exe -i -H "Host: reports.example.internal" http://127.0.0.1/api/health/live
```

The temporary binding answers only the configured host name, so the request
supplies it explicitly.

A `200` with `"status":"healthy"` proves IIS → FastCGI → PHP works. If you see a
500 error page, use section 23 before continuing.

## 14. Configure HTTPS

Production session cookies are `Secure`, so the application must be used over
HTTPS. TLS policy (protocols and ciphers) is controlled by Windows Schannel, not
by `web.config`.

### 14.1 Import the certificate

Obtain a certificate whose Subject Alternative Names include
`reports.example.internal`, as a `.pfx` with its private key:

```powershell
Import-PfxCertificate -FilePath C:\Temp\reports.pfx -CertStoreLocation Cert:\LocalMachine\My `
    -Password (Read-Host -AsSecureString -Prompt 'PFX password')
Get-ChildItem Cert:\LocalMachine\My | Where-Object Subject -like '*reports.example.internal*' |
    Format-Table Thumbprint, Subject, NotAfter
```

Delete the `.pfx` file from `C:\Temp` once imported.

### 14.2 Add the HTTPS binding

1. **IIS Manager** → select site `GenericReporting` → **Bindings…** (right pane).
2. **Add…** → **Type** `https`, **IP address** *All Unassigned*, **Port** `443`,
   **Host name** `reports.example.internal`, tick **Require Server Name
   Indication**, **SSL certificate**: select the imported certificate → **OK**.
3. Select the temporary `http` port 80 binding → **Remove**.

### 14.3 Optional HTTP → HTTPS redirect

To accept `http://` and redirect permanently to HTTPS, use a separate redirect
site so the application site never serves plain HTTP:

```powershell
New-Item -ItemType Directory -Path C:\GenericReporting\http-redirect -Force | Out-Null
Copy-Item C:\GenericReporting\Backend\deployment\iis\http-redirect.web.config.example C:\GenericReporting\http-redirect\web.config
notepad C:\GenericReporting\http-redirect\web.config
```

Replace `reports.example.internal` in the redirect URL with your hostname, save,
then:

```powershell
& "$env:windir\System32\inetsrv\appcmd.exe" add site /name:GenericReporting-HttpRedirect /physicalPath:C:\GenericReporting\http-redirect /bindings:"http/*:80:reports.example.internal"
```

HSTS (`Strict-Transport-Security: max-age=31536000`) is sent by the HTTPS
templates only.

### 14.4 Reach the Admin Console from the server

Admin answers only requests whose client address is `127.0.0.1` or `::1`
(both IIS and the application enforce this). A browser on the server that
resolves `reports.example.internal` through DNS connects from the server's LAN
address and gets 403/404. Map the hostname to loopback **on the server only**
so the certificate name still matches:

```powershell
Add-Content -Path "$env:windir\System32\drivers\etc\hosts" -Value "`r`n127.0.0.1`treports.example.internal"
```

Administer the site through a remote-desktop session on the server. Remote
Admin access is intentionally not provided.

## 15. Configure Windows Firewall

Installing IIS enables the built-in **World Wide Web Services (HTTPS Traffic-In)**
and **(HTTP Traffic-In)** rules. Confirm them, or create explicit rules:

```powershell
Get-NetFirewallRule -DisplayGroup 'World Wide Web Services (HTTP)' | Format-Table DisplayName, Enabled, Profile
New-NetFirewallRule -DisplayName 'Framework HTTPS' -Direction Inbound -Protocol TCP -LocalPort 443 -Action Allow
# Only if you deployed the redirect site:
New-NetFirewallRule -DisplayName 'Framework HTTP redirect' -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow
```

Outbound connections to SQL Server are allowed by default. Verify the path to
the database:

```powershell
Test-NetConnection sql01.example.internal -Port 1433
```

`TcpTestSucceeded : True` is required. On the SQL Server host, allow inbound
1433/TCP (or the instance's port) from the web server only.

## 16. Prepare SQL Server

Run these in SQL Server Management Studio as a sysadmin. Grant only what the
deployment uses; see [Database Configuration](Database-Configuration.md) for how
the application uses each setting.

### 16.1 Option A: Windows authentication (recommended on a domain)

The application connects as the identity of `php-cgi.exe`.

- **SQL Server on the same machine:** the identity is `IIS APPPOOL\GenericSQLAPI`.

  ```sql
  CREATE LOGIN [IIS APPPOOL\GenericSQLAPI] FROM WINDOWS;
  ```

- **SQL Server on another machine:** a virtual pool identity reaches the network
  as the web server's **computer account**:

  ```sql
  CREATE LOGIN [EXAMPLE\WEB01$] FROM WINDOWS;   -- DOMAIN\COMPUTERNAME$
  ```

  Alternatively set the pool identity to a dedicated domain service account
  (**Advanced Settings** → **Identity** → **Custom account**), create the login
  for that account, and grant it the NTFS rights from section 10 instead.

### 16.2 Option B: SQL Server authentication

Enable **SQL Server and Windows Authentication mode** (server **Properties** →
**Security**), restart the SQL Server service, then:

```sql
CREATE LOGIN [generic_sql_api] WITH PASSWORD = N'<SECRET>', CHECK_POLICY = ON;
```

Type the password interactively; do not save it in a script file.

### 16.3 Database user and permissions

```sql
USE [ApplicationDb];
CREATE USER [generic_sql_api] FOR LOGIN [IIS APPPOOL\GenericSQLAPI];   -- or the login from 16.1/16.2
ALTER ROLE db_datareader ADD MEMBER [generic_sql_api];
-- Only if clients with data.write must change data:
ALTER ROLE db_datawriter ADD MEMBER [generic_sql_api];
-- Only if clients must call stored procedures or functions:
GRANT EXECUTE TO [generic_sql_api];
```

These grants are the effective data boundary. The API authorizes by role
permission only: a principal with `data.read` can read every table and view this
user can read, and `data.write` can change every table it can write. Prefer
narrower grants (specific schemas, tables, views, or procedures) and expose
sensitive columns only through least-privilege views.

### 16.4 Encryption and certificates

ODBC Driver 18 encrypts by default. In the Admin Console keep **Encrypt
connection** on. Leave **Trust server certificate** off when SQL Server presents
a certificate this web server trusts. Turning it on accepts any certificate and
should be limited to isolated test environments.

## 17. First Admin Console setup

1. On the server, open `https://reports.example.internal/admin/`.
2. The first visit shows **Create Super Admin**. Enter name, username, mobile
   number, and a strong password → **Create Super Admin**, then sign in.
3. **Configuration** shows **Database**, **Security**, **Runtime & Performance**,
   and **Advanced**. The development-only **Server** tab does not exist in
   production because IIS owns listeners and workers.
4. **Configuration → Security → CORS**: browsers send an `Origin` header on API
   requests, and the API rejects origins that are not listed. Add the exact
   production origin, for example `https://reports.example.internal` (scheme and
   host, no path or trailing slash), keep **Allow browser credentials** ticked,
   and **Save CORS**. Remove development origins you do not need.
5. **Configuration → Security → Authentication**: choose the API authentication
   mode (`session` is the default; see
   [Authentication and authorization](Authentication-and-Authorization.md)).

## 18. Configure the database connection

1. **Databases → Servers → + Add Server** (id, for example `sql01`, and a name):
   - **ODBC Driver**: `auto` or `ODBC Driver 18 for SQL Server`.
   - **Server**: `sql01.example.internal` (or `host\instance`); **Port**: `1433`
     or blank for the default/instance resolution.
   - **Authentication**: *Windows integrated* (16.1) or *SQL login* (16.2) with
     username and password.
   - **Encrypt connection** / **Trust server certificate**: per 16.4.
2. **Save** encrypts the connection with the key from 11.3 and writes the
   registry `Backend\database\config\databases.json`. Passwords are never
   shown again; leave the password blank on later edits to keep it. **Test
   Connection** on the profile then checks it with one temporary connection
   and changes nothing (to test before use, save it with **Enabled** cleared,
   test, then **Enable**).
3. **Databases → + Add Database**: an id (what clients send), a name, the
   server profile, and the SQL Server database (catalog), for example
   `ApplicationDb`. The first database becomes the default.
4. **Connect** on the database (or **System Health → Service Actions →
   Database → Connect** for the default) verifies it with one test connection
   and only then makes it available to API requests. The Database card should
   show **connected**.

Failures are reported safely, for example `DATABASE_CONNECTION_FAILED`, or
`DATABASE_CONFIGURATION_UNAVAILABLE` with the reason `encryption_key_missing`,
`configuration_invalid`, or `configuration_missing`.

## 19. Verify the deployment

Run from the server (or a client that resolves the hostname) without disabling
certificate validation.

### 19.1 Health endpoints

```powershell
curl.exe -i https://reports.example.internal/health/live
curl.exe -i https://reports.example.internal/health/ready
```

- `/health/live` → `200` whenever PHP can answer.
- `/health/ready` → `200` only when configuration and runtime directories are
  valid, the API is enabled, database access is connected, the database
  configuration decrypts, and no recent connectivity failure is cached. It never
  opens a SQL connection. Otherwise `503` with a safe category per check. See
  [Monitoring and Health](Monitoring-and-Health.md).

### 19.2 API

```powershell
Invoke-RestMethod -Method Post -Uri https://reports.example.internal/api -ContentType 'application/json' -Body '{"action":"setup.status"}'
curl.exe -i https://reports.example.internal/api/not-a-route
```

The first returns a JSON envelope with `"success": true`. The second is rejected
by the API `web.config` allowlist with a plain `404` and no IIS detail page.

### 19.3 SQL Parser

On the server, open `https://reports.example.internal/sqlparser/`, enter
`SELECT ItemCode FROM Items`, and click **Parse SQL**. Then confirm the assets:

```powershell
curl.exe -sI https://reports.example.internal/sqlparser/assets/css/app.css | Select-String 'HTTP/|Content-Type'
curl.exe -sI https://reports.example.internal/sqlparser/assets/js/app.js  | Select-String 'HTTP/|Content-Type'
```

Expect `200` with `text/css` and `application/javascript`.

### 19.4 System Health

**Admin → System Health** should show:

| Card | Expected production state |
|---|---|
| Admin Console | running, Infrastructure: IIS Managed |
| API Server / SQL Parser | enabled, Infrastructure: IIS Managed, no PID/port |
| Database | connected (or Disconnected / Unhealthy with a reason) |
| PHP Runtime | PHP version, ODBC available |
| Configuration, Logging, Encryption | healthy / configured |
| Backup | not configured ("No backup has been created yet") until the first backup |

Try **Disable** on the API: `/api` requests then return `503
SERVICE_UNAVAILABLE` while Admin and `/health/live` keep working. **Enable** it
again.

### 19.5 Sensitive paths

```powershell
foreach ($p in '/api/../config/admin.json', '/api/config/admin.json', '/sqlparser/src/SqlParser.php', '/web.config') {
    curl.exe -s -o NUL -w "$p %{http_code}`n" "https://reports.example.internal$p"
}
```

None may return `200` with file contents.

## 20. Logging

| Log | Location |
|---|---|
| Application operational logs | `C:\GenericReporting\Backend\logs\{api,admin,database,sqlparser}\YYYY-MM-DD.txt` |
| Security audit log | `C:\GenericReporting\Backend\logs\audit\YYYY-MM-DD.jsonl` |
| PHP startup/engine errors | `C:\GenericReporting\php-logs\php-errors.log` |
| IIS access logs | `C:\inetpub\logs\LogFiles\W3SVC<site id>\` |
| FastCGI/IIS failures | **Event Viewer** → *Windows Logs* → *Application* |

Every API error response carries `meta.requestId` (also the `X-Request-ID`
header); search the dated application logs for it. Logs never contain
passwords, keys, or query parameter values. Rotate and archive them with your
operations tooling; see [Logging](Logging.md).

## 21. Backups and restore

> **Application backup is not a SQL Server backup.** Recovery points contain
> only application configuration (users, roles, API-key hashes, Admin settings,
> and the encrypted `databases.json` registry). Back up `ApplicationDb` with SQL
> Server-native full, differential, and log backups.

- **Create:** Admin → **Backup & Recovery** → **Create Backup**. The signed ZIP
  is stored in `Backend\backups` and downloaded by the browser. The first
  backup creates the signing key `Backend\runtime\secrets\backup-signing.key`
  unless `GENERIC_BACKUP_SIGNING_KEY` is set on the Admin registration.
- **Protect the keys:** keep a copy of the backup-signing key and the database
  encryption key in your secret vault, separately from the ZIP files. A restore
  needs both.
- **Restore:** **Restore Backup** → choose the ZIP → review the verified preview
  → **Restore Configuration**. The previous configuration is restored
  automatically if activation or its health check fails. Runtime availability,
  sessions, logs, and SQL Server data are not touched.
- **Scheduled backups:** the Admin schedule only stores settings. Create a
  Windows **Task Scheduler** task that runs
  `C:\PHP\php.exe C:\GenericReporting\Backend\scripts\application-backup.php scheduled-create`
  under an account with the section 10 permissions. That task must receive the
  same `GENERIC_RUNTIME_CONFIG_DIR` and `GENERIC_SQL_API_ENCRYPTION_KEY` as the
  Admin registration, the key through your organization's secret-injection
  mechanism and never as a command-line argument.
- **Health:** the System Health **Backup** card reports the latest verified
  recovery point, the schedule, and failures without affecting overall health.

Full details: [Backup and Recovery](Backup-and-Recovery.md).

## 22. Updates, OPcache, and rollback

`opcache.validate_timestamps = 0`, so PHP keeps serving cached code until the
pool recycles. Every update must end with a recycle.

### 22.1 Update procedure

1. Create an application backup (section 21) and confirm SQL Server backups are current.
2. Optionally **Disable** the API in System Health so clients get a clean `503`.
3. Copy the new release over `Backend` (and the new frontend `dist`). Do not
   overwrite `state\config`, `database\config\databases.json`, `runtime\secrets`,
   `logs`, `storage`, or `backups`.
4. Re-copy any changed `deployment\iis\*.web.config.example` files to their
   `web.config` locations (section 13), keeping local edits such as the redirect
   hostname or parser IP restrictions.
5. With `GENERIC_RUNTIME_CONFIG_DIR` set as in section 8.3, run
   `C:\PHP\php.exe C:\GenericReporting\Backend\scripts\bootstrap-runtime-configuration.php`
   (adds any new configuration files without overwriting existing values) and
   re-run the NTFS commands from section 10, which also keep `Backend\config`
   and the development runtimes read-only after the copy.
6. Recycle: `& "$env:windir\System32\inetsrv\appcmd.exe" recycle apppool /apppool.name:GenericSQLAPI`
7. Hard-refresh browsers (Ctrl+F5) so new Admin/parser JavaScript loads.
8. **Upgrading from V2 to V3 only (once):** an existing
   `database\config\database.json` keeps serving as the default database. In
   the Admin Console open **Databases → Servers**, **Edit** the `default` profile,
   leave the password blank, and **Save**: the worker migrates it, with its own encryption key,
   into `database\config\databases.json` and removes the V2 file. Then check
   the **Databases** page. Do not put the encryption key on a command line to
   run the migration script instead. See [Upgrading to V3](Upgrading-to-V3.md).
9. Re-run section 19 and **Enable** the API.

### 22.2 Rollback

1. Keep the previous release folder (for example `C:\GenericReporting\releases\<version>`).
2. Copy the previous code back over `Backend`/`dist`, keeping the state folders listed above.
3. If configuration must also go back, restore the matching application backup
   (section 21). Restore SQL Server data only through SQL Server-native restores.
4. Recycle the application pool and re-run section 19.

Rolling back from V3 to V2 needs the application backup created with V2 before
the upgrade: V2 cannot read `databases.json`, and the V3 migration removed the
V2 `database.json`. Restore that backup with the V2 code (it brings back
`database.json`); servers and databases added after the upgrade are not part of
it. See [Upgrading to V3](Upgrading-to-V3.md#rolling-back).

## 23. Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| **HTTP 500.19** "cannot be used at this path" | `handlers` or `ipSecurity` still locked: run section 3.4 |
| **HTTP 500** "FastCGI application ... not found" / 500.0 | The handler's `scriptProcessor` has no matching FastCGI registration: re-run 11.2 exactly (path and arguments must match) |
| **404** for `/api`, health routes, or Admin pages | URL Rewrite not installed (section 4) or a `web.config` missing (section 13) |
| Admin returns **403** or **404** | Not browsing from loopback (14.4), or `GENERIC_ADMIN_ENABLED=1` missing on the Admin registration |
| Admin shows a **Server** tab or PID/port, or Start/Stop instead of Enable/Disable | That boundary is not in production mode: `GENERIC_APP_ENV=production` missing on its FastCGI registration; fix 11.2, recycle, Ctrl+F5 |
| Changes not visible after an update | OPcache: recycle the pool (22.1) and hard-refresh the browser |
| Browser requests to `/api` fail with **403 `CORS_ORIGIN_DENIED`** | The production origin is not in Configuration → Security → CORS (17.4) |
| Login succeeds but the session is immediately lost | Using `http://` instead of HTTPS (production cookies are Secure), or `session.save_path` not writable by the pool (sections 6.3 and 10) |
| `DATABASE_UNAVAILABLE` (503) from the API | Database access is disconnected: System Health → Database → Connect |
| `DATABASE_CONFIGURATION_UNAVAILABLE` with `encryption_key_missing` | The key is not on that boundary's FastCGI registration (11.3), or the pool was not recycled |
| `configuration_invalid` after changing the key | The key does not match the saved ciphertext: restore the original key; never generate a replacement for existing ciphertext |
| `DATABASE_CONNECTION_FAILED` | Check 15 (`Test-NetConnection`), the login/user (16), the driver (7), and certificate trust (16.4); the database log has the request ID |
| Windows-authentication login fails remotely | Remote SQL Server sees the computer account `DOMAIN\WEB01$`, not `IIS APPPOOL\...` (16.1) |
| SQL Parser page unstyled, `/assets/...` returns `text/html` | An outdated `sqlparser\index.php`; deploy the current release and recycle |
| PHP errors not visible | Check `C:\GenericReporting\php-logs\php-errors.log` and Event Viewer; detailed errors are intentionally never shown to clients |

Run the read-only validator for a structured report:
`C:\PHP\php.exe C:\GenericReporting\Backend\scripts\validate-production.php`.

## 24. Final production checklist

- [ ] Windows Server x64 patched; IIS with Static Content, Request Filtering, IP and Domain Restrictions, CGI, and Management Tools installed
- [ ] `handlers` and `ipSecurity` sections unlocked; Default Web Site stopped
- [ ] URL Rewrite 2.1 installed
- [ ] PHP 8.2+ **NTS x64** in `C:\PHP`, Visual C++ runtime installed
- [ ] `php.ini` merged with `deployment\php-production-security.ini`; `odbc`, `openssl` (and OPcache) loaded; `display_errors = Off`; sessions and PHP error log outside web roots
- [ ] ODBC Driver 18 for SQL Server (x64) registered
- [ ] Code in `C:\GenericReporting`; no development `config`, `databases.json`/`database.json`, `runtime\secrets`, logs, or backups copied
- [ ] Runtime configuration bootstrapped; `validate-production.php` reports templates `VALIDATED`
- [ ] Application pool `GenericSQLAPI`: No Managed Code, Integrated, 64-bit, ApplicationPoolIdentity
- [ ] NTFS: install root, `Backend\config`, and the development runtimes read-only for the pool; only the listed state folders writable
- [ ] Three FastCGI registrations with `PHPRC`, `GENERIC_APP_ENV=production`, and `GENERIC_RUNTIME_CONFIG_DIR`; `GENERIC_ADMIN_ENABLED=1` on Admin only; encryption key on API and Admin only, stored in the secret vault
- [ ] Site `GenericReporting` with `/api`, `/admin`, `/sqlparser` applications; anonymous identity = application pool identity
- [ ] All four `web.config` files installed; `/sqlparser` restricted to loopback or not deployed
- [ ] HTTPS binding with a trusted certificate; optional redirect site; HSTS verified
- [ ] Firewall allows 443 (and 80 only for the redirect); SQL Server reachable on its port
- [ ] SQL login/user created with least-privilege permissions
- [ ] Super Admin created; production origin added to CORS; database saved (encrypted) and connected
- [ ] `/health/live` 200, `/health/ready` 200, API, SQL Parser, and System Health verified (section 19)
- [ ] First application backup created; signing and encryption keys stored separately; SQL Server backups scheduled
- [ ] Update, recycle, and rollback procedure rehearsed
