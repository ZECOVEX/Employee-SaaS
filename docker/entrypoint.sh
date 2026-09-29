#!/bin/sh
set -eu

cd /var/www/html

as_www_data() {
    if [ "$(id -u)" = "0" ]; then
        su -s /bin/sh -c "$1" www-data
    else
        sh -c "$1"
    fi
}

# APP_KEY may arrive through the environment (env_file / -e). Otherwise
# generate one into a bare .env so a from-scratch container still boots.
if [ -z "${APP_KEY:-}" ]; then
    [ -f .env ] || touch .env
    php artisan key:generate --force
fi

# Fresh SQLite database file before the first migrate.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    SQLITE_PATH="${DB_DATABASE:-database/database.sqlite}"
    SQLITE_DIR=$(dirname "$SQLITE_PATH")
    if [ "$(id -u)" = "0" ]; then
        mkdir -p "$SQLITE_DIR"
        chown www-data:www-data "$SQLITE_DIR"
        if [ ! -f "$SQLITE_PATH" ]; then
            touch "$SQLITE_PATH"
            chown www-data:www-data "$SQLITE_PATH"
        fi
    else
        mkdir -p "$SQLITE_DIR"
        [ -f "$SQLITE_PATH" ] || touch "$SQLITE_PATH"
    fi
fi

# Public-disk symlink for uploaded photos (idempotent).
php artisan storage:link >/dev/null 2>&1 || true

as_www_data 'php artisan package:discover --ansi'
as_www_data 'php artisan config:cache --ansi'
# route:cache is intentionally skipped — routes/web.php uses closures.
as_www_data 'php artisan view:cache --ansi'

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    as_www_data 'php artisan migrate --force'
fi

# Platform plan catalog (§54) — idempotent, keeps billing working out of the box.
if [ "${SKIP_PLAN_SEEDING:-false}" != "true" ]; then
    as_www_data 'php artisan db:seed --class="Database\Seeders\PlanSeeder" --force'
fi

exec "$@"
