#!/usr/bin/env bash
#
# Deploy to 20i. Run from an SSH session:
#
#     ~/public_html/deploy.sh
#
# ---------------------------------------------------------------------------
# WHY EVERY PATH HERE IS ABSOLUTE
#
# On 20i, `composer` is a shell ALIAS defined in ~/.bashrc:
#
#     alias composer='/usr/php84/usr/bin/php /usr/local/bin/composer'
#
# Aliases load only in INTERACTIVE shells. This script is not one, so the alias
# is invisible and a bare `composer` falls through to /usr/local/bin/composer,
# which hard-codes PHP 8.0.30 and refuses the Laravel 13 dependency tree. The
# symptom is dozens of composer errors all blaming the PHP version while `php -v`
# in the same session correctly reports 8.4 — so pin both, and never call the
# bare commands below.
#
# ⚠ THIS SCRIPT UPDATES ITSELF AT STEP 1
#
# Bash reads the whole file into memory before executing, so the first run after
# pushing a change to this file still executes the OLD version. If you change
# deploy.sh, expect to run it TWICE — or `git pull` by hand first.
# ---------------------------------------------------------------------------

set -euo pipefail

PHP=/usr/php84/usr/bin/php
COMPOSER="$PHP /usr/local/bin/composer"

cd "$(dirname "$0")"

# nvm, sourced explicitly for the same non-interactive reason as composer.
# 20i ships Node 16, which modern Vite refuses.
export NVM_DIR="$HOME/.nvm"
# shellcheck disable=SC1091
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$1"; }
fail() { printf '\n\033[1;31mFAILED: %s\033[0m\n' "$1"; exit 1; }

# ---------------------------------------------------------------------------
step 'Reading database credentials from .env'
# ---------------------------------------------------------------------------
env_value() {
    grep -E "^$1=" .env | head -n1 | cut -d= -f2- | tr -d '"' | tr -d "'"
}

[ -f .env ] || fail '.env is missing. Create it before deploying.'

DB_NAME="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
DB_PASS="$(env_value DB_PASSWORD)"

[ -n "$DB_NAME" ] || fail 'DB_DATABASE is empty in .env'

# ---------------------------------------------------------------------------
step 'Backing up the database'
# ---------------------------------------------------------------------------
# DEPLOYMENT.md is explicit: dump every time, and verify the file is non-zero.
# The backup happens BEFORE migrations because migrations are the only
# destructive step, and a backup taken after them is worthless.
mkdir -p "$HOME/backups"
BACKUP="$HOME/backups/${DB_NAME}-$(date +%Y%m%d-%H%M%S).sql"

if command -v mysqldump >/dev/null 2>&1; then
    mysqldump --user="$DB_USER" --password="$DB_PASS" --single-transaction \
        --routines --triggers "$DB_NAME" > "$BACKUP" 2>/dev/null \
        || fail "mysqldump failed. Refusing to migrate without a backup."

    [ -s "$BACKUP" ] || fail "Backup $BACKUP is empty. Refusing to migrate."

    echo "Backup written: $BACKUP ($(du -h "$BACKUP" | cut -f1))"

    # Keep the last 20. Shared hosting quotas are not generous.
    ls -1t "$HOME/backups/${DB_NAME}"-*.sql 2>/dev/null | tail -n +21 | xargs -r rm --
else
    fail 'mysqldump not found. Take a backup via StackCP before deploying.'
fi

# ---------------------------------------------------------------------------
step 'Pulling from GitHub'
# ---------------------------------------------------------------------------
# --ff-only so a dirty or diverged server tree fails loudly rather than
# producing a merge commit nobody will ever see.
git pull --ff-only origin master

# ---------------------------------------------------------------------------
step 'Installing PHP dependencies'
# ---------------------------------------------------------------------------
# post-autoload-dump runs `artisan filament:upgrade`, which republishes
# Filament's CSS, JS and fonts into public/. Those are gitignored on purpose, so
# skipping this step leaves the admin panel unstyled — which looks like a broken
# deploy rather than a missing publish.
$COMPOSER install --no-dev --optimize-autoloader --no-interaction

# ---------------------------------------------------------------------------
step 'Building front-end assets'
# ---------------------------------------------------------------------------
# public/build is gitignored, so this is not optional: without it the site
# serves no CSS at all.
if ! command -v npm >/dev/null 2>&1; then
    fail 'npm not found. Install nvm + Node 22 — see docs/DEPLOYMENT.md.'
fi

npm ci --no-audit --no-fund
npm run build

# ---------------------------------------------------------------------------
step 'Creating storage directories'
# ---------------------------------------------------------------------------
# Gitignored, so absent on a fresh clone. view:cache fails without them, and
# caching config before they exist bakes in empty resolved paths.
mkdir -p storage/framework/views \
         storage/framework/cache/data \
         storage/framework/sessions \
         storage/logs
chmod -R 775 storage bootstrap/cache

# storage:link errors if the link already exists, so only make it once.
[ -L public/storage ] || $PHP artisan storage:link

# ---------------------------------------------------------------------------
step 'Running migrations'
# ---------------------------------------------------------------------------
$PHP artisan migrate --force

# ---------------------------------------------------------------------------
step 'Checking the audit_log append-only guarantee'
# ---------------------------------------------------------------------------
# Reported on EVERY deploy on purpose. On the current host the TRIGGER privilege
# is absent, so audit_log immutability is enforced only by the model — weaker
# than spec 12 requires. That is disclosed and tracked in OPEN-ITEMS.md, and it
# is reversible the day the host grants the privilege.
#
# `|| true` because this must not abort a deploy: the state is known, and a
# release failing for a condition that was already true yesterday teaches people
# to ignore the output. The warning is loud; the exit code is not fatal here.
$PHP artisan carhire:install-audit-triggers || true

# ---------------------------------------------------------------------------
step 'Syncing roles and permissions'
# ---------------------------------------------------------------------------
# Idempotent, and authoritative for the three seeded roles. Run on EVERY deploy
# on purpose: hasPermissionTo() throws PermissionDoesNotExist for a permission
# missing from the table rather than returning false, so a release that adds a
# StaffPermission case breaks the panel until this runs. Making it automatic
# means that can never be the thing somebody forgot.
$PHP artisan db:seed --class=RolesAndPermissionsSeeder --force

# ---------------------------------------------------------------------------
step 'Rebuilding caches'
# ---------------------------------------------------------------------------
# optimize:clear as one command rather than separate view:clear / route:clear /
# config:clear lines: pasted multi-line blocks have fused lines on this host
# before, producing "route:clearcd" and silently skipping the clear.
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

step 'Deployed'
$PHP artisan --version
echo "Backup for this release: $BACKUP"
