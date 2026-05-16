# =============================================================================
# Cellar — Unified Single-Container Image
#
# All-in-one: Nginx + PHP API + Horizon Worker + Scheduler + Reverb WebSocket
# Requires an external Redis instance (set REDIS_HOST).
#
# Single port: 8420
# Single volume: /app/data (SQLite + app key)
# Second volume: /data/repositories (backup repos)
# =============================================================================

# ── Stage 1: Build frontend ──────────────────────────────────
FROM node:25-alpine AS frontend-build

WORKDIR /build
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

COPY vite.config.ts tsconfig.json env.d.ts ./
COPY resources/ resources/
RUN npm run build

# ── Stage 2: Unified runtime ─────────────────────────────────
FROM php:8.5-cli

LABEL org.opencontainers.image.title="Cellar"
LABEL org.opencontainers.image.description="Container-native backup management platform"
LABEL org.opencontainers.image.source="https://github.com/cellar-backup/cellar"

# System dependencies
RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
    # Backup engines
    borgbackup \
    restic \
    # Database clients
    postgresql-client \
    default-mysql-client \
    jq \
    # Web server & process manager
    nginx \
    supervisor \
    # Build deps
    curl \
    unzip \
    git \
    libpq-dev \
    libzip-dev \
    libsqlite3-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql pdo_sqlite zip pcntl bcmath \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Install kubectl for Kubernetes Radar feature (pinned to v1.35.5 / Go 1.25.9; patches CVE-2026-25679, CVE-2026-32281, CVE-2026-32283)
ARG KUBECTL_VERSION=v1.35.5
RUN curl -fsSL "https://dl.k8s.io/release/${KUBECTL_VERSION}/bin/linux/$(dpkg --print-architecture)/kubectl" \
    -o /usr/local/bin/kubectl \
    && chmod +x /usr/local/bin/kubectl

# Install couchdb-dump (danielebailo/couchdb-dump) — bash script for CouchDB backup/restore
# Pinned to a specific commit since the repo has no versioned releases.
ARG COUCHDB_DUMP_COMMIT=3644fea72d9bde369492a8e4aac0f591a8593008
RUN curl -fsSL "https://raw.githubusercontent.com/danielebailo/couchdb-dump/${COUCHDB_DUMP_COMMIT}/couchdb-dump.sh" \
    -o /usr/local/bin/couchdb-dump \
    && chmod +x /usr/local/bin/couchdb-dump

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ── PHP application ──────────────────────────────────────────
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY app/ app/
COPY bootstrap/ bootstrap/
COPY config/ config/
COPY database/ database/
COPY public/ public/
COPY resources/views/ resources/views/
COPY routes/ routes/
COPY storage/ storage/
COPY artisan .
COPY .env.example .

RUN composer install --no-dev --optimize-autoloader --prefer-dist

# ── Frontend static files (built by Vite, goes into public/build/) ──
COPY --from=frontend-build /build/public/build /app/public/build

# ── Config files ─────────────────────────────────────────────
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/cellar.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# ── Static assets ────────────────────────────────────────────
COPY logo.svg /app/public/logo.svg

# ── Directories & permissions ────────────────────────────────
RUN mkdir -p \
    /app/data \
    /data/repositories \
    /app/data/logs \
    /var/log/supervisor \
    /var/run/nginx \
    storage/framework/{cache,sessions,testing,views} \
    && chown -R www-data:www-data /app/data /data/repositories storage bootstrap/cache

# ── Environment defaults ─────────────────────────────────────
ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost:8420 \
    TRUSTED_PROXIES=* \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/data/cellar.sqlite \
    # Redis (external — set REDIS_HOST to your Redis instance)
    REDIS_HOST=redis \
    REDIS_PORT=6379 \
    QUEUE_CONNECTION=redis \
    CACHE_STORE=redis \
    # Broadcasting (Reverb)
    BROADCAST_CONNECTION=reverb \
    REVERB_APP_ID=cellar \
    REVERB_HOST=127.0.0.1 \
    REVERB_PORT=8080 \
    REVERB_SCHEME=http \
    REVERB_SERVER_HOST=127.0.0.1 \
    REVERB_SERVER_PORT=8080 \
    REVERB_SCALING_ENABLED=true \
    # Cellar
    CELLAR_BORG_PATH=/usr/bin/borg \
    CELLAR_RESTIC_PATH=/usr/bin/restic \
    CELLAR_MAX_PARALLEL_JOBS=2 \
    CELLAR_LOG_DIR=/app/data/logs

EXPOSE 8420

VOLUME ["/app/data", "/data/repositories"]

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/cellar.conf"]
