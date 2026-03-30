# Release Process

Cellar uses [Release Please](https://github.com/googleapis/release-please) to automate versioning and changelog generation from [Conventional Commits](https://www.conventionalcommits.org/).

## How It Works

1. Merge PRs with conventional commit messages into `main`.
2. Release Please opens/updates a release PR that bumps the version and updates `CHANGELOG.md`.
3. Merging the release PR creates a GitHub Release and tags the commit.
4. CI builds and pushes the container image tagged with the new version.

## Release Checklist

Before merging a release PR, verify:

- [ ] `CHANGELOG.md` entries are accurate and well-worded
- [ ] Version bumped in: `package.json`, `frontend/package.json`, `backend/composer.json`, `backend/config/cellar.php`
- [ ] CI pipeline passes (backend tests, frontend lint + type-check + build, security audit, container scan)
- [ ] No open CRITICAL/HIGH Trivy findings
- [ ] Migration files (if any) are reviewed and tested against a fresh DB
- [ ] Breaking changes documented in the changelog with migration steps
- [ ] Container image tested locally: `docker compose up -d && docker compose logs -f`

## Post-Release

- [ ] Verify the GitHub Release was created with correct tag
- [ ] Verify container image pushed to `ghcr.io/cellar-backup/cellar:<version>`
- [ ] Smoke-test the published image: setup flow, backup, restore, prune
- [ ] Update any deployment manifests (Helm values, docker-compose tags)
- [ ] Announce in project channels if applicable

## Rollback Playbook

If a release causes issues in production:

### 1. Revert to Previous Container Image

```bash
# Pin to the last known good version
docker compose down
# Edit docker-compose.yml: image: ghcr.io/cellar-backup/cellar:<previous-version>
docker compose up -d
```

### 2. Database Rollback (if migrations were involved)

```bash
# Check which migrations ran
docker compose exec api php artisan migrate:status

# Roll back the last batch
docker compose exec api php artisan migrate:rollback --step=1
```

### 3. Revert the Release Commit

```bash
git revert <release-merge-commit>
git push origin main
```

This triggers a new CI build that effectively "un-releases" the version.

### 4. Investigate and Fix Forward

- Check container logs: `docker compose logs api`
- Check job logs in the UI or `/app/data/logs/`
- Open an issue with the failure details
- Fix forward with a patch release rather than staying reverted
