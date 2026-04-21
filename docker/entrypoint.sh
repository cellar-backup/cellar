#!/bin/bash
# =============================================================================
# Cellar API — Docker entrypoint
# Runs migrations + seeds defaults on first boot, then execs into the
# main process (artisan serve, horizon, schedule:work, etc.).
# =============================================================================

set -e

echo "Cellar — starting up …"

# Ensure data directories exist
mkdir -p /app/data /data/repositories /var/log/cellar

# ── .env from template ───────────────────────────
if [ ! -f /app/.env ]; then
    if [ -f /app/.env.docker ]; then
        cp /app/.env.docker /app/.env
    elif [ -f /app/.env.example ]; then
        cp /app/.env.example /app/.env
    else
        cat > /app/.env <<ENVFILE
APP_NAME=Cellar
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=${APP_URL:-http://localhost:8420}
DB_CONNECTION=sqlite
DB_DATABASE=${DB_DATABASE:-/app/data/cellar.sqlite}
REDIS_HOST=${REDIS_HOST:-redis}
REDIS_PORT=${REDIS_PORT:-6379}
REDIS_PASSWORD=${REDIS_PASSWORD:-}
REDIS_DB=${REDIS_DB:-0}
QUEUE_CONNECTION=redis
CACHE_STORE=redis
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=cellar
REVERB_APP_KEY=cellar-key
REVERB_APP_SECRET=cellar-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
ENVFILE
        echo "→ Generated .env from defaults"
    fi
fi

# ── APP_KEY resolution ────────────────────────────
# Priority: 1) APP_KEY env var  2) persisted key file  3) generate new
KEY_FILE="/app/data/.app_key"
if [ -n "${APP_KEY:-}" ]; then
    if ! echo "$APP_KEY" | grep -q '^base64:'; then
        echo "WARNING: APP_KEY does not start with 'base64:' — this may cause Laravel crypto failures" >&2
    fi
    sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" /app/.env
    echo "→ APP_KEY loaded from environment variable"
elif [ -f "$KEY_FILE" ] && [ -s "$KEY_FILE" ]; then
    sed -i "s|^APP_KEY=.*|APP_KEY=$(cat "$KEY_FILE")|" /app/.env
    echo "→ APP_KEY loaded from $KEY_FILE"
else
    NEW_KEY=$(php artisan key:generate --show --no-interaction)
    sed -i "s|^APP_KEY=.*|APP_KEY=${NEW_KEY}|" /app/.env
    echo -n "$NEW_KEY" > "$KEY_FILE"
    chmod 600 "$KEY_FILE"
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
