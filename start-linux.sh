#!/usr/bin/env bash

set -euo pipefail

BACKEND_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYSTEM_PHP_BIN="$(command -v php || true)"
BUNDLED_PHP_BIN="$BACKEND_ROOT/runtime/linux/php/php"
PHP_BIN="$BUNDLED_PHP_BIN"
if [ ! -x "$PHP_BIN" ]; then PHP_BIN="$SYSTEM_PHP_BIN"; fi
PHP_INI_PATH="$BACKEND_ROOT/runtime/linux/php/php.ini"
OPCACHE_PATH="$BACKEND_ROOT/runtime/linux/php/opcache"
LOG_PATH="$BACKEND_ROOT/logs"
ADMIN_PATH="$BACKEND_ROOT/admin"
export GENERIC_RUNTIME_CONFIG_DIR="$BACKEND_ROOT/config"
export GENERIC_APP_ENV=development

echo "========================================"
echo "       Framework"
echo "========================================"
echo

if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
    echo "[FAILED] PHP CLI is not installed or executable."
    exit 1
fi
if [ ! -f "$PHP_INI_PATH" ]; then
    echo "[FAILED] PHP configuration not found: $PHP_INI_PATH"
    exit 1
fi
if [ ! -f "$ADMIN_PATH/router.php" ]; then
    echo "[FAILED] Admin router not found: $ADMIN_PATH/router.php"
    exit 1
fi
if ! "$PHP_BIN" -n -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'; then
    echo "[FAILED] PHP 8.2 or newer is required."
    exit 1
fi

mkdir -p "$OPCACHE_PATH" "$LOG_PATH"

for extension in odbc openssl json session; do
    if ! "$PHP_BIN" -c "$PHP_INI_PATH" -r "exit(extension_loaded('$extension') ? 0 : 1);"; then
        echo "[FAILED] Required PHP extension is unavailable: $extension"
        exit 1
    fi
done

"$PHP_BIN" -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/bootstrap-runtime-configuration.php"

GENERIC_SQL_API_ENCRYPTION_KEY="$($PHP_BIN -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/prepare-local-encryption-key.php")"
if [ -z "$GENERIC_SQL_API_ENCRYPTION_KEY" ]; then
    echo "[FAILED] Unable to prepare the database encryption key."
    exit 1
fi
export GENERIC_SQL_API_ENCRYPTION_KEY

ADMIN_PORT="$($PHP_BIN -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/find-available-port.php" admin)"
export GENERIC_ADMIN_ENABLED=1
export GENERIC_ADMIN_STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
ADMIN_URL="http://127.0.0.1:$ADMIN_PORT/"

API_STATE="unavailable"
PARSER_STATE="unavailable"
DATABASE_STATE="disconnected"
STARTUP_WARNINGS=0
if "$PHP_BIN" -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/api-runtime-control.php" start >/dev/null; then
    API_STATE="running"
    echo "[OK] API runtime started"
else
    STARTUP_WARNINGS=1
    echo "[WARNING] API runtime could not be started. Use System Health to retry."
fi
if "$PHP_BIN" -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/sqlparser-runtime-control.php" start >/dev/null; then
    PARSER_STATE="running"
    echo "[OK] SQL Parser runtime started"
else
    STARTUP_WARNINGS=1
    echo "[WARNING] SQL Parser runtime could not be started. Use System Health to retry."
fi
if "$PHP_BIN" -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/database-runtime-control.php" connect >/dev/null; then
    DATABASE_STATE="connected"
    echo "[OK] Database application runtime connected"
else
    STARTUP_WARNINGS=1
    echo "[WARNING] Database application runtime remains disconnected. Configure it and retry from System Health."
fi
if ! "$PHP_BIN" -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/verify-development-runtime.php" >/dev/null; then
    STARTUP_WARNINGS=1
    echo "[WARNING] Development runtime verification found an unavailable component."
fi

echo "[OK] PHP runtime and required extensions"
echo "[OK] Runtime configuration"
echo "[OK] Local database encryption key"
echo "[OK] Admin Console port $ADMIN_PORT"
echo
echo "API: $API_STATE"
echo "Parser: $PARSER_STATE"
echo "Database: $DATABASE_STATE"
echo "Admin: $ADMIN_URL"
if [ "$STARTUP_WARNINGS" -ne 0 ]; then
    echo "Startup completed with warnings; the Admin Console remains available for recovery."
fi
echo
echo "The Admin Console is bound to this computer only. Press Ctrl+C to stop it."
echo "The PHP built-in server is for local setup and development, not production."
echo

(
    sleep 1
    if command -v xdg-open >/dev/null 2>&1; then
        xdg-open "$ADMIN_URL" >/dev/null 2>&1 || true
    elif command -v cmd.exe >/dev/null 2>&1; then
        cmd.exe /C start "" "$ADMIN_URL" >/dev/null 2>&1 || true
    fi
) &

exec "$PHP_BIN" -c "$PHP_INI_PATH" \
    -d "opcache.file_cache=$OPCACHE_PATH" \
    -S "127.0.0.1:$ADMIN_PORT" \
    -t "$ADMIN_PATH" \
    "$ADMIN_PATH/router.php"
