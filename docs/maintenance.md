# Maintenance · Pinksheet

Keep the SQLite database, backups, and scheduled jobs healthy. For day-to-day app use, see **[Usage](usage.md)** and **[Operator SOP](ops.md)**. For restores, see **[Restore playbook](restore_playbook.md)**.

---

## Quick reference

| Topic | Where to look |
|--------|----------------|
| Run backup from browser | Home → **Run backup now** → `backup_now.php` → `scripts/backup.ps1` |
| Verify backup + DB | `scripts/verify_backup.ps1` or Home → **Verify latest backup** |
| Schedule nightly job | `scripts/register_backup_task.ps1` (elevated) |
| Restore latest file | `scripts/restore_latest_backup.ps1` (`-DryRun` to preview) |
| Health JSON | `health.php` |
| Downtime banner | `config.php` → `MAINTENANCE_MODE` |

---

## Backups & logs

> [!TIP]
> **Retention:** the backup script defaults to **no pruning** (`RetentionDays` **0**). Only pass `-RetentionDays N` if you intentionally want old backups removed.

### What `scripts/backup.ps1` does

- Copies **`data/intake.sqlite`** into **`data/backups/`** with a dated name.
- Writes **`intake-*.sha256`** checksum files alongside backups.
- Rotates **`logs/lookup.csv`** into **`logs/archive/`** when configured.

### OneDrive & mirrors

- **OneDrive (default):** when OneDrive is present, backups + checksums are also copied to **`%UserProfile%\OneDrive\pinksheet-backups`**.
- **Custom mirror:** use **`-CopyTo <path>`** for another folder, UNC, or a staging area before upload elsewhere.

### Photos (optional)

| Flag | Behavior |
|------|----------|
| **`-CopyPhotosTo <path>`** | Mirrors **`data/sku_photos/`** with robocopy **`/MIR`** (can be large). |
| **`-CopyTo`** only | Photos can mirror to **`<CopyTo>\sku_photos`** when photo flag is omitted (see script help for your version). |

### Sleep after backup

- **`-SleepIfIdleMinutes N`:** if the machine has been idle at least **N** minutes after backup, it may be put to sleep. Use **`0`** to disable.

### Integrity & alerts

- **`scripts/verify_backup.ps1`** — checksum (if present) + **`PRAGMA integrity_check`** on the live DB and the newest backup.
- **Email:** copy **`scripts/alert.config.sample.ps1`** → **`scripts/alert.config.ps1`**, set SMTP. A scheduled task can pass **`-NotifyAlways`** for nightly success + failure mail.

### Scheduled task (Windows)

Example (run **elevated**):

```powershell
scripts/register_backup_task.ps1 -Hour 0 -Minute 15 -RetentionDays 0 -SleepIfIdleMinutes 5
```

Chains **backup → integrity check**. Default task name: **`PinksheetNightlyBackup`**.

### Restore (manual)

> [!WARNING]
> **Stop the app** (or block writes) before replacing **`data/intake.sqlite`**, then copy a known-good file from **`data/backups/`** and start again.

### Restore (helper)

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/restore_latest_backup.ps1
```

Creates a **safety copy** of the current DB, then restores the **newest** backup. Use **`-DryRun`** to preview only.

### Git hooks

- **`.githooks/pre-commit`** — blocks staging DB/backups/logs; can run backup.
- **`.githooks/pre-push`** — backup before push.

> [!NOTE]
> On a **fresh clone**, enable hooks: `git config core.hooksPath .githooks`

### Status board

- **`kanban.php`** updates status via **`update_item.php`**. Keep that endpoint **local / private** (not exposed to the public internet without auth).

---

## Health & maintenance mode

| Asset | Role |
|-------|------|
| **`health.php`** | JSON for probes: maintenance flag, backup name/age/size, checksum status |
| **`config.php`** | **`MAINTENANCE_MODE`** — when **true**, downtime banner + **503**-style behavior for checks |

---

## Database care

| Action | How |
|--------|-----|
| Occasional tidy | `VACUUM;` and `ANALYZE;` via `sqlite3 data/intake.sqlite` if the DB grows/shrinks a lot |
| Off-box copies | After backups, mirror **`data/backups/`** (robocopy, NAS, SharePoint, etc.) |
| WAL / settings | **`scripts/migrate.php`** sets **`journal_mode=WAL`**, **`synchronous=NORMAL`**, and indexes (**`sku_normalized`**, **`status, updated_at`**). Rerun after moving the DB file. |

---

## Space management

> [!IMPORTANT]
> Pruning only happens when you set **retention** in the backup flow. If disks are tight, either mirror off-box first or lower **`RetentionDays`** in the **scheduled** command — not blindly on production without a policy.

---

## Task visibility (Windows)

- Enable **Task Scheduler → View → Enable All Tasks History** to see run history.
- Inspect the task:

```powershell
Get-ScheduledTask -TaskName PinksheetNightlyBackup | Format-List TaskName, State, LastRunTime, NextRunTime
```

---

## Related documentation

| Doc | Purpose |
|-----|---------|
| [Restore playbook](restore_playbook.md) | DB + photo restore, rollback |
| [Ops SOP](ops.md) | Daily / weekly operator checks |
| [Testing](testing.md) | Smoke + manual checklist |
| [Dev](dev.md) | File map, local run, conventions |
| [Usage](usage.md) | End-user flows and buttons |
| [Schema](schema.md) | `intake_items` columns |
