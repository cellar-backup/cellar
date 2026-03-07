# Cellar — AI Project Reference

> **Updated:** 2026-03-07 (v0.8.0)
> **Purpose:** Comprehensive project context for AI-assisted development.
> **Local overrides:** See `AI.local.md` (gitignored) for machine-specific paths and credentials.

---

## 1. Overview

**Cellar** is an open-source, container-native backup management platform for HomeLab operators. It provides deduplication-first backups (via BorgBackup), database + filesystem support, and a modern Vue 3 UI.

- **Repo:** `github.com/cellar-backup/cellar`
- **License:** Apache 2.0
- **Default port:** 8420
- **Default credentials:** `admin` / `admin` (seeded via `cellar:seed-defaults`, changed during setup wizard)

### Architecture (unified single container)

All services run inside one container via supervisord:

| Process         | Role              | Stack                          |
| --------------- | ----------------- | ------------------------------ |
| **nginx**       | Reverse proxy     | Nginx (port 8420)              |
| **api**         | REST API          | PHP 8.4 + Laravel 12 + Sanctum |
| **horizon**     | Async job runner  | Laravel Horizon + Redis        |
| **scheduler**   | Cron scheduler    | Laravel Scheduler              |
| **reverb**      | WebSocket server  | Laravel Reverb                 |

**External dependency:** Redis (required) — queue, cache, WebSocket pub/sub.

**Database:** SQLite by default (zero config). PostgreSQL available via Docker Compose overlay.

### Roadmap

| Phase      | Version   | Status | Focus                                                          |
| ---------- | --------- | ------ | -------------------------------------------------------------- |
| Foundation | v0.1–v0.3 | Done   | Core backup/restore, Borg engine, local repos, basic UI        |
| Expansion  | v0.4–v0.7 | Done   | Real-time WebSocket UI, database dumpers, retention policies, profiles, setup wizard |
| Polish     | v0.8      | Now    | Timezone-aware scheduling, source health checks, kubectl connectivity, default profiles, system settings |
| Release    | v0.9–v1.0 | Next   | Multi-user RBAC, audit log, notifications, restic engine, metrics dashboard, mobile views |

---

## 2. Project Structure

```
cellar/
├── AI.md                 # This file
├── AI.local.md           # Local/private context (gitignored)
├── CLAUDE.md             # Points to AI.md (gitignored)
├── README.md
├── .github/workflows/ci.yml  # CI pipeline
├── Makefile
├── docker-compose.yml
│
├── backend/              # Laravel 12 project
│   ├── app/
│   │   ├── Console/Commands/   # 3 Artisan commands
│   │   ├── Enums/              # 8 PHP backed enums
│   │   ├── Events/             # JobUpdated broadcast event
│   │   ├── Http/Controllers/Api/V1/  # 13 controllers
│   │   ├── Jobs/               # 4 queue jobs
│   │   ├── Models/             # 12 Eloquent models
│   │   ├── Observers/          # JobObserver
│   │   ├── Providers/          # AppServiceProvider
│   │   └── Services/
│   │       ├── DatabaseDumper.php
│   │       ├── DatabaseInspector.php
│   │       ├── DatabaseRestorer.php
│   │       ├── JobLogger.php
│   │       ├── KubernetesDiscovery.php
│   │       └── Engines/
│   │           ├── BackupEngine.php  (interface)
│   │           └── BorgEngine.php    (implementation)
│   ├── config/cellar.php       # Cellar-specific config
│   ├── database/migrations/    # 18 migration files
│   └── routes/
│       ├── api.php             # API routes (v1, Sanctum auth)
│       ├── channels.php        # WebSocket channel auth
│       └── console.php         # Scheduler + cron helpers
│
├── frontend/             # Vue 3 SPA
│   ├── src/
│   │   ├── components/
│   │   │   ├── layout/AppSidebar.vue  # Global sidebar + WebSocket subscription
│   │   │   ├── ConfirmModal.vue
│   │   │   └── JobLogModal.vue
│   │   ├── composables/useConfirm.ts
│   │   ├── lib/
│   │   │   ├── api.ts          # Axios instance with auth interceptors
│   │   │   ├── echo.ts         # Laravel Echo (Reverb) client
│   │   │   └── utils.ts
│   │   ├── router/index.ts     # Vue Router with auth guards
│   │   ├── stores/             # 5 Pinia stores
│   │   │   ├── auth.ts
│   │   │   ├── plans.ts
│   │   │   ├── radar.ts
│   │   │   ├── settings.ts
│   │   │   └── sources.ts
│   │   └── views/              # 10 view components
│   │       ├── SetupView.vue        # First-time setup wizard
│   │       ├── DashboardView.vue
│   │       ├── SourcesView.vue
│   │       ├── PlansView.vue
│   │       ├── ArchivesView.vue
│   │       ├── JobsView.vue
│   │       ├── RadarView.vue
│   │       ├── SettingsView.vue     # System + Retention + Schedule tabs
│   │       ├── LoginView.vue
│   │       └── LogsView.vue
│   └── vite.config.ts
│
└── docker/
    ├── unified/
    │   ├── Dockerfile          # Multi-stage: Node build + PHP runtime
    │   ├── entrypoint.sh       # Migrations + seed on boot
    │   ├── nginx.conf          # SPA + API proxy + WebSocket proxy
    │   └── supervisord.conf    # All 5 processes
    ├── kubernetes/cellar.yaml  # Sample K8s manifest
    └── docker-compose.postgres.yml  # PostgreSQL overlay
```

