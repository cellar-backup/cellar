# Production Hardening Guide

This guide covers security and reliability practices for running Cellar in production, beyond the defaults provided in `docker-compose.yml`.

## First-Boot Isolation

Cellar's `/api/v1/setup` endpoint creates the initial admin user. Protect it:

1. **Set `CELLAR_SETUP_TOKEN`** in your environment. The setup endpoint requires this token, preventing drive-by account creation.
2. **Network isolation during setup:** Only expose the Cellar port to your local machine until setup is complete.
3. **Verify lockout:** After first setup, confirm the endpoint returns 403:
   ```bash
   curl -s -o /dev/null -w "%{http_code}" http://localhost:8420/api/v1/setup
   # Should return 403
   ```

## Reverse Proxy & TLS

Cellar does not terminate TLS. Place it behind a reverse proxy.

### Caddy (recommended)

```Caddyfile
cellar.example.com {
    reverse_proxy localhost:8420
    # WebSocket support for job updates
    reverse_proxy /app/* localhost:8420
}
```

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name cellar.example.com;

    ssl_certificate     /etc/ssl/certs/cellar.pem;
    ssl_certificate_key /etc/ssl/private/cellar.key;

    location / {
        proxy_pass http://127.0.0.1:8420;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # WebSocket
    location /app/ {
        proxy_pass http://127.0.0.1:8420;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }
}
```

## Secret Rotation

### APP_KEY

```bash
# Generate a new key
docker compose exec api php artisan key:generate --show
# Update .env with the new key, then restart
docker compose restart api
```

> **Warning:** Rotating `APP_KEY` invalidates all encrypted data (e.g., source passwords). Re-enter them after rotation.

### CELLAR_BORG_PASSPHRASE

Changing the borg passphrase requires re-keying each repository:

```bash
export BORG_PASSPHRASE="old-passphrase"
export BORG_NEW_PASSPHRASE="new-passphrase"
borg key change-passphrase /data/repositories/<plan_id>
```

Repeat for every repository, then update `CELLAR_BORG_PASSPHRASE` in `.env`.

### Sanctum Tokens

Admin tokens can be revoked from the Cellar UI (Settings → API Tokens) or via:

```bash
docker compose exec api php artisan tinker --execute="
    \App\Models\User::first()->tokens()->delete();
"
```

The user will need to log in again to get a new token.

## Non-Root Containers

The unified Dockerfile runs PHP-FPM as `www-data`. Verify:

```bash
docker compose exec api whoami
# Should output: www-data
```

For additional isolation:

```yaml
# docker-compose.override.yml
services:
  cellar:
    read_only: true
    tmpfs:
      - /tmp
    security_opt:
      - no-new-privileges:true
```

## Network Segmentation

- **Redis:** Bind to `127.0.0.1` or use a Unix socket. Do not expose Redis to the network.
- **SQLite:** Stored on a local volume. No network exposure by default.
- **Borg repositories:** Mount as a dedicated volume with restricted permissions (`chmod 700`).

## Environment Variables Checklist

```bash
APP_ENV=production
APP_DEBUG=false
APP_KEY=<generated>

# Security
CELLAR_SETUP_TOKEN=<random-string>
CELLAR_BORG_PASSPHRASE=<strong-passphrase>
CELLAR_FORCE_ENCRYPTION=true

# Rate limiting
CELLAR_LOGIN_RATE_LIMIT=5     # per minute per IP
```

## Monitoring

- **Container health:** The unified image includes a healthcheck. Monitor it with your orchestrator.
- **Backup freshness:** Set up alerts if the last successful backup for any plan exceeds its schedule interval × 2.
- **Disk usage:** Monitor `/data/repositories` — borg repos grow over time even with pruning.
- **Job failures:** Check the Jobs tab or subscribe to notification channels.

## Backup the Backup System

Cellar's own state (SQLite DB, config) should be backed up separately:

```bash
# SQLite database
cp /app/data/database.sqlite /backup/cellar-meta/database.sqlite

# Environment config
cp .env /backup/cellar-meta/.env

# Borg keys (critical — without these, encrypted repos are unrecoverable)
borg key export /data/repositories/<plan_id> /backup/cellar-meta/keys/<plan_id>.key
```

Store these offsite. If you lose the borg keys and passphrase, the backups are gone.
