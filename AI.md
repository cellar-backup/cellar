# Cellar — AI Project Reference

> **Generated:** 2026-04-21 (v0.12.0)
> **Purpose:** Comprehensive project context for AI-assisted development.

---

## 1. Overview

**Cellar** is an open-source, container-native backup management platform. It provides deduplication-first backups (via BorgBackup/Restic), database + filesystem support, Kubernetes auto-discovery, and a modern Vue 3 SPA with a "Warm Wine Cellar" design system.

- **Repo:** `github.com/cellar-backup/cellar`
- **License:** Apache 2.0
- **Default port:** 8420
- **Default credentials:** `admin` / `changeme` (seeded via `php artisan db:seed`)

### Architecture (unified single container)

All services run inside one container via supervisord:

| Process       | Role             | Stack                          |
|---------------|------------------|--------------------------------|
| **nginx**     | Reverse proxy    | Nginx (port 8420)              |
| **api**       | REST API + SPA   | PHP 8.5 + Laravel 12 + Sanctum |
| **horizon**   | Async job runner | Laravel Horizon + Redis        |
| **scheduler** | Cron scheduler   | Laravel Scheduler              |
| **reverb**    | WebSocket server | Laravel Reverb                 |

**External dependency:** Redis (required) — queue, cache, WebSocket pub/sub.

**Database:** SQLite by default (zero config). PostgreSQL available via Docker Compose overlay.

---

## 2. Project Structure

```
cellar/
├── AI.md                       # This file
├── CLAUDE.md                   # Points to AI.md
├── Makefile                    # Dev shortcuts
├── docker-compose.yml          # Multi-container dev setup
├── vite.config.ts              # Vite + laravel-vite-plugin
├── tsconfig.json               # TypeScript config
├── package.json                # Node dependencies (frontend)
├── composer.json               # PHP dependencies (backend)
├── artisan                     # Laravel CLI entrypoint
│
├── app/                        # PHP application
│   ├── Console/Commands/       # 3 Artisan commands
│   ├── Enums/                  # 8 PHP backed enums
│   ├── Events/                 # JobUpdated broadcast event
│   ├── Http/Controllers/Api/V1/  # 13 controllers
│   ├── Jobs/                   # 4 queue jobs (backup, prune, restore, verify)
│   ├── Models/                 # 12 Eloquent models
│   ├── Observers/              # JobObserver (broadcasts on create/update)
│   ├── Providers/              # AppServiceProvider
│   └── Services/               # Core business logic
│       ├── DatabaseDumper.php
│       ├── DatabaseInspector.php
│       ├── DatabaseRestorer.php
│       ├── JobLogger.php
│       ├── KubernetesDiscovery.php
│       └── Engines/
│           ├── BackupEngine.php  (interface)
│           └── BorgEngine.php    (implementation)
│
├── config/cellar.php           # Cellar-specific config
├── database/migrations/        # 18 migration files
├── routes/
│   ├── api.php                 # API routes (v1, Sanctum auth)
│   ├── web.php                 # SPA catch-all route
│   ├── channels.php            # WebSocket channel auth
│   └── console.php             # Scheduler + cron logic
│
├── resources/
│   ├── js/                     # Vue 3 SPA source
│   │   ├── app.ts             # Entry point
│   │   ├── App.vue            # Root component
│   │   ├── components/        # 9 UI components
│   │   ├── composables/       # 7 composables (theme, toast, activeDb, etc.)
│   │   ├── lib/               # API client, Echo, utilities
│   │   ├── router/            # Vue Router with auth guards
│   │   ├── stores/            # 5 Pinia stores
│   │   └── views/             # 11 view components
│   ├── css/app.css            # Tailwind + design system tokens
│   └── views/app.blade.php    # Laravel Blade SPA shell
│
├── public/                     # Web root (served by nginx)
│   ├── build/                  # Vite output (gitignored)
│   └── logo.svg
│
└── docker/
    ├── unified/
    │   ├── Dockerfile          # Multi-stage: Node build + PHP runtime
    │   ├── entrypoint.sh       # Migrations + seed on boot
    │   ├── nginx.conf          # Assets + Laravel proxy + WebSocket
    │   └── supervisord.conf    # All 5 processes
    └── kubernetes/cellar.yaml  # Sample K8s manifest
```

---

## 3. Environment & Configuration

**File:** `.env` (copied from `.env.example`)

