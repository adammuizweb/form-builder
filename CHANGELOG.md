# Changelog

## 2.4.1 - 2026-10-06

- Reorganize Visual Builder around an accent-aware, collapsible Layout summary with clearer row and column controls.
- Link draft Row, Column, and Empty Column markers to the matching layout controls and exact field insertion target.
- Add persistent Questions and Content accordions, keyboard-accessible structure markers, and responsive field guidance.
- Lock editing during publish and reset operations while preserving autosave, drag-and-drop, and public-render isolation.

## 2.4.0 - 2026-10-06

- Expand Visual Builder validation, linked Country, alignment, Image Block, Rich Text, and Raw HTML controls to match canonical field capabilities.
- Add safe field-key editing with stable identities, complete reference cascading, submission-history locks, Bin collision protection, and Classic Builder parity.
- Move fields removed by Visual publishing into the recoverable Bin while preserving surviving field IDs, submissions, and protected content.
- Validate Country and Date dependencies before Bin restore, cascade references in recoverable fields, and preserve numeric field maps across draft and submission JSON.

## 2.3.1 - 2026-10-06

- Use Core's available `panel-top` Lucide icon consistently for Classic Builder navigation, headings, and Visual Builder handoff actions.

## 2.3.0 - 2026-10-05

- Add a dedicated global Settings page for protected reCAPTCHA keys, definition import, and workspace/lifecycle documentation.
- Add Current and Archived form views with locked archive and draft-reactivation transitions while preserving form data and uploads.
- Render configured reCAPTCHA v2 widgets, close the final archive/submission race, and improve lifecycle guidance.
- Improve Form Builder headings and restore table-cell layout for Updated and date columns.

## 2.2.4 - 2026-10-04

- Persist Visual Builder upload descriptions from legacy empty settings and keep upload descriptions independent from help text.
- Use one configurable message for the success screen and downloadable proof, with optional dynamic field values and uppercase formatting.
- Clarify generic success-message settings while keeping project-specific copy outside the plugin.

## 2.2.3 - 2026-10-04

- Add configurable custom success notifications with localized visitor-facing values retrieved through short-lived signed success tokens.
- Add optional browser-generated PNG or multi-page PDF submission proofs without storing or transmitting another copy of submission data.
- Render sanitized rich or legacy plain upload guidance directly below the field label and keep upload-description sanitization idempotent.

## 2.2.2 - 2026-10-04

- Mount Visual Builder upload descriptions only after Core editor dependencies are ready and preserve mounted editors across back-forward cache navigation.
- Explain the independent bulk workflow-status action with the accessible Core field-help tooltip.
- Require Jyavani Core 2.3.140, the first release providing the scoped content-editor mount API used by upload descriptions.

## 2.2.1 - 2026-10-04

- Add files from successive picker or drop actions instead of replacing earlier valid multi-file selections.
- Show selected-file progress and provide an accessible remove control for each file, including uploads without visual previews.
- Preserve valid files when a later choice is invalid or exceeds the configured file-count limit.

## 2.2.0 - 2026-10-04

- Add Core-editor upload descriptions, configurable file counts, and `none`, `icon`, or safe real-image preview modes to Classic and Visual Builder.
- Validate, store, display, import, export, download, and remove multiple private attachments while preserving legacy single-file submissions.
- Export choice labels instead of internal values and add one session-protected Excel/CSV attachment link per configured upload slot.

## 2.1.3 - 2026-10-04

- Replace native browser confirmation dialogs across Form Builder administration with the accessible Core confirmation component.

## 2.1.2 - 2026-10-04

- Fix per-row submission Trash, Restore, and Delete actions being overridden by the bulk action selector.

## 2.1.1 - 2026-10-04

- Replace Visual Builder's pipe-delimited option textarea with structured choice cards for display text, stored values, pricing, and optional capacities.
- Improve the right inspector and field picker styling, spacing, focus states, and responsive layout.
- Preserve punctuation, quotes, and literal pipe characters in visitor-facing option labels without special syntax.

## 2.1.0 - 2026-10-04

- Add optional per-option capacity limits for Select fields in Classic Builder, Visual Builder, and definition imports.
- Disable full options publicly and enforce capacity transactionally across public submissions, restores, and legacy imports.
- Derive occupancy from non-trashed submissions so trash and permanent deletion immediately release slots without counter drift.

## 2.0.1 - 2026-10-02

- Link authorized `[form slug="..."]` references in Core 2.3.167+ CodeMirror editors directly to their Visual Builder.
- Prioritize forms referenced by the current content, bound editor metadata to the active actor, and reuse one bounded authorization context while enumerating accessible forms.

## 2.0.0 - 2026-09-24

- Add a persisted Visual Builder with revision-safe autosave, safe draft previews, field inspection, and Classic Builder fallback.
- Add transactional publish/reset with canonical drift detection, protected-code permission checks, and form-scoped mutation locking.
- Add responsive row and column controls, accessible field reordering, pointer drag-and-drop, and collapsible side panels.
- Render Visual Builder previews inside the active Core public layout while keeping public header and footer chrome muted and inert.
- Use one native country select with browser type-ahead instead of a separate country search input.

## 1.7.9 - 2026-09-19

- Preserve valid hyphens and underscores in form slugs when changing form settings or status.
- Keep generated collision suffixes within the supported 80-character slug limit.

## 1.7.8 - 2026-09-19

- Show private embed diagnostics to authorized editors when a form is draft, archived, trashed, or missing.
- Prevent trashed forms from rendering or accepting submissions even when their previous status was active.

## 1.7.7 - 2026-09-16

- Remove a redundant PHP closing tag that leaked as visible text above the submissions list.

## 1.7.6 - 2026-09-16

- Route XLSX and CSV exports through the Core raw-response dispatcher before the dashboard layout emits HTML.

## 1.7.5 - 2026-09-16

- Add formatted XLSX exports with typed cells, readable widths, filters, frozen headers, and a hardened Excel-friendly CSV fallback.
- Replace the submission detail overlay with a responsive dedicated detail view that preserves active list filters.

## 1.7.4 - 2026-09-16

- Safely provision the private Form Builder upload namespace, reject non-writable storage before staging, and avoid touching upload storage for submissions without files.
- Add read-only per-form submission viewer ACLs without granting global submission management or inheriting form editor access.

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
