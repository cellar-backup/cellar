# Cellar — AI Project Reference

> **Generated:** 2025-03-04
> **Purpose:** Comprehensive project context for AI-assisted development.

---

## 1. Overview

**Cellar** is an open-source, container-native backup management platform for HomeLab operators. It provides deduplication-first backups (via BorgBackup/restic), database + filesystem support, custom backup documents, and a modern Vue 3 UI.

- **Repo:** `github.com/cellar-backup/cellar`
- **License:** Apache 2.0
- **Default port:** 8420
- **Default credentials:** `admin` / `changeme` (seeded via `cellar:seed-defaults`)

### Architecture (6 containers)

| Container          | Role             | Stack                          |
| ------------------ | ---------------- | ------------------------------ |
| `cellar-api`       | REST API         | PHP 8.4 + Laravel 12 + Sanctum |
| `cellar-worker`    | Async job runner | Laravel Horizon + Redis        |
| `cellar-scheduler` | Cron scheduler   | Laravel Scheduler              |
| `cellar-ui`        | Frontend SPA     | Vue 3 + Vite + Tailwind CSS 4  |
| `cellar-redis`     | Queue & cache    | Redis 7                        |
| `cellar-proxy`     | Reverse proxy    | Caddy 2                        |

**Database:** SQLite by default (zero-config). Optional PostgreSQL override via `docker/docker-compose.postgres.yml`.

### Roadmap

| Phase      | Version   | Focus                                                          |
| ---------- | --------- | -------------------------------------------------------------- |
| Foundation | v0.1–v0.3 | Core backup/restore, Borg engine, local + S3, basic UI         |
| Expansion  | v0.4–v0.7 | Custom documents, restore wizard, multi-backend, notifications |
| Polish     | v0.8–v1.0 | Multi-user RBAC, audit log, metrics, mobile views, v1.0 launch |

---

## 2. Project Structure

```
cellar/
├── AI.md                 # This file
├── README.md
├── CONTRIBUTING.md
├── CODE_OF_CONDUCT.md
├── LICENSE
├── Makefile
├── docker-compose.yml
├── logo.svg
├── .env                  # Root env (CELLAR_PORT, debug flags)
│
├── backend/              # Laravel 12 project
│   ├── .env              # Local dev env (APP_KEY, DB, Redis, etc.)
│   ├── .env.docker       # Docker runtime env
│   ├── .env.example      # Template
│   ├── composer.json
│   ├── artisan
│   ├── phpunit.xml
│   ├── app/
│   │   ├── Console/Commands/
│   │   ├── Enums/        # 8 PHP backed enums
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   └── Api/V1/  # 10 controllers
│   │   │   └── Requests/
│   │   ├── Jobs/         # 4 queue jobs
│   │   ├── Models/       # 10 Eloquent models
│   │   ├── Observers/
│   │   ├── Providers/
│   │   └── Services/
│   │       ├── DatabaseDumper.php
│   │       ├── DatabaseRestorer.php
│   │       ├── KubernetesDiscovery.php
│   │       └── Engines/
│   │           ├── BackupEngine.php  (interface + DTOs)
│   │           └── BorgEngine.php    (implementation)
│   ├── config/
│   │   └── cellar.php    # Cellar-specific config
│   ├── database/migrations/  # 13 migration files
│   └── routes/
│       ├── api.php       # API routes (v1, Sanctum auth)
│       └── console.php   # Scheduler definitions
│
├── frontend/             # Vue 3 SPA
│   ├── package.json
│   ├── tsconfig.json
│   ├── vite.config.ts
│   ├── src/
│   │   ├── App.vue
│   │   ├── main.ts
│   │   ├── lib/api.ts        # Axios instance with auth interceptors
│   │   ├── router/index.ts   # Vue Router with auth guards
│   │   ├── stores/           # 4 Pinia stores
│   │   │   ├── auth.ts
│   │   │   ├── plans.ts
│   │   │   ├── radar.ts
│   │   │   └── sources.ts
│   │   ├── views/            # 9 view components
│   │   │   ├── ArchivesView.vue
│   │   │   ├── DashboardView.vue
│   │   │   ├── JobsView.vue
│   │   │   ├── LoginView.vue
│   │   │   ├── LogsView.vue
│   │   │   ├── PlansView.vue
│   │   │   ├── RadarView.vue
│   │   │   ├── SettingsView.vue
│   │   │   └── SourcesView.vue
│   │   ├── components/
│   │   └── assets/
│   └── index.html
│
└── docker/
    ├── Dockerfile.api            # PHP 8.4-cli + borg + restic + kubectl + DB clients
    ├── Dockerfile.ui             # Node 20 build → nginx
    ├── Caddyfile                 # Reverse proxy on :8420
    ├── nginx.conf                # SPA fallback + API proxy
    ├── entrypoint.sh             # Migrations + seed on boot
    └── docker-compose.postgres.yml  # PostgreSQL override
```

