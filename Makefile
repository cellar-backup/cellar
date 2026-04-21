# =============================================================================
# Cellar — Development Makefile
# =============================================================================

.DEFAULT_GOAL := help
.PHONY: help up down build logs shell migrate artisan lint format typecheck test clean dev

# --- Docker Compose -----------------------------------------------------------

up: ## Start all services
	docker compose up -d

down: ## Stop all services
	docker compose down

build: ## Build all Docker images
	docker compose build

logs: ## Tail logs from all services
	docker compose logs -f

# --- Development shortcuts ----------------------------------------------------

dev: ## Start local development (Vite + Laravel)
	php artisan serve & npm run dev

shell: ## Open a shell in the API container
	docker compose exec cellar-api bash

migrate: ## Run Laravel migrations
	php artisan migrate

artisan: ## Run any artisan command (usage: make artisan CMD="route:list")
	php artisan $(CMD)

seed: ## Seed default admin + demo data
	php artisan db:seed
	php artisan db:seed --class=DemoSeeder

tinker: ## Open Laravel Tinker (interactive REPL)
	php artisan tinker

horizon-status: ## Check Horizon queue status
	php artisan horizon:status

# --- Code Quality -------------------------------------------------------------

lint: ## Run linters (ESLint + Pint)
	npm run lint
	vendor/bin/pint --test

format: ## Auto-format code
	npm run format
	vendor/bin/pint

typecheck: ## Run TypeScript type checker
	npm run type-check

# --- Testing ------------------------------------------------------------------

test: test-backend test-frontend ## Run all tests

test-backend: ## Run backend tests only
	php artisan test

test-frontend: ## Run frontend tests only
	npm run test

# --- Build --------------------------------------------------------------------

build-frontend: ## Build frontend for production
	npm run build

# --- Maintenance --------------------------------------------------------------

clean: ## Remove build artifacts, caches, temp files
	rm -rf node_modules vendor public/build
	rm -rf storage/framework/cache/data/*

# --- Help ---------------------------------------------------------------------

help: ## Show this help message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'
