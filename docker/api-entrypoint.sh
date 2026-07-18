#!/bin/sh
set -e

echo "[cporter] waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}…"
until php -r '
    try {
        new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:"3306"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
        exit(0);
    } catch (Throwable $e) { exit(1); }
' 2>/dev/null; do
    sleep 2
done
echo "[cporter] database is up."

# Install PHP deps into the (named-volume) vendor dir on first run.
if [ ! -f vendor/autoload.php ]; then
    echo "[cporter] composer install…"
    composer install --no-interaction --no-progress
fi

# The `worker` service only runs the scheduler; the `api` service migrates + serves.
if [ "${CPORTER_ROLE}" = "worker" ]; then
    echo "[cporter] starting scheduler (run-jobs + queue:work + housekeep)…"
    exec php artisan schedule:work
fi

echo "[cporter] migrate --seed…"
php artisan migrate --force --seed

echo "[cporter] serving on :8000 (admin: admin@cporter.local / password)"
# Use php's built-in server directly (not `artisan serve`, which forwards a computed env
# to a child process and would pick up the mounted .env over the compose env vars).
# server.php uses getcwd() as the public path, so run it from public/.
ROUTER="$PWD/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
cd public
exec php -S 0.0.0.0:8000 "$ROUTER"