---

## 3. Docker Compose (default)

```yaml
services:
  cellar-redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: redis-server --save 60 1 --loglevel warning
    volumes:
      - cellar-redis-data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

  cellar-api:
    build:
      context: .
      dockerfile: docker/Dockerfile.api
    restart: unless-stopped
    depends_on:
      cellar-redis:
        condition: service_healthy
    environment:
      APP_ENV: ${APP_ENV:-production}
      APP_DEBUG: ${CELLAR_DEBUG:-false}
      APP_URL: ${APP_URL:-http://localhost:8420}
      DB_DATABASE: /app/data/cellar.sqlite
      REDIS_HOST: cellar-redis
      QUEUE_CONNECTION: redis
      CACHE_STORE: redis
      SANCTUM_STATEFUL_DOMAINS: ${SANCTUM_STATEFUL_DOMAINS:-localhost:8420,localhost}
    volumes:
      - cellar-data:/app/data
      - cellar-repos:/data/repositories
      - cellar-logs:/var/log/cellar

  cellar-worker:
    build:
      context: .
      dockerfile: docker/Dockerfile.api
    restart: unless-stopped
    command: php artisan horizon
    depends_on:
      cellar-api:
        condition: service_started
    environment:
      APP_ENV: ${APP_ENV:-production}
      APP_DEBUG: ${CELLAR_DEBUG:-false}
      DB_DATABASE: /app/data/cellar.sqlite
      REDIS_HOST: cellar-redis
      QUEUE_CONNECTION: redis
      CACHE_STORE: redis
    volumes:
      - cellar-data:/app/data
      - cellar-repos:/data/repositories
      - cellar-logs:/var/log/cellar

  cellar-scheduler:
    build:
      context: .
      dockerfile: docker/Dockerfile.api
    restart: unless-stopped
    command: php artisan schedule:work
    depends_on:
      cellar-api:
        condition: service_started
    environment:
      APP_ENV: ${APP_ENV:-production}
      APP_DEBUG: ${CELLAR_DEBUG:-false}
      DB_DATABASE: /app/data/cellar.sqlite
      REDIS_HOST: cellar-redis
      QUEUE_CONNECTION: redis
      CACHE_STORE: redis
    volumes:
      - cellar-data:/app/data

  cellar-ui:
    build:
      context: .
      dockerfile: docker/Dockerfile.ui
    restart: unless-stopped
    depends_on:
      - cellar-api

  cellar-proxy:
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - "${CELLAR_PORT:-8420}:8420"
    volumes:
      - ./docker/Caddyfile:/etc/caddy/Caddyfile:ro
      - cellar-caddy-data:/data
    depends_on:
      - cellar-ui
      - cellar-api

volumes:
  cellar-data:
  cellar-repos:
  cellar-redis-data:
  cellar-logs:
  cellar-caddy-data:
```

---

## 4. Environment Files

### Root `.env`

```dotenv
CELLAR_SECRET_KEY=dev-secret-key-not-for-production
CELLAR_DEBUG=true
CELLAR_LOG_LEVEL=DEBUG
CELLAR_ALLOWED_HOSTS=*
CELLAR_TIMEZONE=UTC
CELLAR_PORT=8420
CELLAR_MAX_PARALLEL_JOBS=2
```

### `backend/.env.docker` (Docker runtime)

```dotenv
APP_NAME=Cellar
APP_ENV=production
APP_KEY=                     # Auto-generated and persisted by entrypoint.sh
APP_DEBUG=false
APP_URL=http://localhost:8420
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info
DB_CONNECTION=sqlite
DB_DATABASE=/app/data/cellar.sqlite
SESSION_DRIVER=cookie
SESSION_LIFETIME=120
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=phpredis
REDIS_HOST=cellar-redis
REDIS_PORT=6379
REDIS_PASSWORD=null
SANCTUM_STATEFUL_DOMAINS=localhost:8420,localhost

# Cellar-specific
CELLAR_BORG_PATH=/usr/bin/borg
CELLAR_RESTIC_PATH=/usr/bin/restic
CELLAR_MAX_PARALLEL_JOBS=2
CELLAR_LOG_DIR=/var/log/cellar
CELLAR_ADMIN_NAME=admin
CELLAR_ADMIN_EMAIL=admin@cellar.local
CELLAR_ADMIN_PASSWORD=admin          # ⚠️  SENSITIVE — default seed password
```

### `backend/.env` (Local development)