| Variable | Default | Purpose |
|----------|---------|---------|
| `APP_URL` | `http://localhost:8420` | Public URL |
| `DB_CONNECTION` | `sqlite` | Database driver |
| `DB_DATABASE` | `/app/data/cellar.sqlite` | SQLite path |
| `REDIS_HOST` | `redis` | Redis for queue/cache |
| `QUEUE_CONNECTION` | `redis` | Job queue driver |
| `TRUSTED_PROXIES` | `*` | Trusted proxy IPs (`*` = all, for Docker/K8s) |
| `CELLAR_MAX_PARALLEL_JOBS` | `2` | Concurrent job limit |
| `CELLAR_ADMIN_PASSWORD` | _(none)_ | Initial admin password |
| `REVERB_HOST` | `127.0.0.1` | WebSocket server host |
| `REVERB_PORT` | `8080` | WebSocket server port |

---

## 4. Key Dependencies

### Backend (PHP)

| Package | Purpose |
|---------|---------|
| `laravel/framework` 12.x | Core framework |
| `laravel/horizon` | Redis queue dashboard & worker |
| `laravel/reverb` | WebSocket server (Pusher protocol) |
| `laravel/sanctum` | Bearer token authentication |

### Frontend (Node)

| Package | Purpose |
|---------|---------|
| `vue` 3.5 | UI framework |
| `pinia` 3.x | State management |
| `vue-router` 5.x | Client-side routing |
| `axios` | HTTP client |
| `laravel-echo` + `pusher-js` | WebSocket client |
| `tailwindcss` 4.x | Utility CSS |
| `lucide-vue-next` | Icons |
| `radix-vue` | Accessible primitives |
| `laravel-vite-plugin` | Laravel-Vite integration |

---

## 5. API Routes

All routes prefixed with `/api/v1`. Auth uses Laravel Sanctum Bearer tokens.

### Public

| Method | Path | Action |
|--------|------|--------|
| POST | `auth/login` | `AuthController@login` |
| POST | `setup` | `SetupController@store` |
| GET | `system/health` | `SystemController@health` |

### Authenticated

| Method | Path | Action |
|--------|------|--------|
| POST | `auth/logout` | Logout |
| GET | `auth/me` | Current user |
| CRUD | `sources` | Source management |
| POST | `sources/quick-add` | One-step database + plan creation |
| POST | `sources/{id}/test-connection` | Test connectivity |
| PATCH | `sources/{id}/toggle` | Enable/disable |
| GET | `sources/{id}/policies` | List plans for source |
| GET | `sources/{id}/archives` | List backups for source |
| CRUD | `plans` | Backup plan management |
| POST | `plans/{id}/backup` | Trigger backup |
| POST | `plans/{id}/prune` | Trigger prune |
| POST | `plans/{id}/verify` | Trigger verify |
| GET | `jobs` | Job history |
| GET | `jobs/{id}/log` | Job log output |
| POST | `jobs/{id}/cancel` | Cancel running job |
| CRUD | `archives` | Backup archive management |
| PATCH | `archives/{id}/keep-forever` | Pin/unpin |
| POST | `archives/{id}/restore` | Restore from archive |
| GET | `archives/{id}/download` | Download dump file |
| CRUD | `profiles` | Schedule & retention presets |
| GET/PUT | `settings` | System settings |
| POST | `kubernetes/clusters/{id}/discover` | K8s resource discovery |
| POST | `kubernetes/clusters/{id}/import` | Import discovered sources |

---

## 6. Data Models (12)

All models use UUID primary keys (`HasUuids` trait).

| Model | Key Fields | Relationships |
|-------|-----------|---------------|
| `User` | username, email, password (hashed) | Sanctum tokens |
| `Repository` | name, backend_type, config (encrypted) | hasMany BackupPlan |
| `Source` | name, source_type, host, port, password (encrypted), enabled | hasMany BackupPlan |
| `BackupPlan` | schedule_cron, retention_policy, engine | belongsTo Source + Repository |
| `Job` | job_type, status, progress (0-100) | belongsTo BackupPlan |
| `Archive` | archive_id, timestamp, size_*, file_count, keep_forever | belongsTo BackupPlan |
| `Profile` | type (schedule/retention), config, is_default | Scopes: retention(), schedule() |
| `AppSetting` | key, value | Static get()/set() helpers |
| `RadarCluster` | name, kubeconfig (encrypted) | hasMany RadarIgnore |

---

## 7. Frontend Architecture

