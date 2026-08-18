# Deployment

Target: **20i** shared/managed cloud.

**First deployment: a client demonstration site**, decided 2026-08-13. Real
`APP_ENV=production`, demo fleet seeded explicitly, publicly reachable. That
combination is deliberate and its consequences are written down under
"The demo deployment" below — read that before assuming this is a live launch.

---

## First deploy — the order that matters

Generic 20i steps (package, document root, PHP version, SSH keys, nvm) live in
the platform runbook. What follows is only what is specific to **this** app.
`deploy.sh` at the project root automates every subsequent release.

### 1. Check the `TRIGGER` privilege BEFORE anything else

Cheapest moment to find out is before the database has anything in it.

```bash
mysql -h 127.0.0.1 -u <user> -p -e "SHOW GRANTS;"
```

**On the current package (`pule.jarichatech.com`) `TRIGGER` is ABSENT** —
confirmed 2026-08-14. The migration tolerates that: it prints a loud warning and
continues, rather than failing and leaving a half-migrated database. After
deploying, confirm the state deliberately rather than assuming:

```bash
php artisan carhire:install-audit-triggers --check
```

Exit 1 means `audit_log` is not protected at the database level. That is the
current, known, disclosed state — see OPEN-ITEMS.md. It is **blocking real
launch**, and reversible with one command the day the host grants the privilege.

### 2. `.env`

Copy from `.env.example` and change these. `DB_ISOLATION_LEVEL` is the one
nobody would guess at:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<domain>
APP_TIMEZONE=UTC

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=<from StackCP>
DB_USERNAME=<from StackCP>
DB_PASSWORD=<from StackCP>

# LOAD-BEARING. The booking engine is INCORRECT under InnoDB's REPEATABLE READ
# default — see config/database.php and ARCHITECTURE section 1.
DB_ISOLATION_LEVEL="READ COMMITTED"

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

CARHIRE_DISPLAY_TIMEZONE=Africa/Lusaka
CARHIRE_CURRENCY=ZMW
```

Then `php artisan key:generate --force`.

### 3. Run the deploy script

```bash
chmod +x ~/public_html/deploy.sh
~/public_html/deploy.sh
```

It backs up the database, pulls, installs, builds assets, creates the storage
directories and symlink, migrates, syncs permissions and rebuilds caches. It
refuses to migrate without a verified non-empty backup.

### 4. Verify the isolation level actually took

A managed host that pins the isolation level would reintroduce both concurrency
failures **silently** — no error, just double bookings under load.

```bash
mysql -u <user> -p -e "SELECT @@transaction_isolation;"
```

Expect `READ-COMMITTED`. If it reports `REPEATABLE-READ`, the session setting is
being ignored or overridden; stop and resolve it with 20i before taking bookings.

### 5. Confirm the audit trigger state

```bash
php artisan carhire:install-audit-triggers --check
```

Exit 0 means both triggers are present and §12 is satisfied. Exit 1 means they
are not, which is the expected result on this package today. **The migration
succeeding is not proof** — it is allowed to continue without them.

### 6. Seed

The three production-safe seeders, in this order:

```bash
php artisan db:seed --class=SettingsSeeder --force
php artisan db:seed --class=PaymentMethodSeeder --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
```

`SettingsSeeder` uses `firstOrCreate`, so re-running never overwrites a real
decision with a placeholder. `PaymentMethodSeeder` deliberately seeds **no**
account details — see below.

### 7. Create a Super Admin

`DemoStaffSeeder` **throws** outside `local` on purpose: it creates an admin with
the password `password`. So the first account is made by hand.

```bash
php artisan tinker
```

```php
$user = App\Models\User::create([
    'name' => 'James Jaricha',
    'email' => 'you@example.com',
    'password' => Hash::make('<a long random password>'),
]);
$user->syncRoles([App\Enums\StaffRole::SuperAdmin->value]);
exit
```

`syncRoles` rather than `assignRole`, matching the seeder. Without a recognised
role `User::canAccessPanel()` refuses the panel — which is the gate working, not
a fault.

### 8. Register the scheduler

StackCP → **Scheduled Tasks**, every minute. Not `crontab -e`; on this host
`crontab -l` reports nothing even while a schedule is firing.

```
/usr/php84/usr/bin/php /home/virtual/vps-<box>/<n>/<user>/public_html/artisan schedule:run
```

Do not paste that into an SSH prompt — the leading path is fine but it is a cron
entry, and `schedule:run` alone does nothing useful interactively.

### 9. Smoke test

- `https://<domain>/` — the search form, and the fleet as class cards
- `https://<domain>/classes/economy` — the class page
- `https://<domain>/admin` — sign in with the account from step 7
- `tail -50 storage/logs/laravel.log` — should be empty

