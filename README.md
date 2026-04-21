<p align="center">
  <img src="logo.svg" alt="Cellar" width="80" />
</p>

<h1 align="center">Cellar</h1>

<p align="center">
  <em>Your backups, preserved.</em>
</p>

<p align="center">
  <a href="https://github.com/cellar-backup/cellar/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/cellar-backup/cellar/ci.yml?branch=main&style=flat-square" alt="CI" /></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-Apache%202.0-blue?style=flat-square" alt="License" /></a>
  <a href="https://github.com/cellar-backup/cellar/releases"><img src="https://img.shields.io/github/v/release/cellar-backup/cellar?style=flat-square&color=6B2D3A" alt="Release" /></a>
  <a href="https://github.com/cellar-backup/cellar/pkgs/container/cellar"><img src="https://img.shields.io/badge/ghcr.io-cellar--backup%2Fcellar-blue?style=flat-square&logo=docker" alt="Container" /></a>
</p>

<p align="center">
  Open-source, container-native backup management for HomeLab operators.<br />
  Deduplication-first. Beautiful UI. Database &amp; filesystem support. Extensible.
</p>

---

## Why Cellar?

Existing backup tools force you to choose between **power** (borgmatic, restic CLI) and **polish** (GUI-only tools limited to database dumps). Cellar bridges the gap:

- **Deduplication-first** — powered by BorgBackup / restic, store only unique data blocks
- **Database + filesystem** — native support for PostgreSQL, MySQL, MongoDB, SQLite, Redis, plus any directory or Docker volume
- **Kubernetes Radar** — auto-discover databases and volumes in your clusters
- **Beautiful wine-themed UI** — a modern Vue 3 interface with dark/light themes, timeline views, and spring animations
- **Single container** — one image, one port, two volumes — deploy in seconds

## Quick Start

Cellar requires a Redis instance for queues and caching. The easiest way to get started:

```bash
# Start Redis
docker run -d --name cellar-redis redis:7-alpine

# Start Cellar
docker run -d \
  --name cellar \
  --link cellar-redis:redis \
  -p 8420:8420 \
  -v cellar-data:/app/data \
  -v cellar-repos:/data/repositories \
  ghcr.io/cellar-backup/cellar:latest
```

Open **http://localhost:8420** — a setup wizard will guide you through creating your admin account.

> **Tip:** Point `REDIS_HOST` to an existing Redis instance:
>
> ```bash
> docker run -d \
>   --name cellar \
>   -p 8420:8420 \
>   -e REDIS_HOST=your-redis-host \
>   -e APP_URL=https://cellar.example.com \
>   -v cellar-data:/app/data \
>   -v cellar-repos:/data/repositories \
>   ghcr.io/cellar-backup/cellar:latest
> ```

## Architecture

Cellar ships as a **single unified container** that runs all services via supervisord:

| Process       | Role             | Stack                          |
|---------------|------------------|--------------------------------|
| **nginx**     | Reverse proxy    | Nginx (port 8420)              |
| **api**       | REST API + SPA   | PHP 8.5 + Laravel 12 + Sanctum |
| **horizon**   | Async job runner | Laravel Horizon + Redis        |
| **scheduler** | Cron scheduler   | Laravel Scheduler              |
| **reverb**    | WebSocket server | Laravel Reverb                 |

**External dependencies:**

- **Redis** (required) — queue, cache, and WebSocket pub/sub
- **Database:** SQLite by default (zero config). PostgreSQL available via overlay.

## Volumes

| Path                | Purpose                           |
|---------------------|-----------------------------------|
| `/app/data`         | SQLite database, app key          |
| `/data/repositories`| Backup repositories (borg/restic) |

## Environment Variables

All variables have sensible defaults. Override only what you need:

