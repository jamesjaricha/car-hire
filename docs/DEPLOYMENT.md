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

There is no daemonised queue worker on shared hosting. Both of these need cron:

```
* * * * * cd ~/path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler drives hold expiry. **If it stops, vehicles stay claimed and
inventory disappears from sale.** Two mitigations already exist in the
application — the availability query ignores holds past their deadline, and
placing a hold retires lapsed ones — but a manual "release expired holds" admin
action is still required, and someone should be watching that the cron runs.

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
- [ ] Cron confirmed running, and hold expiry observed working
- [ ] Backups scheduled and a restore tested, not just assumed
- [ ] Full test suite green against MySQL
