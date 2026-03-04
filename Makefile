# =============================================================================
# Cellar — Development Makefile
# =============================================================================

.DEFAULT_GOAL := help
.PHONY: help up down build logs shell frontend-shell migrate artisan lint test clean

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

shell: ## Open a shell in the API container
	docker compose exec cellar-api bash

frontend-shell: ## Open a shell in the UI container
	docker compose exec cellar-ui sh

migrate: ## Run Laravel migrations
	docker compose exec cellar-api php artisan migrate

artisan: ## Run any artisan command (usage: make artisan CMD="route:list")
	docker compose exec cellar-api php artisan $(CMD)

seed: ## Seed default admin + repository
	docker compose exec cellar-api php artisan cellar:seed-defaults

tinker: ## Open Laravel Tinker (interactive REPL)
	docker compose exec cellar-api php artisan tinker

horizon-status: ## Check Horizon queue status
	docker compose exec cellar-api php artisan horizon:status

# --- Code Quality -------------------------------------------------------------

lint: ## Run linters
	cd frontend && npm run lint

format: ## Auto-format frontend code
	cd frontend && npm run format

typecheck: ## Run TypeScript type checker
	cd frontend && npm run type-check

# --- Testing ------------------------------------------------------------------

test: ## Run all tests
	docker compose exec cellar-api php artisan test
	cd frontend && npm run test

test-backend: ## Run backend tests only
	docker compose exec cellar-api php artisan test

test-frontend: ## Run frontend tests only
	cd frontend && npm run test

# --- Maintenance --------------------------------------------------------------

clean: ## Remove build artifacts, caches, temp files
	find . -type d -name node_modules -exec rm -rf {} + 2>/dev/null || true
	rm -rf frontend/dist backend/vendor

# --- Help ---------------------------------------------------------------------

help: ## Show this help message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'