---

## The demo deployment

Decided 2026-08-13, and recorded because each part has a consequence somebody
will otherwise meet by surprise.

**`APP_ENV=production` with a demo fleet.** `DemoFleetSeeder` has no environment
guard of its own — only `DatabaseSeeder`'s call to it is gated on `local` — so it
can be run explicitly in production:

```bash
php artisan db:seed --class=DemoFleetSeeder --force
```

Six classes, eighteen vehicles, two branches, **every figure invented**. It is
idempotent and keyed on slug and registration, so the operator can edit or
replace any of it in the panel without a later run overwriting the changes.

**Cash is the only payment method a customer will be offered.**
`DemoPaymentDetailsSeeder` refuses to run in production, by design — seeding
plausible bank details on a live site would tell customers to send money to an
account belonging to nobody. Since 2026-08-09 a method with no account details
is withheld from checkout, so bank transfer and both mobile money options simply
do not appear. **That is correct, and it will look like a fault to anyone who
does not know why.** It resolves when the operator enters real details at
`/admin` → Payment methods.

**The site is publicly reachable and indexable**, on the operator's instruction.
Two things follow. A passer-by can create a real booking against invented
pricing, and nothing notifies anybody — notifications are Phase 6, so it would
sit in the database until somebody looked. And a search engine may index
placeholder rates under the client's domain, which is awkward to unpick if that
domain later becomes the real site. The footer says "Demonstration site"; that is
the whole of the mitigation.

**No §15 figure has been answered.** The flat admin fee is still `0.00` and is
applied to real refunds. Every blocking item in [OPEN-ITEMS.md](OPEN-ITEMS.md)
is still open. This deployment is for the operator to look at, and the checklist
under "Before the first real booking" is what separates it from a live one.

---

## Unresolved before launch

### ✅ The `TRIGGER` privilege — GRANTED, and the triggers are installed

**Resolved 2026-08-17.** `SHOW GRANTS` now lists `TRIGGER` on
`bandap-353030303b9a`, and `carhire:install-audit-triggers` installed both
`audit_log_block_update` and `audit_log_block_delete`. Spec §12 is satisfied in
its strong form — `audit_log` immutability is enforced by the database, so raw
SQL and database clients cannot alter history either.

`deploy.sh` reports the state on every release. Confirm it rather than inferring
it from a deploy that exited zero:

```bash
php artisan carhire:install-audit-triggers --check
```

Exit 0 means both triggers are present.

### ⚠⚠ DO NOT CHANGE MySQL GRANTS ON A LIVE 20i SITE

**Getting that privilege caused a production outage on 2026-08-17**, and the
mechanism will do it again to anybody who does not know about it.

Applying grants through StackCP — or having 20i apply them — **resets the
database user's password on the host's side, without warning.** Every connection
then fails with:

```
ERROR 1045 Access denied for user 'james-8c41'@'localhost' (using password: YES)
```

from PHP and from the `mysql` client alike, and the site returns HTTP 500 on
every page. **Resetting the password yourself in StackCP does not recover it** —
neither the old password nor a newly set one connected. Only the host restoring
credentials worked. 20i confirmed the cause in writing.

If grants must change:

- Do it in a **maintenance window**, never while the site is being demonstrated.
- Ask support to apply the grant **and tell you the resulting password** in the
  same ticket.
- If using the dialog yourself: **grants first, password second, `.env` third** —
  the grant save is what resets the password, so any other order means editing
  `.env` twice and a second outage.
- **`php artisan config:clear` after editing `.env`**, or the cached config keeps
  serving the old credentials and the fix looks like it failed.
- **Afterwards, re-check `.env`.** A temporary password typed in during the
  incident will still be there once the host restores the account, and the site
  may be running on stale cached config — which detonates on the next
  `config:cache`, i.e. at the end of the next deploy.

### Diagnosing a database failure here

The `mysqldump` failure that exposed all this was blamed first on the missing
`TRIGGER` privilege (`--triggers` plausibly needs it) and then on a missing
`--host` (no host means socket, means `user@localhost`). **Both were mechanisms
reasoned from symptoms and both were wrong.**

What identified it was testing the application rather than the tool:

```bash
php artisan migrate:status | head -5
```

That failing with the app's own credentials proved the database was rejecting
them, which no `mysqldump` flag theory could explain. **When a database CLI tool
fails, test Laravel's own connection before theorising about the tool.**

---

## Environment

