# Architecture

Cellar follows a clean three-tier architecture, fully containerized.

## Containers

| Container         | Role              | Stack                        |
| ----------------- | ----------------- | ---------------------------- |
| **cellar-ui**     | Frontend SPA      | Vue 3 + Vite + Tailwind CSS  |
| **cellar-api**    | REST API          | Python 3.12 + Django 5 + DRF |
| **cellar-worker** | Async task runner | Celery + Redis               |
| **cellar-redis**  | Broker & cache    | Redis 7                      |

> **Database:** SQLite by default (embedded, zero-config). PostgreSQL supported for larger deployments via `CELLAR_DB_ENGINE=postgresql`.

## Request Flow

1. User creates or triggers a backup through the Vue frontend
2. API validates and dispatches the job to the Celery worker queue via Redis
3. Worker executes the backup engine (Borg, restic, or custom script)
4. Progress streams back to the UI via WebSocket in real-time
5. Archive metadata is recorded in the application database (SQLite or PostgreSQL)

## Backup Engine Abstraction Layer (BEAL)

The BEAL decouples the UI and scheduler from the underlying backup tool. Each engine implements: `initialize()`, `backup()`, `restore()`, `list_archives()`, `prune()`, and `verify()`.
