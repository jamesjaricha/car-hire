# Car Hire Platform

A booking and fleet-operations platform for a Zambian car hire business, built
around how the market actually pays: mobile money, bank transfer and cash, with
staff verification, rather than card gateways.

**Status:** in development. Phase 1 (foundations) complete.

## What it does

Customers search, quote and book without an account — personal details are
requested only at final checkout. A booking places an exclusive hold on a
*specific* vehicle, secured with a 50% deposit, and staff confirm payment
manually against a bank or mobile money statement. Every consequential action
lands in an append-only audit trail.

## Stack

| | |
|---|---|
| Framework | Laravel 13.23 |
| PHP | 8.4 |
| Database | MySQL 8.4 |
| Admin | Filament |
| Tests | PHPUnit, running against MySQL |
| Local | Laragon → `carhire.test` |
| Production | 20i |

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan test
```

You need two MySQL databases: `carhire` and `carhire_test`. The suite runs
against real MySQL rather than SQLite — see the developer guide for why that is
not negotiable here.

## Documentation

Everything else lives in [`docs/`](docs/README.md):

| Document | What it covers |
|---|---|
| [Developer Guide](docs/DEVELOPER_GUIDE.md) | Setup, conventions, how to run things. **Read before writing code.** |
| [Architecture](docs/ARCHITECTURE.md) | How the booking engine works and why it is built this way |
| [Treeview](docs/TREEVIEW.md) | Annotated directory map |
| [Changelog](docs/CHANGELOG.md) | What shipped, when |
| [Open Items](docs/OPEN-ITEMS.md) | Business decisions still outstanding before launch |
| [Deployment](docs/DEPLOYMENT.md) | Getting it onto 20i |