Standard Laravel `.env` with SQLite, `APP_KEY` set, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`. No Cellar-specific values (uses config defaults).

---

## 5. Backend Configuration — `config/cellar.php`

```php
return [
    'version' => env('CELLAR_VERSION', '0.1.0'),
    'borg_path' => env('CELLAR_BORG_PATH', '/usr/bin/borg'),
    'restic_path' => env('CELLAR_RESTIC_PATH', '/usr/bin/restic'),
    'kubectl_path' => env('CELLAR_KUBECTL_PATH', '/usr/local/bin/kubectl'),
    'max_parallel_jobs' => (int) env('CELLAR_MAX_PARALLEL_JOBS', 2),
    'log_dir' => env('CELLAR_LOG_DIR', '/var/log/cellar'),
    'admin_name' => env('CELLAR_ADMIN_NAME', 'admin'),
    'admin_email' => env('CELLAR_ADMIN_EMAIL', 'admin@cellar.local'),
    'admin_password' => env('CELLAR_ADMIN_PASSWORD', 'admin'),
];
```

---

## 6. Backend Dependencies — `composer.json`

```
require:
  php: ^8.2
  laravel/framework: ^12.0
  laravel/horizon: ^5.45
  laravel/sanctum: ^4.3
  laravel/tinker: ^2.10.1

require-dev:
  fakerphp/faker, laravel/pail, laravel/pint, laravel/sail,
  mockery/mockery, nunomaduro/collision, phpunit/phpunit
