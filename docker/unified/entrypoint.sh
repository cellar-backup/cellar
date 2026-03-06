#!/bin/bash
# =============================================================================
# Cellar — Unified container entrypoint
#
# • Configures embedded Redis (optional, controlled by CELLAR_REDIS)
# • Overrides service hostnames to 127.0.0.1 for single-container mode
# • Runs migrations + seeds defaults, then execs into supervisord
# =============================================================================

set -e

echo "🍷 Cellar (unified) — starting up …"

# ── Data directories ─────────────────────────────
mkdir -p /app/data /data/repositories /var/log/cellar /var/log/nginx

# ── .env from template ───────────────────────────
if [ -f /app/.env.docker ]; then
    cp /app/.env.docker /app/.env
fi

# ── Override hosts for unified single-container mode ──
# Replace multi-container hostnames with localhost
sed -i 's|REDIS_HOST=cellar-redis|REDIS_HOST=127.0.0.1|' /app/.env
sed -i 's|REVERB_HOST=cellar-reverb|REVERB_HOST=127.0.0.1|' /app/.env

# ── Allow runtime env-var overrides ──────────────
# If user set REDIS_HOST externally (e.g. external Redis), honour it
if [ -n "${REDIS_HOST:-}" ] && [ "$REDIS_HOST" != "cellar-redis" ]; then
    sed -i "s|^REDIS_HOST=.*|REDIS_HOST=${REDIS_HOST}|" /app/.env
fi

if [ -n "${REDIS_PORT:-}" ]; then
    sed -i "s|^REDIS_PORT=.*|REDIS_PORT=${REDIS_PORT}|" /app/.env
fi

if [ -n "${REDIS_PASSWORD:-}" ]; then
    sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASSWORD}|" /app/.env
fi

# ── Embedded Redis toggle ────────────────────────
CELLAR_REDIS="${CELLAR_REDIS:-true}"
if [ "$CELLAR_REDIS" = "true" ]; then
    echo "→ Embedded Redis enabled"
    export CELLAR_REDIS_AUTOSTART=true
else
    echo "→ Embedded Redis disabled (using external: ${REDIS_HOST:-127.0.0.1}:${REDIS_PORT:-6379})"
    export CELLAR_REDIS_AUTOSTART=false
fi

# ── APP_KEY persistence ──────────────────────────
KEY_FILE="/app/data/.app_key"
if [ -f "$KEY_FILE" ]; then
    sed -i "s|^APP_KEY=.*|APP_KEY=$(cat "$KEY_FILE")|" /app/.env
else
    php artisan key:generate --no-interaction
    grep "^APP_KEY=" /app/.env | cut -d= -f2- > "$KEY_FILE"
fi

# ── Allow runtime APP_URL override ───────────────
if [ -n "${APP_URL:-}" ]; then
    sed -i "s|^APP_URL=.*|APP_URL=${APP_URL}|" /app/.env
fi

# Allow runtime SANCTUM_STATEFUL_DOMAINS override
if [ -n "${SANCTUM_STATEFUL_DOMAINS:-}" ]; then
    sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${SANCTUM_STATEFUL_DOMAINS}|" /app/.env
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
