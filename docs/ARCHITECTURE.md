# Architecture

Why the system is built this way. If you only read one section, read the first.

---

## 1. How double-booking is prevented

This is the design decision everything else bends around.

### The problem

Checking whether a vehicle is free and then claiming it is a read-modify-write.
Without a lock, that is a race. Two customers reaching checkout on the last
Hilux within the same few milliseconds will both be told it is free, and both
will take it. Application-level checks lose this race every time; they just lose
it rarely enough that it survives testing and surfaces in production.

### What we do

`VehicleHoldService::place()` is the only code permitted to write to
`vehicle_holds`. It does four things in a strict order, inside one transaction:

1. **Take a row lock on the vehicle** with `lockForUpdate()`. Nothing about
   availability is read before this line, because anything read before it is
   already stale.
2. **Retire that vehicle's lapsed holds**, so the unique index and the
   availability query cannot disagree about what is still claimed.
3. **Check for overlaps**, inside the lock. A check performed before the lock
   proves nothing.
4. **Insert.**

Concurrent attempts on the same vehicle serialise at step 1. The second request
waits until the first commits, then re-runs its own check and sees the hold that
was just written.

### The honest caveat

PostgreSQL could express this as an exclusion constraint over a time range —
`EXCLUDE USING gist (vehicle_id WITH =, tstzrange(start_at, end_at) WITH &&)` —
and the database would then refuse an overlapping row no matter what the
application did. That is a structural guarantee.

We are on MySQL, because production is shared hosting. MySQL has no
equivalent. So our guarantee is **behavioural**: it holds exactly as long as
every writer goes through `place()`. Three things defend that:

- The service is the documented single writer, and the model says so too.
- A unique index on `(vehicle_id, start_at, end_at, is_active)` catches an
  identical duplicate at the database level. MySQL ignores NULLs in unique
  indexes, so released holds — which set `is_active` to NULL — drop out of the
  constraint automatically. **This catches exact duplicates only, not partial
  overlaps.** It is a second net, not the mechanism.
- `VehicleHoldConcurrencyTest` runs six real processes at a shared barrier and
  asserts exactly one wins. If someone later adds a second write path, that test
  is what catches it.

If the platform ever moves to PostgreSQL, replace the behavioural guarantee with
the constraint and keep the test.

### The isolation level is part of the mechanism

The MySQL connection runs at **READ COMMITTED**, not InnoDB's `REPEATABLE READ`
default. This is set in `config/database.php` and it is not a performance tweak
— the booking engine is incorrect without it, in two separate ways. Both were
observed in `BookingConcurrencyTest`, not theorised.

**Stale reads.** Under `REPEATABLE READ` a transaction's snapshot is fixed at
its *first* read. Creating a booking reads payment methods, settings, the
vehicle class and the customer before it ever reaches the vehicle lock, so its
view of `vehicle_holds` is already old. Blocking on the lock does not refresh
it. The overlap check therefore consulted a view of the table from before the
winning transaction committed, found nothing, and inserted a second hold over
the same dates. Four of five racing processes did exactly this; only the unique
index stopped them, and it would not have if the ranges had merely overlapped
instead of matching exactly.

**Gap locks.** `REPEATABLE READ` takes next-key locks on range scans. On a
sparsely populated `vehicle_holds` those gaps span most of the index, so
transactions working on entirely different vehicles lock one another out and
deadlock when they insert. That failure is worst when the table is nearly
empty — which is week one of go-live, not week fifty.

**What this does not change:** two customers competing for the same vehicle are
still serialised by the explicit `lockForUpdate()` on the vehicle row. That is
unaffected by isolation level. "We relaxed the isolation level" reads like a
weakening; here it is the opposite.

### Availability results are advisory

`AvailabilityService` answers "which vehicles look free". It does **not**
reserve anything. Between a search result and a hold being placed, another
customer can take the vehicle. Treating an availability result as a promise is
precisely how double-bookings get reintroduced. Only `place()` decides.

---

## 2. Overlap and the turnaround buffer

Hire windows are **half-open**: `[start, end)`. A hire ending at 10:00 and one
starting at 10:00 do not overlap. Closed ranges make every boundary comparison
ambiguous.

Vehicles need time between hires for cleaning, refuelling and inspection — two
hours by default, configurable per vehicle class. This is applied by padding
**both ends** of the requested window before testing it against existing holds:

```
existing.start_at < (requested.end + buffer)
AND existing.end_at > (requested.start - buffer)
```

Padding both ends guarantees at least the buffer's worth of clear time between
any two hires, whichever order they fall in. A hire ending at 10:00 blocks a
new hire starting at 11:00, and permits one starting at 12:00.

`AvailabilityService` groups candidate vehicles by their class's buffer and runs
one query per distinct value. This keeps the date arithmetic in PHP rather than
raw SQL, and keeps those comparisons byte-identical to the single-vehicle check
— which is what stops the two drifting apart. A test asserts they agree.

---

## 3. Hold expiry, and why it self-heals

A hold lapses when its payment deadline passes. A scheduled sweep releases them
and notifies the customer.

The guideline warns that if that job dies unnoticed, vehicles stay locked and
inventory silently disappears. Two mitigations:

- The availability query treats a hold as claiming its vehicle only while it is
  **unreleased *and* not past its deadline**. A lapsed hold stops blocking
  immediately, whether or not the sweep has run.
- `place()` retires that vehicle's lapsed holds inside the lock before checking
  overlaps.

So the worst case of a dead scheduler is stale rows in a table, not vehicles
vanishing from sale. A manual "release expired holds" admin action is still
required, per the guideline.