```

Key scripts:

- `composer setup` — install, env, key:generate, migrate, npm install+build
- `composer dev` — concurrently runs `artisan serve`, `queue:listen`, `pail`, `npm run dev`
- `composer test` — `config:clear` + `artisan test`

---

## 7. API Routes — `routes/api.php`

All routes prefixed with `/api/v1`. Auth uses Laravel Sanctum tokens.

### Public

| Method | Path            | Controller/Action         |
| ------ | --------------- | ------------------------- |
| POST   | `auth/login`    | `AuthController@login`    |
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
| CRUD   | `plans`                                       | `BackupPlanController` (apiResource)  |
| POST   | `plans/{id}/backup`                           | `BackupPlanController@backup`         |
| POST   | `plans/{id}/restore`                          | `BackupPlanController@restore`        |
| POST   | `plans/{id}/prune`                            | `BackupPlanController@prune`          |
| POST   | `plans/{id}/verify`                           | `BackupPlanController@verify`         |
| GET    | `jobs`                                        | `JobController@index`                 |
| GET    | `jobs/{id}`                                   | `JobController@show`                  |
| GET    | `archives`                                    | `ArchiveController@index`             |
| GET    | `archives/{id}`                               | `ArchiveController@show`              |
| DELETE | `archives/{id}`                               | `ArchiveController@destroy`           |
| POST   | `archives/{id}/restore`                       | `ArchiveController@restore`           |
| GET    | `archives/{id}/download`                      | `ArchiveController@download`          |
| CRUD   | `notifications`                               | `NotificationChannelController`       |
| CRUD   | `documents`                                   | `DocumentController`                  |
| POST   | `documents/{id}/test`                         | `DocumentController@test`             |
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

---

## 8. Scheduler — `routes/console.php`

- **Every minute:** Evaluates all `schedule_enabled` plans with `schedule_cron`, dispatches `RunBackup` for matching cron expressions.
- **Every minute (offset +30min):** Dispatches `RunPrune` for plans with retention policies.
- **Daily at 03:00:** Runs `cellar:sync-archives` command to reconcile DB with borg repos.

Uses `CronExpression` library for dynamic per-plan cron matching. Helper functions: `cronMatchesNow()`, `nextCronRun()`, `shiftCronMinutes()`.

---

## 9. Enums

### `BackendType` — Repository storage backends

`local`, `s3`, `b2`, `r2`, `gcs`, `azure`, `sftp`, `smb`, `nfs`, `rclone`

### `ChannelType` — Notification channels

`email`, `slack`, `discord`, `telegram`, `gotify`, `ntfy`, `apprise`, `webhook`

### `EngineType` — Backup engines

`borg`, `restic`

### `JobStatus` — Job lifecycle states

`pending`, `running`, `success`, `failed`, `cancelled`

### `JobType` — Job operation types

`backup`, `restore`, `export`, `prune`, `verify`

### `PlanStatus` — Backup plan health

`healthy`, `warning`, `failed`, `running`, `idle`

### `RepoStatus` — Repository connectivity

`online`, `offline`, `degraded`, `unknown`

### `SourceType` — Data source types

`postgresql`, `mysql`, `mariadb`, `mongodb`, `sqlite`, `redis`, `directory`, `docker_volume`

Methods:

- `isDatabase()` — returns `true` for postgresql, mysql, mariadb, mongodb, sqlite, redis
- `defaultPort()` — returns standard port for DB types (5432, 3306, 27017, 6379)

---

## 10. Models

All models use UUID primary keys (`HasUuids` trait).

### `User`

- Fields: `name`, `username`, `email`, `password`
- Traits: `HasApiTokens`, `HasFactory`, `Notifiable`
- Casts: `email_verified_at` → datetime, `password` → hashed

### `Repository`

- Fields: `name`, `description`, `backend_type`, `status`, `is_default`, `config`, `capacity_bytes`, `used_bytes`, `last_checked`
- Casts: `backend_type` → `BackendType`, `status` → `RepoStatus`, `config` → `encrypted:array`
- Relations: `hasMany(BackupPlan)`
- Accessor: `plan_count`

### `Source`

- Fields: `name`, `source_type`, `host`, `port`, `username`, `password`, `database_name`, `path`, `enabled`, `notes`, `extra_config`
- Casts: `source_type` → `SourceType`, `password` → `encrypted`, `extra_config` → `array`
- Auto-fill: default port on save, auto-generated name
- Relations: `hasMany(BackupPlan)`
- Accessors: `is_database`, `display_label`

### `BackupPlan`

- Fields: `name`, `source_id`, `repository_id`, `engine`, `status`, `schedule_cron`, `schedule_enabled`, `next_run`, `last_run`, `retention_policy`, `compression`, `encryption`, `pre_hook`, `post_hook`
- Casts: `engine` → `EngineType`, `status` → `PlanStatus`, `retention_policy` → `array`
- Default retention: `{ keep_daily: 7, keep_weekly: 4, keep_monthly: 6 }`
- Relations: `belongsTo(Source)`, `belongsTo(Repository)`, `hasMany(Job)`, `hasMany(Archive)`, `hasMany(NotificationChannel)`
- Accessors: `source_name`, `source_type`, `repository_name`

### `Job`

- Table: `backup_jobs`
- Fields: `plan_id`, `job_type`, `status`, `started_at`, `finished_at`, `log_path`, `error_message`, `metadata`, `created_at`
- Casts: `job_type` → `JobType`, `status` → `JobStatus`, `metadata` → `array`
- Relations: `belongsTo(BackupPlan)`

### `Archive`

- Fields: `plan_id`, `archive_id`, `timestamp`, `size_original`, `size_dedup`, `size_compressed`, `duration`, `file_count`, `metadata`, `created_at`
- Casts: all sizes → integer, `metadata` → `array`
- Relations: `belongsTo(BackupPlan)`

### `NotificationChannel`

- Fields: `name`, `channel_type`, `config`, `events_filter`, `enabled`, `backup_plan_id`
- Casts: `channel_type` → `ChannelType`, `config` → `encrypted:array`, `events_filter` → `array`
- Relations: `belongsTo(BackupPlan)`

### `CustomDocument`

- Fields: `name`, `version`, `description`, `backup_command`, `restore_command`, `health_check`, `env_vars`, `stream_to_engine`
- Casts: `env_vars` → `array`, `stream_to_engine` → `boolean`

### `RadarIgnore`

- Table: `radar_ignores`
- Fields: `resource_key` (unique), `namespace`, `name`, `kind`, `source_type`, `reason`
- Used by Kubernetes Radar to persist ignored discovered resources so they don't reappear on subsequent scans

---

## 11. Queue Jobs

All jobs are queued (Redis via Horizon). They create a `Job` record, update plan status, and handle cleanup on failure.

### `RunBackup`

- **Timeout:** 28800s (8h), **Tries:** 2
- Flow: Load plan → create job record → init borg repo if needed → estimate DB size → dump database (if DB source) to temp dir with real-time progress polling → run `borg create` → create `Archive` record → update job status
- **Progress tracking:** For database sources, queries `pg_database_size()` / `information_schema` to estimate DB size, then runs the dump non-blocking (`Process::start()`) and polls output file/directory size every 3 seconds. Maps dump progress (0–100%) to job progress 12–55%. Borg phase is 60–90% (staged). Filesystem sources keep staged jumps.
- Cleans up temp dump directory in `finally` block

### `RunPrune`

- **Timeout:** 3600s, **Tries:** 1
- Runs `borg prune` with plan's retention policy. Supports `dryRun` mode.
- Reconciles DB archives after pruning (deletes Archive records for pruned archives)

### `RunRestore`

- **Timeout:** 7200s, **Tries:** 1
- Extracts borg archive to temp dir → finds dump file → restores to source DB via `DatabaseRestorer`
- Searches for files with extensions: `.sql.gz`, `.dump`, `.sql`, `.pg_dump`

### `RunVerify`

- **Timeout:** 3600s, **Tries:** 1
- Runs `borg check` on repo or specific archive. Records pass/fail in job metadata.

---

## 12. Services

### `DatabaseDumper`

Dispatches to `pg_dump` (PostgreSQL, directory format `-Fd` for direct, custom format `-Fc` for kubectl) or `mysqldump` (MySQL/MariaDB). Returns `DumpResult` DTO with success, path, size, message.

**Progress tracking:** Accepts optional `Closure $onProgress` callback. When provided, estimates DB size (`pg_database_size()` for PostgreSQL, `information_schema` for MySQL), runs the dump non-blocking via `Process::start()`, and polls output file/directory size every 3 seconds. Reports progress as `bytes_written / estimated_size` (capped at 95%). Estimation factors: direct pg_dump uses `raw_size × 0.7`; kubectl pg_dump uses `raw_size / 5` (compressed format). Falls back to blocking `Process::run()` when no callback or estimation fails.

### `DatabaseRestorer`

Restores dumps via `pg_restore` (custom format) or `psql` (plain SQL) for PostgreSQL, and `mysql` CLI for MySQL/MariaDB. Auto-detects dump format by reading first 5 bytes (`PGDMP` = custom format).

### `BackupEngine` (Interface)

Defines contract: `initialize()`, `backup()`, `restore()`, `listArchives()`, `getArchiveInfo()`, `getRepoInfo()`, `prune()`, `verify()`, `deleteArchive()`.

DTOs: `BackupResult`, `RestoreResult`, `PruneResult`, `ArchiveInfo`, `RepoInfo`.

### `BorgEngine` (Implementation)

Wraps borg CLI commands via Laravel `Process` facade. Parses JSON output (`--json` flag). Handles environment variables (`BORG_PASSPHRASE`, relocated repo access). Exit code 0-1 = OK, 2+ = error.

Key methods:

- `initialize(repoPath, encryption='none')` — `borg init`
- `backup(repoPath, sourcePaths, archiveName, excludePatterns, compression='lz4')` — `borg create --stats --json`
- `restore(repoPath, archiveId, targetPath)` — `borg extract` (sets CWD to targetPath)
- `listArchives(repoPath)` — `borg list --json`
- `prune(repoPath, keepPolicy, dryRun)` — `borg prune --stats --list`
- `verify(repoPath, archiveId?)` — `borg check`
- `deleteArchive(repoPath, archiveId)` — `borg delete`

### `KubernetesDiscovery`

Auto-discovers databases and backup-eligible PVCs in a Kubernetes cluster. Works in two modes:

1. **In-cluster** — uses default ServiceAccount (no kubeconfig needed)
2. **Out-of-cluster** — uses a kubeconfig file and/or explicit context

Wraps `kubectl` via Laravel `Process` facade with JSON output parsing.

Key features:

- **Image detection** — maps container images to source types (e.g., `postgres:16` → `postgresql`, `redis:7` → `redis`)
- **Port detection** — known DB ports (5432, 3306, 27017, 6379) trigger source type classification
- **Pod scanning** — inspects all pods for database containers
- **Service scanning** — inspects service ports for database endpoints; uses `app.kubernetes.io/name` or `app` labels (and selector) to resolve the canonical app name so that multiple services (ClusterIP, LoadBalancer, etc.) for the same database group together
- **PVC scanning** — lists bound PersistentVolumeClaims as potential directory backup targets
- **External access detection** — for services, detects NodePort, LoadBalancer (ingress IP/hostname), ExternalName, and `spec.externalIPs`. Returns `external_host`, `external_port`, `node_port`, `service_type` alongside internal host/port so the UI can suggest reachable endpoints.
- **Secret credential discovery** — scans Opaque secrets for known credential keys (password, username, database variants for MariaDB/MySQL/PostgreSQL/MongoDB/Redis). Matches secrets to discovered resources via `app.kubernetes.io/name` label, with **name-based fallback**: if a secret name starts with a discovered app name (e.g., `mariadb-credentials` → `mariadb`), it is associated even without labels. `discoverSecrets()` accepts `$appNamesByNs` (keyed by namespace) for this heuristic. Returns `credentials[]` array per resource with `{secret_name, key, value}`.
- **Deduplication & grouping** — groups Pod + Service entries for the same database (`namespace:canonicalName:source_type` key) into a single resource with an `endpoints[]` array. Each endpoint carries `resource_name` (actual K8s resource name). Endpoints sorted neutrally: Service → Pod → PVC (no sub-ordering within services — user picks). Top-level fields prefer Service.
- Returns discovered resources with host, port, namespace, kind, image, external access fields, `endpoints[]` array, and `credentials[]` array
- Factory method: `fromCluster(RadarCluster)` — creates instance from a saved cluster, writing kubeconfig content to a temp file (cleaned up in `__destruct`)

### `DatabaseInspector`

Lists available databases on discovered endpoints using a **dual strategy**. Used by Radar's import review to let users pick which databases to back up.

**Strategy 1 — Direct PDO** (tried first when host is externally reachable, skipped for `.svc.cluster.local` hosts):

- **PostgreSQL** — PDO `pgsql`, queries `pg_database`
- **MySQL / MariaDB** — PDO `mysql`, `SHOW DATABASES`

**Strategy 2 — kubectl exec fallback** (used when Cellar runs outside the K8s cluster, e.g., Docker on host):

- Runs DB CLI inside the discovered pod via `kubectl exec <pod> -n <namespace> -- sh -c "<query_cmd>"`
- `KUBECTL_COMMANDS` constant defines per-engine commands: `psql -U %s -d postgres -t -A -c "SELECT datname..."` for PostgreSQL, `mysql -u %s %s -N -e "SHOW DATABASES"` for MySQL/MariaDB
- `buildKubectlExecCommand()` constructs the full command array including kubeconfig and context from `$kubectlContext`
- Uses Laravel `Process::timeout(15)->run()` for execution

**Common:**

- **MongoDB** — `mongosh` CLI, `listDatabases` admin command
- **Redis** — not supported (no concept of named databases)
- Returns `{databases: string[], error: string|null}` with friendly error messages
- System DB exclusion in `SYSTEM_DBS` constant (template0/1, information_schema, mysql, performance_schema, sys, etc.)
- `listDatabases(type, host, port, user, pass, kubectlContext?)` — public API, orchestrates both strategies

---

## 13. Controllers (Api/V1)

### `AuthController`

- `login` — Validates username/password, issues Sanctum plain-text token. Supports login by username or email.
- `logout` — Revokes current token.
- `me` — Returns authenticated user.

### `SystemController`

- `health` — Returns status + version + database/redis connectivity checks.

### `RepositoryController`

- Standard CRUD (apiResource).
- `test` — For local backends: checks `is_dir`, reports disk space. Updates status to online/offline.
- `import` — Imports an existing Borg repository: validates path, checks for borg `README` file, calls `getRepoInfo()` + `listArchives()` + `getArchiveInfo()` per archive. Creates a Source (type=directory), BackupPlan (schedule_enabled=false), and Archive records. Stores `imported_paths` mapping in repo config. Returns 201 with archive count.

### `SourceController`

- Standard CRUD.
- `quickAdd` — Creates Source + BackupPlan in one call. Auto-creates default local repository if none exists.
- `testConnection` — For database sources: runs `pg_isready` (PostgreSQL) or `mysqladmin ping` (MySQL/MariaDB). For filesystem sources (directory/docker_volume/sqlite): validates path with `file_exists()`, `is_dir()`, `is_readable()`.

### `BackupPlanController`

- Standard CRUD with eager-loaded `source` and `repository`.
- `backup` — Dispatches `RunBackup` job.
- `restore` — Dispatches `RunRestore` job (requires `archive_id`).
- `prune` — Dispatches `RunPrune` job (supports `dry_run` flag).
- `verify` — Dispatches `RunVerify` job (optional `archive_id`).

### `JobController`

- Read-only `index`/`show`. Supports filtering by `plan`, `job_type`, `status`. Paginated (default 25).

### `ArchiveController`

- `index` — Paginated, filterable by `plan`.
- `show` / `destroy` — Standard.
- `restore` — Dispatches `RunRestore` job.
- `download` — Extracts archive to temp dir, converts custom-format dumps to plain SQL, streams as download. Cleanup via `app()->terminating()`.

### `NotificationChannelController`

- Standard CRUD (apiResource).

### `DocumentController`

- Standard CRUD. `test` action is a stub (not yet implemented).

### `KubernetesController`

**Cluster CRUD:**

- `clusters` — List all saved cluster configurations.
- `storeCluster` — Create a new cluster; accepts kubeconfig file upload (multipart/form-data, max 512KB, encrypted at rest).
- `updateCluster` — Update cluster name, context, namespace, or replace/clear kubeconfig.
- `destroyCluster` — Delete a cluster and its associated ignore list.

**Cluster-scoped discovery (all routes prefixed with `clusters/{cluster}`):**

- `test` — Cluster connectivity check via `KubernetesDiscovery::fromCluster()`.
- `discover` — Runs full discovery scoped to a cluster, filters out ignored resources, annotates `already_added`, updates `last_scanned_at`.
- `namespaces` — Lists available namespaces in the cluster.
- `import` — Batch-creates Source records from selected discovered resources.
- `ignore` — Persists a resource to `RadarIgnore` (scoped to cluster) so it no longer appears in discovery.
- `ignored` — Lists ignored resources for a cluster.
- `unignore` — Removes a resource from the ignore list.
- `listDatabases` — Cluster-scoped (`clusters/{cluster}/list-databases`). Accepts `source_type`, `host`, `port`, `username`, `password`, `pod_name`, `namespace`. Builds `$kubectlContext` (temp kubeconfig, kubectl path, context) from the cluster and passes it to `DatabaseInspector` for kubectl exec fallback.

---

## 14. Database Migrations

12 migration files:

1. `0001_01_01_000000_create_users_table.php` — users, password_reset_tokens, sessions
2. `0001_01_01_000001_create_cache_table.php` — cache, cache_locks
3. `0001_01_01_000002_create_jobs_table.php` — Laravel queue jobs, job_batches, failed_jobs
4. `0001_01_01_000003_create_personal_access_tokens_table.php` — Sanctum tokens
5. `2024_01_01_000010_create_repositories_table.php`
6. `2024_01_01_000011_create_sources_table.php`
7. `2024_01_01_000012_create_backup_plans_table.php`
8. `2024_01_01_000013_create_backup_jobs_table.php`
9. `2024_01_01_000014_create_archives_table.php`
10. `2024_01_01_000015_create_notification_channels_table.php`
11. `2024_01_01_000016_create_custom_documents_table.php`
12. `2024_01_01_000017_create_radar_ignores_table.php` — K8s Radar ignore list (resource_key unique, namespace, name, kind, source_type, reason)
13. `2024_01_01_000018_create_radar_clusters_table.php` — K8s cluster configs (name, kubeconfig encrypted, context, default_namespace, is_active, last_scanned_at) + adds cluster_id FK to radar_ignores

---

## 15. Frontend

### Tech Stack

- **Vue 3.5+**, **Pinia 2.2+**, **Vue Router 4.4+**
- **Tailwind CSS 4**, **Radix Vue** (headless UI), **Lucide icons**
- **Axios** for API calls, **Chart.js + vue-chartjs** for charts
- **TypeScript 5.6+**, **Vite 6**
- Dev: ESLint 9, Prettier, Vitest, jsdom

### `frontend/src/lib/api.ts` — Axios Instance

```typescript
const api = axios.create({ baseURL: "/api/v1" });
// Request: attaches Bearer token from localStorage("cellar_access_token")
// Response: on 401 → clears session, soft-redirects to /login via router
```

### `frontend/src/App.vue`

- Public pages (login): full-screen `<RouterView>`
- Authenticated pages: sidebar (`AppSidebar`) + main content area

### Router — 8 routes

| Path        | Name      | View              | Auth Required |
| ----------- | --------- | ----------------- | :-----------: |
| `/login`    | login     | LoginView.vue     |      No       |
| `/`         | dashboard | DashboardView.vue |      Yes      |
| `/sources`  | sources   | SourcesView.vue   |      Yes      |
| `/plans`    | plans     | PlansView.vue     |      Yes      |
| `/archives` | archives  | ArchivesView.vue  |      Yes      |
| `/jobs`     | jobs      | JobsView.vue      |      Yes      |
| `/radar`    | radar     | RadarView.vue     |      Yes      |
| `/settings` | settings  | SettingsView.vue  |      Yes      |

Auth guard: on first load validates token via `GET /auth/me`; subsequent navigations check in-memory state.

### Pinia Stores

**`auth.ts`** — Token management. Stores `cellar_access_token` and `cellar_user` in localStorage. Methods: `checkAuth()`, `login(username, password)`, `logout()`, `clearSession()`.

**`plans.ts`** — Manages BackupPlan[], Job[], Archive[]. Methods: `fetchPlans()`, `fetchJobs()`, `fetchArchives()`, `triggerBackup(planId)`, `triggerPrune(planId)`, `triggerVerify(planId)`, `triggerRestore(archiveId)`, `downloadArchive(archiveId)`.

**`sources.ts`** — Manages Source[]. Methods: `fetchSources()`, `quickAdd(payload)`, `getSource(id)`, `updateSource(id, payload)`, `testConnection(id)`, `deleteSource(id)`.

**`radar.ts`** — Manages multi-cluster K8s Radar state. Types: `ResourceEndpoint` (kind, resource_name, host, port, external fields, image), `DiscoveredCredential` (secret_name, key, value), `DiscoveredResource` (includes `endpoints[]` and `credentials[]`), `ImportOverride` (per-resource host/port/username/password/database_name overrides). Tracks saved clusters, active cluster selection, discovered resources, ignored list. Cluster CRUD: `fetchClusters()`, `createCluster(name, kubeconfigFile?, context?, defaultNamespace?)`, `updateCluster(...)`, `deleteCluster(id)`, `selectCluster(id)`. Discovery (cluster-scoped): `testConnection()`, `discover()`, `importResources(selected, overrides?)`, `ignoreResource(resource, reason?)`, `fetchIgnored()`, `unignore(id)`. Database inspection: `listDatabases(sourceType, host, port, username?, password?, podName?, podNamespace?)` — calls cluster-scoped endpoint, passes pod info for kubectl exec targeting. Uses `FormData` with multipart upload for kubeconfig files.

### Views (9 files)

`ArchivesView.vue`, `DashboardView.vue`, `JobsView.vue`, `LoginView.vue`, `LogsView.vue`, `PlansView.vue`, `RadarView.vue`, `SettingsView.vue`, `SourcesView.vue`

#### Notable View Features

- **SourcesView** — Wizard supports two categories: Databases (PostgreSQL, MySQL, MariaDB, MongoDB, Redis) and Filesystem (Directory, Docker Volume). Step 2 form fields adapt based on category.
- **PlansView** — Includes "Import Borg Repo" modal for importing existing Borg repositories into Cellar.
- **RadarView** — Multi-cluster K8s discovery UI: cluster selector bar with add/edit/delete, kubeconfig file upload modal, per-cluster scan with namespace filter, scan results as selectable list with Pod/Service endpoint toggle (clickable badges showing service type: LB/NP/CIP/Ext), external access badges and hints, Secret credential badge. Import review modal with endpoint selector (shows each endpoint’s K8s resource name + type), editable host/port, auto-filled username/password from discovered Secrets, “Detect databases” button that queries the actual DB server and shows selectable database chips. Bulk import, per-resource ignore with “Ignored” panel toggle.

---

## 16. Docker

### `Dockerfile.api`

- Base: `php:8.4-cli`
- Installs: borgbackup, restic, postgresql-client, default-mysql-client, supervisor, cron, kubectl
- PHP extensions: pdo, pdo_mysql, pdo_pgsql, pdo_sqlite, zip, pcntl, bcmath, redis (PECL)
- Runs Composer install, copies backend code, creates data dirs
- Entrypoint: `docker/entrypoint.sh`
- Default CMD: `php artisan serve --host=0.0.0.0 --port=8000`

### `Dockerfile.ui`

- Build stage: `node:20-alpine`, `npm ci`, `npm run build`
- Production stage: `nginx:alpine`, serves `/app/dist` with `nginx.conf`

### `entrypoint.sh`

1. Creates data directories
2. Copies `.env.docker` → `.env` (if exists)
3. Persists `APP_KEY` across container rebuilds (stored in `/app/data/.app_key`)
4. Creates SQLite file if missing
5. Runs `php artisan migrate --force`
6. Runs `php artisan cellar:seed-defaults`
7. Execs into main process

### `Caddyfile`

Listens on `:8420`. Routes:

- `/api/*` → `cellar-api:8000`
- `/horizon/*` → `cellar-api:8000`
- `/sanctum/*` → `cellar-api:8000`
- Everything else → `cellar-ui:80` (SPA catch-all)

### `nginx.conf`

SPA fallback (`try_files $uri $uri/ /index.html`), API proxy to `cellar-api:8000`, WebSocket proxy at `/ws/`, static asset caching (1 year).

### `docker-compose.postgres.yml` (override)

Adds `cellar-db` (PostgreSQL 16), overrides API/worker/scheduler envs with `CELLAR_DB_*` variables.

---

## 17. Makefile

```makefile
up          # docker compose up -d
down        # docker compose down
build       # docker compose build
logs        # docker compose logs -f
shell       # exec bash in cellar-api
frontend-shell  # exec sh in cellar-ui
migrate     # artisan migrate
artisan     # artisan $(CMD) — e.g., make artisan CMD="route:list"
seed        # artisan cellar:seed-defaults
tinker      # artisan tinker
horizon-status  # artisan horizon:status
lint        # frontend npm run lint
format      # frontend npm run format
typecheck   # frontend npm run type-check
test        # backend + frontend tests
test-backend    # artisan test only
test-frontend   # npm run test only
clean       # remove node_modules + dist + vendor
```

---

## 18. Key Design Decisions

1. **SQLite default** — Zero-config for HomeLab. PostgreSQL available as overlay.
2. **UUID primary keys** — All domain models use `HasUuids` for portability.
3. **Encrypted-at-rest** — Repository configs and source passwords use Laravel's `encrypted` / `encrypted:array` casts.
4. **Sanctum token auth** — Stateless Bearer tokens stored in frontend localStorage.
5. **Horizon for queues** — Redis-backed with dashboard at `/horizon`.
6. **Borg-first** — `BackupEngine` interface allows future engines (restic), but MVP implements BorgBackup only.
7. **Custom backup documents** — Extensibility via user-defined backup/restore shell commands.
8. **Schedule per plan** — Each `BackupPlan` has its own cron expression, evaluated every minute by the scheduler.
9. **Auto-pruning** — Prune runs 30 minutes after each scheduled backup.
10. **Caddy reverse proxy** — Handles routing between SPA and API, with admin-off for minimal footprint.
11. **Borg repo import** — Importing creates an anchor Source (type=directory) + BackupPlan (schedule_enabled=false) to satisfy FK constraints, then creates Archive records for each discovered archive. Stored `imported_paths` mapping in repo config for traceability.
12. **Kubernetes Radar** — Uses kubectl CLI (not client-go) via Laravel Process facade for simplicity. Supports multiple saved cluster configurations with kubeconfig file upload (encrypted at rest via Laravel's `encrypted` cast) or in-cluster ServiceAccount auto-detect. Ignore list persisted in `radar_ignores` table (scoped per cluster) to prevent rediscovery noise. Temp kubeconfig files written on-demand and cleaned up in `__destruct`.
13. **Non-database sources** — Frontend wizard split into Database/Filesystem categories. Backend `testConnection()` validates filesystem paths instead of rejecting non-DB types.
