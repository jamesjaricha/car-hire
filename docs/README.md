# Documentation

Start here.

## For someone new to the project

Read in this order:

1. **[DEVELOPER_GUIDE.md](DEVELOPER_GUIDE.md)** — get it running, learn the
   conventions. Read this before writing any code.
2. **[ARCHITECTURE.md](ARCHITECTURE.md)** — how the booking engine works, and
   more importantly *why* it is built the way it is. The double-booking
   prevention section is the part that matters most.
3. **[TREEVIEW.md](TREEVIEW.md)** — where everything lives.

## For someone picking up in-flight work

- **[CHANGELOG.md](CHANGELOG.md)** — what has shipped and when.
- **[OPEN-ITEMS.md](OPEN-ITEMS.md)** — business decisions still outstanding.
  Nothing goes live while items in the blocking table are still placeholders.

## For someone deploying

- **[DEPLOYMENT.md](DEPLOYMENT.md)** — the 20i runbook, and the one privilege
  question that must be answered before launch.

## Source specifications

This build implements two documents supplied by the business, which are the
authority on requirements:

- **Requirements Specification v2** — what the platform must do. Section
  references throughout the code (`spec §8.2`, `spec §15.1`) point here.
- **Developer Guideline** — the non-negotiables, build order and known failure
  modes. Referenced as "the guideline".

Where this documentation and those specifications disagree, the specifications
win — and the disagreement is a bug in these notes worth fixing.

## Conventions in these documents

- **British English** throughout, in prose and code comments.
- Money is always written with its currency: `ZMW 1,250.00`.
- Times are stored UTC and displayed in `Africa/Lusaka` (UTC+2, no daylight
  saving). Where a document says a local time, it says so explicitly.
