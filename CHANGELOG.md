# Changelog

## 1.6.0 - 2026-09-14

- Require Jyavani Core 2.3.122 and exact method-aware routes.
- Replace runtime DDL and layout migration-on-read with immutable plugin migrations.
- Add stable per-form references, idempotency, provenance, workflow status, timestamps, notes/history, indexes, and an import ledger.
- Harden anonymous submissions, private uploads/downloads, rate limits, CSV exports, CSRF, and post-commit observers.
- Use the Core Mail API for administrator and applicant notifications.
- Add configurable workflow transitions, filters, bulk actions, and append-only reviewer notes.
- Add bounded atomic JSON definition export/upsert with en/id/de locale overlays.
- Add the complete `international-signup` application contract, localized public validation/UI and email templates.
- Add an independent idempotent legacy-submission import API and source ledger.
- Resolve private storage from the exact Core plugin root and retain constrained storage relocation support.
- Add Theme Section and Theme Zone embedding, unique repeated-form IDs, and unsafe-code capability gates.
- Add complete-uninstall containment checks, release documentation, behavior contracts, and deterministic packaging.

## 1.5.2

- Previous compatible release baseline.