---

## 4. Money

`DECIMAL(12,2)` in the database, bcmath strings in PHP, at scale 2. Never
`FLOAT`, never `DOUBLE`, and never cast to float in between.

Values arriving from SQL or a form are unscaled — `'300'` rather than
`'300.00'`. Every value is normalised through `bcadd($value, '0', 2)` before it
is used or compared. Skipping that yields arithmetic that is numerically correct
but fails exact string assertions, which is how the problem usually gets
noticed: late, and confusingly.

Tests assert money with `assertSame` on strings, never float comparison.

### The two deposits

The specification calls conflating these the single most likely misreading.

| | What it is | Where it lives |
|---|---|---|
| **Booking deposit** | 50% part-payment of the hire, paid online to secure the booking | percentage in settings; amount on the booking |
| **Security deposit** | Refundable cash taken at the counter against damage | `security_deposit_amount`, per vehicle class |

Different columns, different lifecycles, different UI labels. Keep them apart.

---

## 5. Time

Every instant is stored in **UTC**. Everything is displayed in
**`Africa/Lusaka`** — UTC+2, no daylight saving, which makes the conversion
lossless. Conversion happens at the edge, never in the domain.

The case worth remembering, from the guideline: a booking created at 23:30 CAT
for a pickup the following morning. 23:30 in Lusaka is 21:30 UTC *the same day*.
There is a test for it.

---

## 6. The audit trail

`audit_log` is append-only, and that is enforced by the database:
`BEFORE UPDATE` and `BEFORE DELETE` triggers that raise
`SQLSTATE '45000'` unconditionally. The guideline's reasoning is blunt and
correct — if the ORM can edit it, it will eventually be edited.

The `AuditLogEntry` model *also* refuses updates and deletes. That is
convenience, not the guarantee: it gives a clear domain exception instead of a
raw SQL error, and it fails loudly in development if the triggers were somehow
never installed. The test that matters bypasses the model and issues raw SQL.

There is no `updated_at` column. A row that can be updated is not an audit
record. Correcting a mistaken entry means appending a correcting entry.

---

## 7. Configuration over constants

Every value the business might change — the admin fee, the deposit percentage,
the short-notice threshold, KYC rules, fuel and mileage policy — lives in the
`settings` table, not in a constant. None of them require a deploy to change.

Values the specification does not yet answer are seeded as **placeholders** and
flagged, so they surface in the admin panel and in `OPEN-ITEMS.md` rather than
passing silently as though they had been decided. `SettingsSeeder` uses
`firstOrCreate`, so re-running it never overwrites a real decision with a
placeholder.

---

## 8. The multi-operator seam

The commercial plan is to open the platform to other Zambian operators once it
is proven on the house fleet. So `operators` exists now, and `operator_id` sits
on branches, vehicles and classes from day one.

What deliberately does **not** exist yet: global scopes, per-operator
permissions, commission tracking, any UI. Those belong with the multi-operator
work itself. The point of doing this now is only to avoid a schema migration
across every core table, on live booking data, later.

---

## 9. Layering

```
Console command / HTTP controller / Filament page
        ↓  (thin — no business logic)
Service (bound to a Contract in AppServiceProvider)
        ↓
Eloquent model
```

- Business logic lives in services, never in controllers or models.
- Services are bound to interfaces so callers depend on behaviour, not classes.
- DTOs (`DateRange`) carry validated values across boundaries.
- Models are Eloquent and little else: relations, casts, scopes.
- `declare(strict_types=1)` everywhere; classes are `final` unless there is a
  reason not to be.

---

## 10. Who may do what

Specification §12 is a table of fifteen permissions against three roles. It is
transcribed twice, on purpose: once as `StaffPermission` and `StaffRole`, and
once by hand in `RolesAndPermissionsSeederTest`. The test does not derive its
expectations from the enums, because a test that reads the code it is checking
agrees with that code no matter what the code says.

### The seeder is the authority, not the database

Grants are applied with `syncPermissions()`. Re-running the seeder restores the
§12 matrix exactly and revokes anything granted to those three roles by other
means. The alternative — seeding additively — lets the permission set in a live
database drift away from the reviewed matrix with nothing to notice, and the
direction it drifts is always outward. An operator who needs a different
combination gets a **new role**; they do not get an edited Counter Clerk.

### Wildcards are off

`payments.*` would be convenient and is exactly wrong here. §12 separates
confirming cash from confirming a bank transfer because a counter clerk may do
the first and not the second — verifying a transfer means reading a statement
they cannot see. A wildcard erases that distinction silently.

### Permissions are checked in the service, not only at the edge

`hasPermissionTo()` is asserted inside the service that performs the action,
and a policy holds the same matrix for the admin panel to reuse. Authorising
only in a controller or a Filament page means the guarantee lasts exactly as
long as nobody calls the service from a command, a job or a test — and the
expiry sweep is already a command.

Note that `hasPermissionTo()` throws when a permission is absent from the table
rather than returning false. That is wanted: a missing permission row is a
deployment fault, and a silent denial would be read as a role misconfiguration
and sent to the wrong person to fix.

### The permission cache and `WithoutModelEvents`

`PermissionRegistrar` keeps its permissions in memory and reloads them only when
told to, normally by model events. `DatabaseSeeder` suppresses model events for
the whole run. Anything that seeds or checks permissions under that seeder must
therefore not read through the registrar's cache — `RolesAndPermissionsSeeder`
uses direct queries and passes model instances rather than names. The failure
mode otherwise is a `PermissionDoesNotExist` for a permission that is visibly
present in the table. See CHANGELOG.md, Phase 3.
