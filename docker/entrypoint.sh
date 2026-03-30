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

# ── APP_KEY resolution ────────────────────────────
# Priority: 1) APP_KEY env var  2) persisted key file  3) generate new
KEY_FILE="/app/data/.app_key"
if [ -n "${APP_KEY:-}" ]; then
    # Explicit env var (k8s Secret, docker-compose, etc.) — use it
    sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" /app/.env
    echo "→ APP_KEY loaded from environment variable"
elif [ -f "$KEY_FILE" ] && [ -s "$KEY_FILE" ]; then
    # Restore previously generated key from persistent volume
    sed -i "s|^APP_KEY=.*|APP_KEY=$(cat "$KEY_FILE")|" /app/.env
    echo "→ APP_KEY loaded from $KEY_FILE"
else
    # First boot without explicit key — generate and persist
    NEW_KEY=$(php artisan key:generate --show --no-interaction)
    sed -i "s|^APP_KEY=.*|APP_KEY=${NEW_KEY}|" /app/.env
    echo -n "$NEW_KEY" > "$KEY_FILE"
    echo "→ APP_KEY generated and persisted to $KEY_FILE"
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