| Variable                   | Default                 | Description                        |
|----------------------------|-------------------------|------------------------------------|
| `APP_URL`                  | `http://localhost:8420` | Public URL for this instance       |
| `REDIS_HOST`               | `redis`                 | Redis hostname                     |
| `REDIS_PORT`               | `6379`                  | Redis port                         |
| `REDIS_PASSWORD`           | *(none)*                | Redis password (if auth enabled)   |
| `TRUSTED_PROXIES`          | `*`                     | Trusted proxy IPs (`*` = trust all) |
| `CELLAR_MAX_PARALLEL_JOBS` | `2`                     | Max concurrent backup/restore jobs |

See [`.env.example`](.env.example) for the full list.

## Project Structure

```
cellar/
├── app/              # PHP application (controllers, models, services, jobs)
├── config/           # Laravel + Cellar configuration
├── database/         # Migrations and seeders
├── routes/           # API routes, web (SPA catch-all), scheduler
├── resources/
│   ├── js/           # Vue 3 SPA (components, stores, views, composables)
│   ├── css/          # Tailwind + design system tokens
│   └── views/        # Blade template (SPA shell)
├── public/           # Web root (Vite build output)
├── docker/           # Dockerfile, nginx, supervisord, entrypoint
├── vite.config.ts    # Vite + Laravel plugin
└── package.json      # Node dependencies
```

## Development

```bash
# Install dependencies
composer install
npm install

# Set up environment
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed                        # Admin user
php artisan db:seed --class=DemoSeeder     # Demo data (optional)

# Start dev servers
php artisan serve     # Laravel API on :8000
npm run dev           # Vite dev server with HMR
```

## Makefile

Common commands are available via `make`:

```bash
make dev             # Start both Vite + Laravel serve
make up              # Start Docker Compose services
make down            # Stop services
make migrate         # Run database migrations
make seed            # Seed admin + demo data
make lint            # ESLint + Pint
make typecheck       # TypeScript type checker
make test            # Run all tests
```

## Advanced Deployment

### Docker Compose

```bash
git clone https://github.com/cellar-backup/cellar.git
cd cellar
cp .env.example .env
docker compose up -d
```

### Kubernetes

A sample manifest is provided in [`docker/kubernetes/cellar.yaml`](docker/kubernetes/cellar.yaml):

```bash
kubectl apply -f docker/kubernetes/cellar.yaml
```

### Reverse Proxy

Cellar uses WebSockets (Laravel Reverb) for real-time job updates. Your reverse proxy must support WebSocket upgrades on the `/app/` path.

**nginx-ingress (Kubernetes):**

```yaml
annotations:
  nginx.ingress.kubernetes.io/proxy-read-timeout: "86400"
  nginx.ingress.kubernetes.io/proxy-send-timeout: "86400"
  nginx.ingress.kubernetes.io/proxy-http-version: "1.1"
  nginx.ingress.kubernetes.io/configuration-snippet: |
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
```

**Nginx (standalone):**

```nginx
location /app/ {
    proxy_pass http://cellar:8420;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 86400s;
}

location / {
    proxy_pass http://cellar:8420;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

**Caddy:**

```caddyfile
cellar.example.com {
    reverse_proxy cellar:8420
}
```

Caddy handles WebSocket upgrades automatically.

> **Important:** Set `TRUSTED_PROXIES=*` (or your proxy's IP) so Laravel recognizes HTTPS behind the proxy. Without this, asset URLs will be generated as `http://` causing mixed-content errors.

## Roadmap

| Phase          | Version    | Status | Focus |
|----------------|------------|--------|-------|
| **Foundation** | v0.1–v0.3  | Done   | Core backup/restore, Borg engine, local repos, basic UI |
| **Expansion**  | v0.4–v0.7  | Done   | Real-time WebSocket, database dumpers, retention, profiles |
| **Polish**     | v0.8–v0.11 | Done   | Health checks, scheduling, settings, Kubernetes Radar |
| **Redesign**   | v0.12      | Now    | Wine-cellar UI, timeline views, storage dashboard |
| **Release**    | v0.13–v1.0 | Next   | Multi-user RBAC, notifications, restic engine, mobile views |

## License

[Apache License 2.0](LICENSE) — use it, fork it, contribute back.