---

## 3. CI Pipeline

**File:** `.github/workflows/ci.yml`

Triggers: push to `main`, push tags `v*`, PRs to `main`.

| Job      | Runs                                              | When                      |
| -------- | ------------------------------------------------- | ------------------------- |
| Backend  | PHP 8.4, Composer, `php artisan test` (SQLite)    | Always                    |
| Frontend | Node 20, `vue-tsc --noEmit`, `vite build`         | Always                    |
| Docker   | Build & push to `ghcr.io/cellar-backup/cellar`    | Push to main or version tag |

Docker tags: `latest` (on main), `0.9.0` (on `v0.9.0` tag), `sha-xxxxx`, branch name.

---

## 4. Key Patterns

### WebSocket (Real-time updates)

- **Server:** Laravel Reverb (internal port 8080, proxied via nginx at `/app/{id}`)
- **Client:** Laravel Echo + Pusher.js in `frontend/src/lib/echo.ts`
- **Channel:** `jobs` (public) — broadcasts `JobUpdated` events on job create/update
- **Subscription:** Single global listener in `AppSidebar.vue` — do NOT subscribe per-view (causes channel teardown on navigation)
- **Store handler:** `plansStore.handleJobEvent(event)` uses `patchArray()` for reactive updates

### Pinia Stores

- `patchArray()` utility for in-place reactive array updates (match by `id`, update existing or push new)
- Sorted views use `computed()` (e.g., `sortedSources` sorts by `display_label`)

### Database & Auth

- All models use UUID primary keys (`HasUuids` trait)
- Encrypted-at-rest: repo configs, source passwords, kubeconfig files (`encrypted` / `encrypted:array` casts)
- Sanctum Bearer tokens stored in `localStorage("cellar_access_token")`

### Scheduler (routes/console.php)

- Timezone-aware: `cellarTimezone()` reads from `AppSetting('timezone')` with try/catch (safe during Docker build)
- `cronMatchesNow()` and `nextCronRun()` pass timezone to `CronExpression`
- Prune runs 30 minutes after each backup cron via `shiftCronMinutes()`
- Daily tasks: `sync-archives` at 03:00, `cleanup-job-logs` at 04:00
- Health checks: `check-source-health` every 15 minutes

### Profiles & Settings

- `Profile` model: reusable retention and schedule presets with `is_default` flag
- `AppSetting` model: key-value store for system settings (timezone, max_parallel_jobs, app_url)
- `AppServiceProvider::boot()` overrides Horizon `maxProcesses` from `AppSetting` at runtime
- Default profiles seeded by `cellar:seed-defaults`: "Standard" retention + "Daily at 02:00" schedule

### Source Health

- `Source::checkConnection()` routes through kubectl exec when `dump_method: 'kubectl'`
- `cellar:check-source-health` command runs every 15 minutes, updates `health_status` and `last_health_check`

---

## 5. API Routes — `routes/api.php`

All routes prefixed with `/api/v1`. Auth uses Laravel Sanctum tokens.

### Public

| Method | Path            | Controller/Action         |
| ------ | --------------- | ------------------------- |
| POST   | `auth/login`    | `AuthController@login`    |
| POST   | `setup`         | `SetupController@store`   |
| GET    | `system/health` | `SystemController@health` |

### Authenticated (Sanctum)

