@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"

set "PHP=%ROOT%\runtime\windows\php\php.exe"
set "PHP_INI=%ROOT%\runtime\windows\php\php.ini"
set "OPCACHE=%ROOT%\runtime\windows\php\opcache"
set "LOGS=%ROOT%\logs"
set "ADMIN=%ROOT%\admin"

set "GENERIC_RUNTIME_CONFIG_DIR=%ROOT%\config"
set "GENERIC_APP_ENV=development"

echo ========================================
echo          Framework
echo ========================================
echo.

REM ==================================================
REM Basic path validation
REM ==================================================

if not exist "%PHP%" (
    echo [FAILED] Bundled PHP runtime not found:
    echo         %PHP%
    pause
    exit /b 1
)

if not exist "%PHP_INI%" (
    echo [FAILED] PHP configuration not found:
    echo         %PHP_INI%
    pause
    exit /b 1
)

if not exist "%ADMIN%\router.php" (
    echo [FAILED] Admin router not found:
    echo         %ADMIN%\router.php
    pause
    exit /b 1
)

"%PHP%" -n -r "exit(PHP_VERSION_ID >= 80200 ? 0 : 1);"
if errorlevel 1 (
    echo [FAILED] PHP 8.2 or newer is required.
    pause
    exit /b 1
)

if not exist "%OPCACHE%" (
    mkdir "%OPCACHE%" >nul 2>&1
    if errorlevel 1 (
        echo [FAILED] Unable to create PHP OPcache directory:
        echo         %OPCACHE%
        pause
        exit /b 1
    )
)

if not exist "%LOGS%" (
    mkdir "%LOGS%" >nul 2>&1
    if errorlevel 1 (
        echo [FAILED] Unable to create log directory:
        echo         %LOGS%
        pause
        exit /b 1
    )
)

REM ==================================================
REM Verify required PHP extensions
REM ==================================================

for %%E in (odbc openssl json session) do (
    "%PHP%" -c "%PHP_INI%" -r "exit(extension_loaded('%%E') ? 0 : 1);"

    if errorlevel 1 (
        echo [FAILED] Required PHP extension is unavailable: %%E
        pause
        exit /b 1
    )
)

echo [OK] PHP runtime and required extensions

REM ==================================================
REM Initialize runtime configuration
REM ==================================================

"%PHP%" -c "%PHP_INI%" "%ROOT%\scripts\bootstrap-runtime-configuration.php"

if errorlevel 1 (
    echo [FAILED] Unable to initialize runtime configuration.
    pause
    exit /b 1
)

echo [OK] Runtime configuration

REM ==================================================
REM Prepare local database encryption key
REM ==================================================
REM
REM Do not use FOR /F command substitution directly against
REM the PHP helper. Windows CMD can misinterpret the nested
REM command/path.
REM
REM The helper itself is responsible for generating/ensuring
REM the local key. Its stdout is captured temporarily and then
REM immediately deleted.
REM

set "KEY_FILE=%TEMP%\generic-sql-api-key-%RANDOM%-%RANDOM%.tmp"
set "PREPARED_ENCRYPTION_KEY="

type nul > "%KEY_FILE%"
icacls "%KEY_FILE%" /inheritance:r /grant:r "%USERNAME%:(R,W,D)" >nul 2>&1
if errorlevel 1 (
    del /q "%KEY_FILE%" >nul 2>&1
    echo [FAILED] Unable to protect the temporary database encryption key output.
    pause
    exit /b 1
)

"%PHP%" -c "%PHP_INI%" "%ROOT%\scripts\prepare-local-encryption-key.php" > "%KEY_FILE%"

if errorlevel 1 (
    del /q "%KEY_FILE%" >nul 2>&1
    echo [FAILED] Unable to prepare the database encryption key.
    pause
    exit /b 1
)

if not exist "%KEY_FILE%" (
    echo [FAILED] Encryption key output was not created.
    pause
    exit /b 1
)

for /f "usebackq delims=" %%K in ("%KEY_FILE%") do (
    if not defined PREPARED_ENCRYPTION_KEY (
        set "PREPARED_ENCRYPTION_KEY=%%K"
    )
)

del /q "%KEY_FILE%" >nul 2>&1

if not defined PREPARED_ENCRYPTION_KEY (
    echo [FAILED] Unable to prepare the database encryption key.
    pause
    exit /b 1
)

