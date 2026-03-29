# Restore Drill Runbook

A periodic restore drill validates that backups are actually recoverable. Run this quarterly at minimum, or after any significant infrastructure change.

## Prerequisites

- A running Cellar instance (can be a staging/test instance)
- Access to at least one borg repository with recent archives
- A clean target directory or database for restore validation
- Sufficient disk space for the largest expected restore

## Drill Procedure

### 1. Select Archives to Test

Pick at least one archive from each active backup plan:

```bash
# List archives for a plan via the API
curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8420/api/v1/plans/{plan_id}/archives | jq '.[-1]'
```

Or use the Cellar UI → Archives tab.

### 2. Restore to an Isolated Location

**Via UI:**
1. Go to Archives → select an archive → Restore
2. Choose a target path (use a temp directory, e.g., `/tmp/restore-drill/`)
3. Monitor the job in the Jobs tab

**Via API:**
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"archive_id": "<id>", "target_path": "/tmp/restore-drill"}' \
  http://localhost:8420/api/v1/plans/{plan_id}/restore
```

**Via borg directly (out-of-band validation):**
```bash
export BORG_PASSPHRASE="<your-passphrase>"
borg extract --dry-run /data/repositories/<plan_id>::<archive_name>
# If dry run passes, extract for real:
cd /tmp/restore-drill
borg extract /data/repositories/<plan_id>::<archive_name>
```

### 3. Validate Restored Data

For **filesystem backups:**
```bash
# Compare file counts
find /tmp/restore-drill -type f | wc -l
# Spot-check key files
diff /tmp/restore-drill/path/to/critical/file /original/path/to/critical/file
```

For **database backups:**
```bash
# Restore to a temporary database
createdb restore_drill_test
pg_restore -d restore_drill_test /tmp/restore-drill/dump.sql
# Or for MySQL:
mysql -u root restore_drill_test < /tmp/restore-drill/dump.sql

# Run a few queries to validate
psql restore_drill_test -c "SELECT count(*) FROM important_table"

# Clean up
dropdb restore_drill_test
```

### 4. Record Results

Document in your ops log:

| Field | Value |
|-------|-------|
| Date | YYYY-MM-DD |
| Plan | plan name / ID |
| Archive | archive name / timestamp |
| Archive age | days since creation |
| Restore method | UI / API / borg direct |
| Duration | minutes |
| Data validated | yes/no + method |
| Issues found | none / description |
| Operator | name |

### 5. Clean Up

```bash
rm -rf /tmp/restore-drill
dropdb restore_drill_test 2>/dev/null  # if applicable
```

## Success Criteria

- [ ] Archive extracted without errors
- [ ] File counts / sizes match expectations
- [ ] Database queries return expected data (for DB backups)
- [ ] Restore completed within acceptable time window
- [ ] No corruption or missing data detected

## Failure Response

If a restore drill fails:

1. **Do not panic** — the drill exists to catch this.
2. Check borg repository integrity: `borg check /data/repositories/<plan_id>`
3. Review the backup job logs for the failed archive.
4. If the repo is corrupt, check for disk errors, and restore from a secondary/offsite copy.
5. Open a Cellar issue with the failure details.
6. Re-run the backup immediately and verify the new archive restores cleanly.
