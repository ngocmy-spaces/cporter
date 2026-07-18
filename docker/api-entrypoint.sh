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
exec php artisan serve --host=0.0.0.0 --port=8000