### Design System — "Warm Wine Cellar"

- **Colors:** OKLCH-based — burgundy primary, oak mid-tones, cream paper (light), smoky charcoal (dark)
- **Typography:** DM Serif Display (headings), Geist (UI), Geist Mono (data)
- **Motion:** Spring animations, pour-in reveals, pulse dots, configurable (full/subtle/none)
- **Themes:** Dark (default) + Light, toggled via `data-theme` attribute

### Views

| Route | Component | Purpose |
|-------|-----------|---------|
| `/` | BackupsView | Timeline/list of backups for active database |
| `/schedule` | ScheduleView | Per-database backup schedule + countdown |
| `/jobs` | JobsView | Job history table with progress |
| `/storage` | StorageView | Donut chart + per-database breakdown |
| `/radar` | RadarView | Kubernetes cluster discovery |
| `/settings` | SettingsView | System, retention profiles, schedule profiles, appearance |
| `/login` | LoginView | Authentication |
| `/setup` | SetupView | First-time setup wizard |

### Key Patterns

- **Active database:** Shared via `useActiveDatabase` composable — sidebar sets it, views consume it
- **WebSocket:** Single global listener in `AppSidebar.vue` on `jobs` channel
- **Toasts:** Global `useToast` composable + `ToastStack` component in App.vue
- **Theme:** `useTheme` composable syncs `data-theme`/`data-motion` to `<body>` + localStorage

---

## 8. Background Jobs

| Job | Timeout | Flow |
|-----|---------|------|
| `RunBackup` | 8h | Init repo → estimate size → dump → create archive → record |
| `RunPrune` | 1h | Apply retention policy → reconcile archive records |
| `RunRestore` | 2h | Extract archive → find dump → restore via DatabaseRestorer |
| `RunVerify` | 1h | Check repo/archive integrity |

### Scheduler (routes/console.php)

- Every minute: dispatch scheduled backups/prunes based on plan cron expressions
- Daily 03:00: sync archives with repository
- Every 15 min: check source health connectivity
- Daily 04:00: cleanup old job logs (30 day retention)

---

## 9. Infrastructure

### Docker (unified image)

- **Base:** `php:8.5-cli` + nginx + supervisor
- **Build:** Multi-stage (Node for frontend, PHP for backend)
- **Port:** 8420
- **Volumes:** `/app/data` (SQLite + key), `/data/repositories` (backup repos)
- **External:** Redis required

### CI/CD (.github/workflows/ci.yml)

| Job | Runs | Trigger |
|-----|------|---------|
| Backend | Pint + PHPUnit | Always |
| Frontend | ESLint + vue-tsc + Vite build | Always |
| Security | composer audit + npm audit | Always |
| Container Scan | Trivy (CRITICAL/HIGH) | PRs only |
| Docker Build | Build + push to GHCR | Push to main/tags |
| Release | GitHub Release | Version tags |

### Image registry

`ghcr.io/cellar-backup/cellar` — tagged with semver, SHA, `latest`

---

## 10. Build & Dev Commands

```bash
# Development
make dev              # Start Vite + Laravel serve
php artisan serve     # Start Laravel API on :8000
npm run dev           # Start Vite dev server

# Build
npm run build         # Build frontend → public/build/
make build-frontend   # Same

# Quality
npm run type-check    # vue-tsc --noEmit
npm run lint          # ESLint
vendor/bin/pint       # PHP code style
php artisan test      # PHPUnit

# Database
php artisan migrate              # Run migrations
php artisan db:seed              # Seed admin user
php artisan db:seed --class=DemoSeeder  # Seed demo data

# Docker
docker compose up -d             # Start dev environment
make up / make down / make logs  # Docker shortcuts
```

---

## 11. Design Decisions

| Decision | Rationale |
|----------|-----------|
| Monolithic Laravel structure | Single codebase, unified deploys, Vite integration |
| SQLite default | Zero-config for HomeLab operators, one volume to backup |
| UUID primary keys | Safe for distributed/replicated setups |
| BorgBackup engine | Best-in-class deduplication for homelab-scale data |
| Single container | Simplest deployment — one image, one port, two volumes |
| Redis required | Reliable queue + pub/sub for WebSocket broadcasting |
| Sanctum tokens | Simple bearer auth, stored in localStorage |
| OKLCH color system | Perceptually uniform, better dark/light theme control |
| laravel-vite-plugin | Native Laravel asset pipeline, no separate frontend server in prod |