| Method | Path                                          | Controller/Action                     |
| ------ | --------------------------------------------- | ------------------------------------- |
| POST   | `auth/logout`                                 | `AuthController@logout`               |
| GET    | `auth/me`                                     | `AuthController@me`                   |
| CRUD   | `repositories`                                | `RepositoryController` (apiResource)  |
| POST   | `repositories/{id}/test`                      | `RepositoryController@test`           |
| POST   | `repositories/{id}/import`                    | `RepositoryController@import`         |
| CRUD   | `sources`                                     | `SourceController` (apiResource)      |
| POST   | `sources/quick-add`                           | `SourceController@quickAdd`           |
| POST   | `sources/{id}/test-connection`                | `SourceController@testConnection`     |
| PATCH  | `sources/{id}/toggle`                         | `SourceController@toggle`             |
| PATCH  | `sources/{id}/retention`                      | `SourceController@updateRetention`    |
| PATCH  | `sources/{id}/dump-method`                    | `SourceController@updateDumpMethod`   |
| GET    | `sources/{id}/policies`                       | `SourceController@policies`           |
| GET    | `sources/{id}/archives`                       | `SourceController@archives`           |
| CRUD   | `plans`                                       | `BackupPlanController` (apiResource)  |
| POST   | `plans/{id}/backup`                           | `BackupPlanController@backup`         |
| POST   | `plans/{id}/restore`                          | `BackupPlanController@restore`        |
| POST   | `plans/{id}/prune`                            | `BackupPlanController@prune`          |
| POST   | `plans/{id}/verify`                           | `BackupPlanController@verify`         |
| PATCH  | `plans/{id}/toggle`                           | `BackupPlanController@toggle`         |
| GET    | `jobs`                                        | `JobController@index`                 |
| GET    | `jobs/{id}`                                   | `JobController@show`                  |
| GET    | `jobs/{id}/log`                               | `JobController@log`                   |
| POST   | `jobs/{id}/cancel`                            | `JobController@cancel`                |
| GET    | `archives`                                    | `ArchiveController@index`             |
| GET    | `archives/{id}`                               | `ArchiveController@show`              |
| PATCH  | `archives/{id}`                               | `ArchiveController@update`            |
| DELETE | `archives/{id}`                               | `ArchiveController@destroy`           |
| PATCH  | `archives/{id}/keep-forever`                  | `ArchiveController@keepForever`       |
| POST   | `archives/{id}/restore`                       | `ArchiveController@restore`           |
| GET    | `archives/{id}/download`                      | `ArchiveController@download`          |
| CRUD   | `notifications`                               | `NotificationChannelController`       |
| CRUD   | `documents`                                   | `DocumentController`                  |
| CRUD   | `profiles`                                    | `ProfileController`                   |
| GET    | `settings`                                    | `SettingsController@index`            |
| PUT    | `settings`                                    | `SettingsController@update`           |
| GET    | `kubernetes/clusters`                         | `KubernetesController@clusters`       |
| POST   | `kubernetes/clusters`                         | `KubernetesController@storeCluster`   |
| PUT    | `kubernetes/clusters/{id}`                    | `KubernetesController@updateCluster`  |
| DELETE | `kubernetes/clusters/{id}`                    | `KubernetesController@destroyCluster` |
| POST   | `kubernetes/clusters/{id}/test`               | `KubernetesController@test`           |
| POST   | `kubernetes/clusters/{id}/discover`           | `KubernetesController@discover`       |
| GET    | `kubernetes/clusters/{id}/namespaces`         | `KubernetesController@namespaces`     |
| POST   | `kubernetes/clusters/{id}/import`             | `KubernetesController@import`         |
| POST   | `kubernetes/clusters/{id}/ignore`             | `KubernetesController@ignore`         |
| GET    | `kubernetes/clusters/{id}/ignored`            | `KubernetesController@ignored`        |
| DELETE | `kubernetes/clusters/{id}/ignored/{ignoreId}` | `KubernetesController@unignore`       |
| POST   | `kubernetes/clusters/{id}/list-databases`     | `KubernetesController@listDatabases`  |

---

## 6. Enums

| Enum          | Values                                                                                     |
| ------------- | ------------------------------------------------------------------------------------------ |
| `BackendType` | local, s3, b2, r2, gcs, azure, sftp, smb, nfs, rclone                                     |
| `ChannelType` | email, slack, discord, telegram, gotify, ntfy, apprise, webhook                             |
| `EngineType`  | borg, restic                                                                               |
| `JobStatus`   | pending, running, success, failed, cancelled                                                |
| `JobType`     | backup, restore, export, prune, verify                                                     |
| `PlanStatus`  | healthy, warning, failed, running, idle                                                    |
| `RepoStatus`  | online, offline, degraded, unknown                                                         |
| `SourceType`  | postgresql, mysql, mariadb, mongodb, sqlite, redis, directory, docker_volume. Methods: `isDatabase()`, `defaultPort()` |

---

## 7. Models (12)

All models use UUID primary keys (`HasUuids` trait).

