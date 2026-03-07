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
mkdir -p /app/data /data/repositories /var/log/cellar /var/log/nginx

# ── .env from template ───────────────────────────
if [ -f /app/.env.docker ]; then
    cp /app/.env.docker /app/.env
fi

# ── Reverb runs locally inside the container ─────
sed -i 's|REVERB_HOST=cellar-reverb|REVERB_HOST=127.0.0.1|' /app/.env

# ── Allow runtime env-var overrides ──────────────
for VAR in REDIS_HOST REDIS_PORT REDIS_PASSWORD APP_URL SANCTUM_STATEFUL_DOMAINS; do
    eval VAL=\${$VAR:-}
    if [ -n "$VAL" ]; then
        sed -i "s|^${VAR}=.*|${VAR}=${VAL}|" /app/.env
    fi
done

# ── APP_KEY persistence ──────────────────────────
KEY_FILE="/app/data/.app_key"
if [ -f "$KEY_FILE" ]; then
    sed -i "s|^APP_KEY=.*|APP_KEY=$(cat "$KEY_FILE")|" /app/.env
else
    php artisan key:generate --no-interaction
    grep "^APP_KEY=" /app/.env | cut -d= -f2- > "$KEY_FILE"
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
