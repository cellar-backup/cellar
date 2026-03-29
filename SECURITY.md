# Security Policy

## Reporting a Vulnerability

**Do NOT open a public issue for security vulnerabilities.**

Please report vulnerabilities privately using one of these methods:

1. **GitHub Private Vulnerability Reporting:**  
   Go to [Security → Advisories → New draft advisory](https://github.com/cellar-backup/cellar/security/advisories/new) to file a confidential report.

2. **Email:**  
   Send details to **security@cellar-backup.dev** (or the repository maintainer's email listed in the org profile). Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Affected version(s)
   - Impact assessment (if known)

We aim to acknowledge reports within **48 hours** and provide a fix or mitigation plan within **7 days** for critical issues.

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 0.x     | ✅ Current (pre-release) |

Once Cellar reaches 1.0, we will maintain security patches for the latest minor release.

## Security Practices

### Authentication & Access
- The default admin password is **randomly generated** at first boot and printed to container logs. There is no fixed default password.
- The `/api/v1/setup` endpoint is locked after first use and can optionally require a `CELLAR_SETUP_TOKEN`.
- All API access requires Sanctum token authentication.
- Setup attempts are rate-limited (5/min per IP).

### Encryption
- Borg repositories default to **repokey-blake2** encryption. Unencrypted repos require explicit opt-in.
- Set `CELLAR_BORG_PASSPHRASE` in your environment — the system will refuse to create encrypted repos without it.

### Operational Hardening
- Run the container with a **read-only root filesystem** where possible; mount `/app/data` and `/data/repositories` as writable volumes.
- Use a **non-root user** inside the container (the unified Dockerfile runs as `www-data` for PHP-FPM).
- Set `APP_DEBUG=false` and `APP_ENV=production` in production.
- Rotate `APP_KEY`, Sanctum tokens, and `CELLAR_BORG_PASSPHRASE` periodically.
- Enable TLS termination (reverse proxy / ingress) — Cellar does not handle TLS directly.
- Restrict network access to the Redis and SQLite/database backends.

### CI Security
- `composer audit` and `npm audit` run on every PR.
- Container images are scanned with Trivy for CRITICAL/HIGH CVEs.
- Dependabot monitors PHP, npm, Docker, and GitHub Actions dependencies weekly.

## Disclosure Timeline

1. Report received → acknowledgment within 48h
2. Triage and severity assessment → 72h
3. Fix development → varies by severity (critical: 7 days, high: 14 days, medium: 30 days)
4. Coordinated disclosure after fix is released

## Hall of Fame

We appreciate responsible disclosure. Contributors who report valid vulnerabilities will be credited here (with permission).
