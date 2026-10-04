#!/usr/bin/env bash
set -euo pipefail

ROLE="${1:-app}"
APP_DIR="/var/www/html"

cd "$APP_DIR"

# Ensure runtime paths exist and are writable by www-data. The storage
# directory is a bind-mount on /mnt/data in production so this also
# applies the correct ownership to the host-side tree.
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

wait_for_host() {
    local host="$1" port="$2" label="$3" tries=60
    echo "[entrypoint] Waiting for ${label} at ${host}:${port}..."
    until php -r "exit(@fsockopen('${host}', ${port}) ? 0 : 1);" >/dev/null 2>&1; do
        tries=$((tries - 1))
        if [ "$tries" -le 0 ]; then
            echo "[entrypoint] ERROR: ${label} did not become reachable at ${host}:${port}" >&2
            exit 1
        fi
        sleep 1
    done
    echo "[entrypoint] ${label} is up."
}

wait_for_host "${DB_HOST:-licentra-pgsql}" "${DB_PORT:-5432}" "PostgreSQL"
wait_for_host "${REDIS_HOST:-licentra-redis}" "${REDIS_PORT:-6379}" "Redis"

if [ "$ROLE" = "app" ]; then
    if [ "${APP_RUN_MIGRATIONS_ON_BOOT:-true}" = "true" ]; then
        echo "[entrypoint] Running migrations..."
        php artisan migrate --force --no-interaction || true
    fi

    echo "[entrypoint] Warming caches..."
    php artisan config:cache || true
    php artisan route:cache  || true
    # storage/ is persistent across container recreates, so stale
    # compiled blades from a previous image can survive. Always clear
    # before re-warming, otherwise a buggy old compile keeps haunting us.
    php artisan view:clear   || true
    php artisan view:cache   || true
    php artisan event:cache  || true
    php artisan storage:link || true
    php artisan filament:optimize || true
    php artisan livewire:publish --assets --no-interaction || true
fi

case "$ROLE" in
    app)
        echo "[entrypoint] Starting nginx + php-fpm via supervisord..."
        exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
        ;;
    queue)
        echo "[entrypoint] Starting queue worker..."
        exec php artisan queue:work --sleep=2 --tries=3 --max-time=3600 --timeout=180 --backoff=5
        ;;
    scheduler)
        echo "[entrypoint] Starting scheduler loop..."
        exec php artisan schedule:work
        ;;
    artisan)
        shift || true
        exec php artisan "$@"
        ;;
    bash|sh)
        exec /bin/bash
        ;;
    *)
        echo "[entrypoint] Unknown role '$ROLE'." >&2
        exit 1
        ;;
esac
