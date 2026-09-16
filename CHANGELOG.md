# Changelog

## Unreleased

- Safely provision the private Form Builder upload namespace, reject non-writable storage before staging, and avoid touching upload storage for submissions without files.

## 1.7.3 - 2026-09-15

- Use a compact three-dot ellipsis for row overflow actions instead of a hamburger icon.

## 1.7.2 - 2026-09-14

- Keep country option labels focused on country names while retaining searchable calling-code metadata for linked phone fields.
- Improve the submissions workspace hierarchy, filters, tables, bulk controls, and responsive layout.
- Replace overflow-menu text symbols with Core-provided Lucide icons.

## 1.7.1 - 2026-09-14

- Correct the Store metadata URL used for future update discovery.

## 1.7.0 - 2026-09-14

- Add a searchable, bundled ISO 3166-1 country field that stores uppercase alpha-2 codes.
- Add a country-linked international phone field with server-side E.164 normalization.
- Accept configurable normalized locale identifiers instead of a fixed locale allowlist.
- Replace fixed price formatting with a configurable ISO 4217 currency code.
- Keep project-owned form definitions outside the reusable plugin package.

## 1.6.1 - 2026-09-14

- Preserve the rendered locale through public submissions with a locale-bound signed start token, keeping validation, provenance, and notification emails localized.

## 1.6.0 - 2026-09-14

- Require Jyavani Core 2.3.122 and exact method-aware routes.
- Replace runtime DDL and layout migration-on-read with immutable plugin migrations.
- Add stable per-form references, idempotency, provenance, workflow status, timestamps, notes/history, indexes, and an import ledger.
- Harden anonymous submissions, private uploads/downloads, rate limits, CSV exports, CSRF, and post-commit observers.
- Use the Core Mail API for administrator and applicant notifications.
- Add configurable workflow transitions, filters, bulk actions, and append-only reviewer notes.
- Add bounded atomic JSON definition export/upsert with locale overlays.
- Add an independent idempotent legacy-submission import API and source ledger.
- Resolve private storage from the exact Core plugin root and retain constrained storage relocation support.
- Add Theme Section and Theme Zone embedding, unique repeated-form IDs, and unsafe-code capability gates.
- Add complete-uninstall containment checks, release documentation, behavior contracts, and deterministic packaging.

## 1.5.2

- Previous compatible release baseline.