set "GENERIC_SQL_API_ENCRYPTION_KEY=%PREPARED_ENCRYPTION_KEY%"
set "PREPARED_ENCRYPTION_KEY="

echo [OK] Local database encryption key

REM ==================================================
REM Find available Admin port
REM ==================================================

set "ADMIN_PORT="
set "PORT_FILE=%TEMP%\generic-sql-api-port-%RANDOM%-%RANDOM%.tmp"

"%PHP%" -c "%PHP_INI%" "%ROOT%\scripts\find-available-port.php" admin > "%PORT_FILE%"
if errorlevel 1 (
    del /q "%PORT_FILE%" >nul 2>&1
    echo [FAILED] The configured Admin port is unavailable.
    pause
    exit /b 1
)

for /f "usebackq delims=" %%P in ("%PORT_FILE%") do (
    if not defined ADMIN_PORT (
        set "ADMIN_PORT=%%P"
    )
)

del /q "%PORT_FILE%" >nul 2>&1

if not defined ADMIN_PORT (
    echo [FAILED] The configured Admin port is unavailable.
    pause
    exit /b 1
)

set "PORT_FILE="

echo [OK] Admin Console port %ADMIN_PORT%

REM ==================================================
REM Admin runtime environment
REM ==================================================

set "GENERIC_ADMIN_ENABLED=1"

for /f "usebackq delims=" %%T in (`
    powershell -NoProfile -Command "[DateTime]::UtcNow.ToString('o')"
`) do (
    if not defined GENERIC_ADMIN_STARTED_AT (
        set "GENERIC_ADMIN_STARTED_AT=%%T"
    )
)

if not defined GENERIC_ADMIN_STARTED_AT (
    echo [FAILED] Unable to determine Admin startup time.
    pause
    exit /b 1
)

set "ADMIN_URL=http://127.0.0.1:%ADMIN_PORT%/"

REM ==================================================
REM Establish complete development runtime
REM ==================================================

set "API_STATE=unavailable"
set "PARSER_STATE=unavailable"
set "DATABASE_STATE=disconnected"
set "STARTUP_WARNINGS=0"

"%PHP%" -c "%PHP_INI%" "%ROOT%\scripts\api-runtime-control.php" start >nul
if errorlevel 1 (
    set "STARTUP_WARNINGS=1"
    echo [WARNING] API runtime could not be started. Use System Health to retry.
) else (
    set "API_STATE=running"
    echo [OK] API runtime started
)

"%PHP%" -c "%PHP_INI%" "%ROOT%\scripts\sqlparser-runtime-control.php" start >nul
if errorlevel 1 (
    set "STARTUP_WARNINGS=1"
    echo [WARNING] SQL Parser runtime could not be started. Use System Health to retry.
) else (
    set "PARSER_STATE=running"
    echo [OK] SQL Parser runtime started
)

"%PHP%" -c "%PHP_INI%" "%ROOT%\scripts\database-runtime-control.php" connect >nul
if errorlevel 1 (
    set "STARTUP_WARNINGS=1"
    echo [WARNING] Database application runtime remains disconnected. Configure it and retry from System Health.
) else (
    set "DATABASE_STATE=connected"
    echo [OK] Database application runtime connected
)

"%PHP%" -c "%PHP_INI%" "%ROOT%\scripts\verify-development-runtime.php" >nul
if errorlevel 1 (
    set "STARTUP_WARNINGS=1"
    echo [WARNING] Development runtime verification found an unavailable component.
)

echo.
echo API:      %API_STATE%
echo Parser:   %PARSER_STATE%
echo Database: %DATABASE_STATE%
echo Admin:  %ADMIN_URL%
if "%STARTUP_WARNINGS%"=="1" echo Startup completed with warnings; the Admin Console remains available for recovery.
echo.
echo The Admin Console is bound to this computer only.
echo Press Ctrl+C to stop it.
echo The PHP built-in server is for local setup and development, not production.
echo.

REM ==================================================
REM Open Admin Console
REM ==================================================

start "" "%ADMIN_URL%"

REM ==================================================
REM Start Admin Console control plane
REM ==================================================

"%PHP%" ^
    -c "%PHP_INI%" ^
    -d "opcache.file_cache=%OPCACHE%" ^
    -S "127.0.0.1:%ADMIN_PORT%" ^
    -t "%ADMIN%" ^
    "%ADMIN%\router.php"

echo.
echo Admin Console stopped.
echo API and SQL Parser lifecycles remain independently managed.
pause

endlocal
