#!/usr/bin/env bash
#
# setup_linux_cron.sh — register Pinksheet's background jobs in cron (Linux).
#
# Mirrors scripts/setup_scheduled_tasks.ps1 for the Debian LXC / Proxmox
# deployment where the app runs under systemd (pinksheet unit):
#
#   * Sync queue worker        every 2 minutes   scripts/process_sync_queue.php --limit=20
#   * Inventory reconciliation daily 03:00       scripts/reconcile_square.php
#   * Nightly DB backup        daily 02:00       scripts/backup_db.php
#   * DB integrity check       daily 08:00       scripts/check_db.php data/intake.sqlite
#
# The script is idempotent: it rewrites only the block it owns (between the
# "<<< pinksheet tasks" markers), so it is safe to re-run after deploys.
#
# Usage:
#   bash scripts/setup_linux_cron.sh
#
# Run it inside the container, e.g. from the Proxmox host:
#   pct exec 141 -- bash /opt/pinksheet/scripts/setup_linux_cron.sh
#
# If cron is not installed in the container, install it first:
#   apt-get update && apt-get install -y cron && systemctl enable --now cron

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

echo "Project root: $PROJECT_ROOT"

# ── Find PHP ────────────────────────────────────────────────────
PHP_BIN="$(command -v php || true)"
if [ -z "$PHP_BIN" ]; then
    for cand in /usr/bin/php /usr/local/bin/php; do
        [ -x "$cand" ] && PHP_BIN="$cand" && break
    done
fi
if [ -z "$PHP_BIN" ] || ! "$PHP_BIN" -v >/dev/null 2>&1; then
    echo "ERROR: PHP not found. Install it or set PHP_BIN before re-running." >&2
    exit 1
fi
echo "Using PHP: $PHP_BIN"

# ── Check cron availability ─────────────────────────────────────
if ! command -v crontab >/dev/null 2>&1; then
    echo "ERROR: 'crontab' not found. Install cron in the container first:" >&2
    echo "  apt-get update && apt-get install -y cron && systemctl enable --now cron" >&2
    exit 1
fi

# ── Sanity-check the PHP scripts exist ──────────────────────────
for s in process_sync_queue.php reconcile_square.php backup_db.php check_db.php; do
    if [ ! -f "$SCRIPT_DIR/$s" ]; then
        echo "ERROR: $SCRIPT_DIR/$s not found. Deploy the repo first." >&2
        exit 1
    fi
done

# ── Build the cron block (idempotent marker) ────────────────────
MARKER_OPEN="# >>> pinksheet scheduled tasks (managed by setup_linux_cron.sh) >>>"
MARKER_CLOSE="# <<< pinksheet scheduled tasks <<<"

BLOCK="$MARKER_OPEN
# Sync queue worker — pushes queued Square catalog updates (runs every 2 minutes)
*/2 * * * * $PHP_BIN $SCRIPT_DIR/process_sync_queue.php --limit=20 >> $PROJECT_ROOT/logs/cron_sync_queue.log 2>&1
# Inventory reconciliation against Square (daily at 3:00 AM)
0 3 * * * $PHP_BIN $SCRIPT_DIR/reconcile_square.php >> $PROJECT_ROOT/logs/cron_reconcile.log 2>&1
# Nightly online-safe DB backup, 14-day retention (daily at 2:00 AM)
0 2 * * * $PHP_BIN $SCRIPT_DIR/backup_db.php >> $PROJECT_ROOT/logs/cron_backup.log 2>&1
# DB integrity check (daily at 8:00 AM)
0 8 * * * $PHP_BIN $SCRIPT_DIR/check_db.php $PROJECT_ROOT/data/intake.sqlite >> $PROJECT_ROOT/logs/cron_check_db.log 2>&1
$MARKER_CLOSE"

mkdir -p "$PROJECT_ROOT/logs"

# ── Merge into the user's crontab ───────────────────────────────
EXISTING="$(crontab -l 2>/dev/null || true)"
# Drop any previous managed block so re-runs replace, never duplicate.
FILTERED="$(printf '%s\n' "$EXISTING" | sed "/^$MARKER_OPEN$/,/^$MARKER_CLOSE$/d" | sed '/^$/N;/^\n$/D')"
NEW_CRON="$(printf '%s\n%s\n' "$FILTERED" "$BLOCK" | sed '/^$/d')"

printf '%s\n' "$NEW_CRON" | crontab -

echo ""
echo "[OK] Installed Pinksheet cron jobs:"
crontab -l | sed -n "/^$MARKER_OPEN$/,/^$MARKER_CLOSE$/p" | grep -v "^#" | sed 's/^/     /'
echo ""
echo "Verify with:  crontab -l"
echo "Logs:         $PROJECT_ROOT/logs/cron_*.log"
echo ""
echo "Test the queue worker once now:"
echo "  $PHP_BIN $SCRIPT_DIR/process_sync_queue.php --limit=5"
