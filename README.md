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
  <a href="https://github.com/cellar-backup/cellar/releases"><img src="https://img.shields.io/github/v/release/cellar-backup/cellar?style=flat-square&color=6C5CE7" alt="Release" /></a>
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
- **Custom Backup Documents** — extend Cellar for any workload with simple YAML manifests
- **Beautiful dark-mode UI** — a modern Vue 3 interface that makes you want to check your backups
- **Single container** — one image, one port, one volume — deploy in seconds

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

Open **http://localhost:8420** — a setup wizard will guide you through creating your admin account and configuring your instance.

> **Tip:** Point `REDIS_HOST` to an existing Redis instance instead of running a separate one:
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

| Process         | Role              | Stack                          |
| --------------- | ----------------- | ------------------------------ |
| **nginx**       | Reverse proxy     | Nginx (port 8420)              |
| **api**         | REST API          | PHP 8.4 + Laravel 11 + Sanctum |
| **horizon**     | Async job runner  | Laravel Horizon                |
| **scheduler**   | Cron scheduler    | Laravel Scheduler              |
| **reverb**      | WebSocket server  | Laravel Reverb                 |

**External dependencies:**

- **Redis** (required) — queue, cache, and WebSocket pub/sub
- **Database:** SQLite by default (zero config). PostgreSQL available via overlay — see [Advanced Deployment](#advanced-deployment).

## Volumes

| Path                | Purpose                          |
| ------------------- | -------------------------------- |
| `/app/data`         | SQLite database, app key         |
| `/data/repositories`| Backup repositories (borg/restic)|

## Environment Variables

All variables have sensible defaults. Override only what you need:

| Variable                    | Default                    | Description                          |
| --------------------------- | -------------------------- | ------------------------------------ |
| `APP_URL`                   | `http://localhost:8420`    | Public URL for this instance         |
| `REDIS_HOST`                | `redis`                   | Redis hostname                       |
| `REDIS_PORT`                | `6379`                    | Redis port                           |
| `REDIS_PASSWORD`            | *(none)*                  | Redis password (if auth enabled)     |
| `CELLAR_MAX_PARALLEL_JOBS`  | `2`                       | Max concurrent backup/restore jobs   |
| `SANCTUM_STATEFUL_DOMAINS`  | `localhost:8420,localhost` | Cookie auth domains (CORS)           |

See [`.env.example`](.env.example) for the full list.

## Advanced Deployment

### Docker Compose

For more control or PostgreSQL support, use Docker Compose:

```bash
git clone https://github.com/cellar-backup/cellar.git
cd cellar
cp .env.example .env    # edit as needed
docker compose up -d
```

For PostgreSQL instead of SQLite:

```bash
docker compose -f docker-compose.yml -f docker/docker-compose.postgres.yml up -d
```

Set `CELLAR_DB_NAME`, `CELLAR_DB_USER`, and `CELLAR_DB_PASSWORD` in your `.env`.

### Kubernetes

A sample manifest is provided in [`docker/kubernetes/cellar.yaml`](docker/kubernetes/cellar.yaml). It deploys Cellar + Redis in a single namespace:

```bash
kubectl apply -f docker/kubernetes/cellar.yaml
```

## Project Structure

```
cellar/
├── backend/          # Laravel project — API, Eloquent models, queue jobs
│   ├── app/
│   │   ├── Console/      # Artisan commands, scheduler
│   │   ├── Enums/        # PHP 8.1+ backed enums
│   │   ├── Http/         # Controllers, middleware
│   │   ├── Jobs/         # Queue jobs (backup, restore, prune, verify)
│   │   ├── Models/       # Eloquent models
│   │   └── Services/     # Backup engines (Borg), DB dumper/restorer
│   ├── config/           # Laravel + Cellar + Horizon config
│   ├── database/         # Migrations
│   └── routes/           # API routes, scheduler definitions
├── frontend/         # Vue 3 SPA — UI components, stores, views
└── docker/           # Dockerfiles, Nginx config, supervisord, entrypoint
```

## Development

```bash
# Backend (PHP 8.4+, Composer)
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan cellar:seed-defaults
php artisan serve

# Frontend (Node 20+)
cd frontend
npm install
npm run dev
```

## Makefile

Common commands are available via `make`:

```bash
make up          # Start all containers
make down        # Stop all containers
make build       # Build Docker images
make logs        # Tail container logs
make shell       # Shell into the API container
make migrate     # Run database migrations
make seed        # Run default seeder
make tinker      # Open Laravel Tinker REPL
```

## Roadmap

| Phase          | Version   | Focus                                                          |
| -------------- | --------- | -------------------------------------------------------------- |
| **Foundation** | v0.1–v0.3 | Core backup/restore, Borg engine, local + S3, basic UI         |
| **Expansion**  | v0.4–v0.7 | Custom documents, restore wizard, multi-backend, notifications |
| **Polish**     | v0.8–v1.0 | Multi-user RBAC, audit log, metrics, mobile views, v1.0 launch |

## License

[Apache License 2.0](LICENSE) — use it, fork it, contribute back.