| Model               | Table                  | Key Relations & Notes                                                           |
| -------------------- | ---------------------- | ------------------------------------------------------------------------------- |
| `User`               | users                  | Sanctum tokens, hashed password cast                                            |
| `Repository`         | repositories           | `config` encrypted:array, hasMany BackupPlan                                    |
| `Source`             | sources                | `password` encrypted, auto-default retention from Profile on save, hasMany BackupPlan |
| `BackupPlan`         | backup_plans           | belongsTo Source + Repository, hasMany Job + Archive                            |
| `Job`                | backup_jobs            | belongsTo BackupPlan, `progress` (0-100), broadcast via JobObserver             |
| `Archive`            | archives               | belongsTo BackupPlan, tags/notes, keep_forever pin                              |
| `Profile`            | profiles               | type: retention/schedule, `is_default` flag, scopes: `retention()`, `schedule()` |
| `AppSetting`         | app_settings           | key-value store, static `get()`/`set()` helpers                                 |
| `NotificationChannel`| notification_channels  | belongsTo BackupPlan, `config` encrypted:array                                 |
| `CustomDocument`     | custom_documents       | User-defined backup/restore commands                                            |
| `RadarCluster`       | radar_clusters         | `kubeconfig` encrypted, hasMany RadarIgnore                                     |
| `RadarIgnore`        | radar_ignores          | belongsTo RadarCluster, `resource_key` unique                                   |

---

## 8. Queue Jobs

All queued via Redis/Horizon. Create a `Job` record, update plan status, broadcast progress via `JobUpdated` event.

| Job          | Timeout | Flow                                                                                   |
| ------------ | ------- | -------------------------------------------------------------------------------------- |
| `RunBackup`  | 8h      | Init borg repo → estimate DB size → dump with progress polling → `borg create` → Archive record |
| `RunPrune`   | 1h      | `borg prune` with retention policy → reconcile Archive records                          |
| `RunRestore` | 2h      | `borg extract` → find dump file → restore via DatabaseRestorer                          |
| `RunVerify`  | 1h      | `borg check` on repo or specific archive                                                |

---

## 9. Services

| Service              | Purpose                                                                                    |
| -------------------- | ------------------------------------------------------------------------------------------ |
| `DatabaseDumper`     | pg_dump / mysqldump with progress tracking (non-blocking `Process::start()` + size polling) |
| `DatabaseRestorer`   | pg_restore / psql / mysql CLI, auto-detects dump format                                     |
| `DatabaseInspector`  | Lists databases via direct PDO or kubectl exec fallback                                     |
| `BorgEngine`         | Wraps borg CLI (`--json` output), implements `BackupEngine` interface                       |
| `KubernetesDiscovery`| Auto-discovers DBs and PVCs in K8s clusters, deduplication, credential discovery            |
| `JobLogger`          | Per-job log files in `/var/log/cellar/jobs/`, with cleanup (30-day retention)                |

---

## 10. Docker (Unified)

**Image:** `ghcr.io/cellar-backup/cellar:latest`

Multi-stage build: Node 20 (frontend) → PHP 8.4 (runtime with borg, restic, kubectl, DB clients, nginx, supervisor).

**Volumes:** `/app/data` (SQLite + app key), `/data/repositories` (borg repos).

**Entrypoint:** creates dirs → persists APP_KEY → creates SQLite → runs migrations → seeds defaults → starts supervisord.

**Required env:** `REDIS_HOST` (everything else has sensible defaults).

---

## 11. Key Design Decisions

1. **Single container** — supervisord manages nginx, PHP, Horizon, Scheduler, Reverb. Simplifies HomeLab deployment.
2. **SQLite default** — Zero-config. PostgreSQL available as overlay.
3. **UUID primary keys** — All domain models for portability.
4. **Encrypted-at-rest** — Repo configs, passwords, kubeconfigs via Laravel `encrypted` casts.
5. **Borg-first** — `BackupEngine` interface for future restic support, but MVP is BorgBackup only.
6. **Timezone-aware scheduling** — All cron evaluation uses configured timezone from AppSetting.
7. **Single WebSocket subscription** — AppSidebar owns the Echo channel; views must NOT subscribe independently.
8. **Profile-driven defaults** — New sources inherit retention/schedule from default Profile, not hardcoded values.
9. **kubectl exec for connectivity** — Sources with `dump_method: 'kubectl'` test connectivity via pod exec, matching the backup path.
10. **Runtime config override** — `AppServiceProvider::boot()` reads DB settings and overrides Horizon config without restart.

---

## 12. Database Migrations

**Convention:** Filenames use actual creation date: `YYYY_MM_DD_NNNNNN_description.php`. First 4 are Laravel framework defaults (`0001_01_01_*`).

18 migration files covering: users, cache, queue, Sanctum tokens, repositories, sources, backup_plans, backup_jobs, archives, notification_channels, custom_documents, radar_ignores, radar_clusters, job progress, archive tags/notes, source retention, profiles + app_settings, source health fields.
