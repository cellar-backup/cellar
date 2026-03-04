#!/bin/bash
# =============================================================================
# Cellar API — Docker entrypoint
# Runs migrations + seeds defaults on first boot, then execs into the
# main process (artisan serve, horizon, schedule:work, etc.).
# =============================================================================

set -e

echo "🍷 Cellar — starting up …"

# Ensure data directories exist
mkdir -p /app/data /data/repositories /var/log/cellar

# Always use .env.docker as the runtime env in Docker
if [ -f /app/.env.docker ]; then
    cp /app/.env.docker /app/.env
fi

# Generate app key if not set
if ! grep -q "^APP_KEY=base64:" /app/.env 2>/dev/null; then
    php artisan key:generate --no-interaction
fi

# Ensure SQLite database file exists
DB_PATH="${DB_DATABASE:-/app/data/cellar.sqlite}"
if [ ! -f "$DB_PATH" ]; then
    touch "$DB_PATH"
    echo "→ Created SQLite database at $DB_PATH"
fi

# Run migrations (idempotent — safe on every boot)
echo "→ Running database migrations …"
php artisan migrate --force --no-interaction

# Seed defaults (admin user + default repository)
php artisan cellar:seed-defaults

echo "→ Boot complete. Launching: $@"
exec "$@"
