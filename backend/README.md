# Cellar Backend

PHP 8.4 + Laravel 11 REST API powering the Cellar backup management platform.

## Key Components

- **Sanctum** — Bearer token authentication
- **Horizon** — Redis-powered queue dashboard & worker management
- **Eloquent** — ORM with UUID primary keys and encrypted attributes
- **BorgBackup / Restic** — Backup engine integrations

## Local Development

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan cellar:seed-defaults
php artisan serve
```

## Running in Docker

The backend is deployed via Docker — see the root `docker-compose.yml` and `docker/Dockerfile.api`.
