# Getting Started

Get Cellar running in under 5 minutes.

## Prerequisites

- Docker & Docker Compose v2+
- A machine with at least 1GB RAM

## Quick Start

```bash
# Clone the repository
git clone https://github.com/your-org/cellar.git
cd cellar

# Configure environment
cp .env.example .env
# Edit .env — at minimum, set CELLAR_SECRET_KEY

# Launch all services
docker compose up -d

# Open the web UI
open http://localhost:8420
```

On first visit, you'll be prompted to create an admin account.

## Next Steps

1. **Add a Storage** — configure where your backups will be stored
2. **Create a Vault** — define what to back up, when, and how long to keep it
3. **Run your first backup** — click "Backup Now" and watch it happen in real-time

## Environment Variables

See [Configuration](./configuration.md) for the full list of environment variables.
