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
- **Container-native** — single `docker compose up -d` to deploy everything

## Quick Start

```bash
# Clone the repo
git clone https://github.com/cellar-backup/cellar.git
cd cellar

# Launch (that's it — zero config required)
docker compose up -d

# Open http://localhost:8420
# Default credentials: admin / changeme
```

## Architecture

| Container            | Role             | Stack                          |
| -------------------- | ---------------- | ------------------------------ |
| **cellar-api**       | REST API         | PHP 8.4 + Laravel 11 + Sanctum |
| **cellar-worker**    | Async job runner | Laravel Horizon + Redis        |
| **cellar-scheduler** | Cron scheduler   | Laravel Scheduler              |
| **cellar-ui**        | Frontend SPA     | Vue 3 + Vite + Tailwind CSS    |
| **cellar-redis**     | Queue & cache    | Redis 7                        |
| **cellar-proxy**     | Reverse proxy    | Caddy 2                        |

> **Database:** SQLite by default — zero config, single file, trivially backed up.

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
└── docker/           # Dockerfiles, Caddy config, entrypoint
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
