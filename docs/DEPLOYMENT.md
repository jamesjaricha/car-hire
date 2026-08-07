# Deployment

Target: **20i** shared/managed cloud. Nothing has been deployed yet — this
records what is known and what must be settled first.

---

## Unresolved before launch

### The `TRIGGER` privilege

`audit_log` immutability depends on two MySQL triggers created by
`2026_08_03_000007_create_audit_log_table.php`. They are confirmed working on
MySQL 8.4.3 locally.

Shared hosting does not always grant the `TRIGGER` privilege to database users.
If 20i refuses to create them, the migration will fail, and the append-only
guarantee falls back to the application-level check in `AuditLogEntry` — which
is materially weaker than spec §12 requires.

**Check this early.** On an SSH session:

```bash
mysql -u <user> -p -e "SHOW GRANTS;"
```

Look for `TRIGGER` in the grant list. If it is absent, that is a decision to
take to the business before launch, not a thing to work around quietly.

After migrating, confirm the triggers exist:

```bash
mysql -u <user> -p -e "SHOW TRIGGERS FROM <database>;"
```

Both `audit_log_block_update` and `audit_log_block_delete` must be listed.

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

- [ ] `TRIGGER` privilege confirmed and both triggers present
- [ ] Every blocking item in [OPEN-ITEMS.md](OPEN-ITEMS.md) answered — no
      placeholder settings remain
- [ ] Cron confirmed running, and `carhire:expire-bookings` observed cancelling
      a real expired booking — not merely present in `schedule:list`
- [ ] Somebody named as responsible for reading the part-paid warning, or a
      screen built for it
- [ ] Backups scheduled and a restore tested, not just assumed
- [ ] Full test suite green against MySQL
