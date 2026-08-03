# Open Items

Values the business must decide before go-live. Spec §15 calls these
"must be answered before build starts"; in practice most of them are figures
rather than architecture, so the platform treats every one as configuration
seeded with a placeholder. Nothing here blocks development, but **none of it may
still be a placeholder when the platform takes real money.**

Anything marked PLACEHOLDER is flagged in the `settings` table
(`is_placeholder = true`) and will be listed in the admin panel, so this file
and the running system cannot drift apart.

Last reviewed: 2026-08-03 (end of Phase 1).

---

## Blocking before launch

| # | Item | Where it lives | Status |
|---|---|---|---|
| 1 | Flat admin fee (ZMW), deducted from refunds | `settings.admin_fee_amount` | **PLACEHOLDER** `0.00` |
| 2 | Security deposit per vehicle class (ZMW) | `vehicle_classes.security_deposit_amount` | **PLACEHOLDER** per class |
| 3 | Insurance price per class, and per-day vs flat | `vehicle_classes.insurance_price` / `insurance_price_mode` | **PLACEHOLDER** per class |
| 4 | Insurance excess the customer remains liable for | `vehicle_classes.insurance_excess_amount` | **PLACEHOLDER** per class |
| 5 | Accepted KYC documents, minimum driver age, minimum licence years, foreign licence policy | `settings.minimum_driver_age`, `minimum_licence_years`, `foreign_licence_accepted` | **PLACEHOLDER** |
| 6 | Cross-border: supported countries, price and document checklist per country | Not yet modelled — due in the cross-border phase | Outstanding |
| 7 | SMS provider and registered sender ID | `settings.sms_provider`, `sms_sender_id` | **PLACEHOLDER**, empty |
| 8 | Branch list, operating hours, after-hours pickup policy | `branches` table | **PLACEHOLDER** — two demo branches seeded locally |
| 9 | Fuel policy (full-to-full? charged shortfall rate?) | `settings.fuel_policy` | **PLACEHOLDER** `full_to_full` |
| 10 | Mileage policy (unlimited? daily cap and excess rate?) | `settings.mileage_policy` | **PLACEHOLDER** `unlimited` |
| 11 | Late return charge rate | `settings.late_return_hourly_charge` | **PLACEHOLDER** `0.00` |
| 12 | Whether counter clerks may confirm cash, per branch | `settings.counter_clerk_may_confirm_cash` | **PLACEHOLDER** `false`; per-branch override due with roles |

## Settled

| Item | Decision | Source |
|---|---|---|
| Booking deposit percentage | 50% | Spec §5 |
| Short-notice threshold | 4 hours | Spec §8.2 |
| Deadline margin before pickup | 2 hours | Spec §8.2 |
| Basket lifetime | 30 minutes | Spec §1.1 |
| Payment reminder trigger | 25% of hold window remaining | Spec §8.4 |
| Turnaround buffer between hires | 2 hours, per class | Confirmed with the operator, 2026-08-03 |
| One-way hires | Allowed by staff arrangement only; no automatic vehicle relocation | Confirmed with the operator, 2026-08-03 |
| Pricing level | Class sets rate and deposit; individual vehicles may override | Confirmed with the operator, 2026-08-03 |
| Currency | ZMW only | Spec header |

---

## Technical risks carried forward

**`TRIGGER` privilege on production.** `audit_log` immutability is enforced by
MySQL `BEFORE UPDATE` and `BEFORE DELETE` triggers. These are confirmed working
locally on MySQL 8.4.3. Shared hosting does not always grant the `TRIGGER`
privilege. If production refuses to create them, the guarantee falls back to an
application-level check, which is weaker than spec §12 requires. **Verify on the
production database before launch, not after.**

**Hold expiry depends on the scheduler.** A dead expiry job would normally lock
vehicles out of sale indefinitely. Mitigated two ways: the availability query
ignores holds whose deadline has passed, and placing a hold retires that
vehicle's lapsed holds first. A manual "release expired holds" admin action is
still required, per the developer guideline.

**Double-booking prevention is behavioural, not structural.** On PostgreSQL this
would be an exclusion constraint the database enforces regardless of application
code. On MySQL it is a row lock taken inside `VehicleHoldService::place()`, which
is correct only while that remains the sole writer to `vehicle_holds`. The
concurrency test exists to keep it that way.