| Setting | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_TIMEZONE` | `UTC` — do not change |
| `CARHIRE_DISPLAY_TIMEZONE` | `Africa/Lusaka` |
| `DB_CONNECTION` | `mysql` |
| `QUEUE_CONNECTION` | `database` — no Redis on 20i |
| `CACHE_STORE` | `database` |
| `LOG_CHANNEL` | not `stderr` — it hides errors on this host |

`APP_TIMEZONE` must stay UTC. Every deadline, hold and audit timestamp is stored
in UTC and converted for display. Changing it corrupts the meaning of existing
rows.

---

## Document root

20i serves from `public_html`. A Laravel application must be served from its
`public/` directory — never from the project root, which would expose `.env`.
Confirm the document root **before** the first deploy; getting this wrong has
cost time on previous projects.

---

## Scheduled work

There is no daemonised queue worker on shared hosting. This needs cron:

```
* * * * * cd ~/path/to/app && php artisan schedule:run >> ~/logs/schedule.log 2>&1
```

Note the log destination. The usual `>> /dev/null 2>&1` is exactly what makes a
dying scheduler silent, which is the failure the developer guideline singles
out. Send it somewhere a person can read.

### What the scheduler runs

One entry, registered in `routes/console.php`:

| Command | Frequency | Does |
|---|---|---|
| `carhire:expire-bookings` | every 5 minutes | Cancels bookings whose payment deadline has passed, expires their payments, releases their holds, and sweeps up any other lapsed hold |

Five minutes rather than hourly because a lapsed deadline is a claim on a
vehicle: an hourly sweep keeps a car off sale for up to an hour after the claim
on it ended, which on a small fleet is a booking lost for nothing.

### If it stops

**Vehicles stay claimed and inventory disappears from sale**, and nothing
announces it. Three things soften that, and none of them replaces watching the
cron:

- The availability query ignores holds past their deadline, so a lapsed hold
  stops blocking sales whether or not the sweep has run.
- Placing a hold retires that vehicle's lapsed holds first.
- The command is safe to run by hand at any time, and safe to run twice:

```bash
cd ~/path/to/app && php artisan carhire:expire-bookings
```

That is the manual action the guideline asks for. It prints what it did, so it
is also the quickest way to check the sweep is working at all.

### The line to watch in its output

The command warns when part-paid bookings are sitting past their deadline:

```
N part-paid booking(s) are past their deadline and need a decision from staff.
```

These are deliberately **not** cancelled automatically — each is holding money
the customer has paid, and cancelling one unattended would strand real cash
against a cancelled booking with no refund record. They wait for a person. Until
the admin panel has a screen for them (see OPEN-ITEMS.md), this log line is the
only thing that will tell anyone they exist.

---

## Filament assets

Filament publishes its own CSS, JS and fonts into `public/`. Those directories
are **gitignored on purpose** — they are build output, they regenerate, and
committing them means a conflict on every Filament update.

They are republished automatically, because `filament:install` added this to
`composer.json`:

```json
"post-autoload-dump": [
    "@php artisan filament:upgrade"
]
```

That runs during `composer install`, which step 4 of the release procedure below
already does. **If a deploy ever skips `composer install`** — an rsync of a
prebuilt tree, say — the panel will load with no styling and no icons, and the
fix is:

```bash
php artisan filament:assets
```

Worth knowing the symptom, because an unstyled admin panel looks like a broken
deploy rather than a missing publish step.

---

## Release procedure

Once live, treat the production database as sacred.

1. `php artisan down`
2. **`mysqldump` and verify the file is non-zero.** Every time.
3. `git pull`
4. `composer install --no-dev --optimize-autoloader`
5. `php artisan migrate --force` — **only if the release contains migrations.**
   State explicitly in the release notes when it does not.
6. `php artisan config:cache route:cache view:cache` (clear first)
7. `npm run build` if front-end assets changed
8. `php artisan up`

Schema changes must be additive and reversible. No destructive operations
against live booking or payment data.

---

## Before the first real booking

- [x] `TRIGGER` privilege confirmed and both triggers present — **done
      2026-08-17**, verify again after any host-side database work
- [ ] Every blocking item in [OPEN-ITEMS.md](OPEN-ITEMS.md) answered — no
      placeholder settings remain
- [ ] Cron confirmed running, and `carhire:expire-bookings` observed cancelling
      a real expired booking — not merely present in `schedule:list`
- [ ] Somebody named as responsible for reading the part-paid warning, or a
      screen built for it
- [ ] Backups scheduled and a restore tested, not just assumed
- [ ] Full test suite green against MySQL
