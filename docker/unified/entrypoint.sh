#!/bin/bash
# =============================================================================
# Cellar — Unified container entrypoint
#
# • Applies runtime env-var overrides to .env
# • Runs migrations + seeds defaults, then execs into supervisord
# =============================================================================

set -e

echo "Cellar — starting up …"

# ── Data directories ─────────────────────────────
mkdir -p /app/data /app/data/logs /data/repositories /var/log/nginx

# ── .env from template ───────────────────────────
if [ -f /app/.env.docker ]; then
    cp /app/.env.docker /app/.env
fi

# ── Reverb runs locally inside the container ─────
sed -i 's|REVERB_HOST=cellar-reverb|REVERB_HOST=127.0.0.1|' /app/.env

# ── Allow runtime env-var overrides ──────────────
for VAR in REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_DB APP_URL SANCTUM_STATEFUL_DOMAINS; do
    eval VAL=\${$VAR:-}
    if [ -n "$VAL" ]; then
        sed -i "s|^${VAR}=.*|${VAR}=${VAL}|" /app/.env
    fi
done

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

# ── Reverb key persistence ───────────────────────
REVERB_KEY_FILE="/app/data/.reverb_key"
REVERB_SECRET_FILE="/app/data/.reverb_secret"
if [ -f "$REVERB_KEY_FILE" ] && [ -f "$REVERB_SECRET_FILE" ]; then
    REVERB_KEY=$(cat "$REVERB_KEY_FILE")
    REVERB_SECRET=$(cat "$REVERB_SECRET_FILE")
else
    REVERB_KEY=$(head -c 20 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 20)
    REVERB_SECRET=$(head -c 32 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 32)
    echo -n "$REVERB_KEY" > "$REVERB_KEY_FILE"
    echo -n "$REVERB_SECRET" > "$REVERB_SECRET_FILE"
fi
sed -i "s|^REVERB_APP_KEY=.*|REVERB_APP_KEY=${REVERB_KEY}|" /app/.env
sed -i "s|^REVERB_APP_SECRET=.*|REVERB_APP_SECRET=${REVERB_SECRET}|" /app/.env

# Inject Reverb key into frontend SPA so Echo can connect
SPA_INDEX="/app/public/spa/index.html"
if [ -f "$SPA_INDEX" ]; then
    sed -i "s|<head>|<head><script>window.__REVERB_KEY__=\"${REVERB_KEY}\";</script>|" "$SPA_INDEX"
fi

# ── SQLite database ─────────────────────────────
DB_PATH="${DB_DATABASE:-/app/data/cellar.sqlite}"
if [ ! -f "$DB_PATH" ]; then
    touch "$DB_PATH"
    echo "→ Created SQLite database at $DB_PATH"
fi

# ── Migrations & seed ───────────────────────────
echo "→ Running database migrations …"
php artisan migrate --force --no-interaction
php artisan cellar:seed-defaults

# ── Launch main process ──────────────────────────
echo "→ Boot complete. Launching: $@ …"
exec "$@"
