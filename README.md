# Form Builder

Form Builder 1.7.5 is a Jyavani Core 2.3.122 plugin for reusable public forms, private uploads, multilingual definitions, and review workflows.

## Requirements

- Jyavani Core 2.3.122 or newer
- PHP 8.1 or newer with `pdo_mysql`, `fileinfo`, `dom`, `json`, and `mbstring`
- A project root where the PHP runtime can safely create or write the Core-owned `private_files` directory

Formatted Excel export uses the bundled, Composer-locked PhpSpreadsheet runtime when its PHP extensions are available (`ctype`, `dom`, `fileinfo`, `filter`, `gd`, `iconv`, `libxml`, `mbstring`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, and `zlib`). The submissions screen retains a streamed CSV fallback when Excel support is unavailable.

Core runs `migrations/` during install, update, or enable. Runtime requests only assert the migrated schema and never perform DDL.

## Use

Embed an active form with `[form slug="contact"]`. Theme Section integrations may render `form-builder` with a `slug` attribute, and Theme Zones may use the Form Builder widget. Repeated embeds receive unique DOM IDs.

Definitions use schema version `1` and deterministically upsert by slug. The admin import/export controls omit submissions, files, secrets, ACL, and unsafe code by default. PHP integrations can call `fb_export_form_definition()` and `fb_upsert_form_definition()` directly after checking their own authorization.

Public labels, help, options, UI messages, validation messages, success text, and administrator/applicant mail templates can be overridden under any valid locale key in `settings.translations`. Locale identifiers use normalized lowercase BCP 47 syntax, such as `fr`, `pt-br`, or `zh-hant`; an exact locale falls back to its base language and then to the built-in English text. Unknown translation keys and malformed or oversized values are rejected during definition validation.

The `country` field renders a searchable bundled ISO 3166-1 picker and stores the uppercase alpha-2 code. The `intl_phone` field requires a `settings.country_field` reference to a `country` field, displays its calling code, and stores a server-normalized E.164 value. Country names and calling metadata are bundled for runtime independence; provenance and licenses are documented in `THIRD_PARTY_NOTICES.md`.

Priced options and totals use the form's configurable uppercase ISO 4217 `currency_code`; new forms default to `USD`.

## Legacy Submission Import

`fb_import_legacy_submission(PDO $pdo, string|int $form, string $sourceNamespace, string $sourceKey, array $record)` imports one generic legacy record. The versioned record preserves its reference, creation/update timestamps, mapped workflow status, reviewer notes/history, provenance, field data, totals, read/trash state, and validated private attachment metadata/paths. It never reads client-specific tables.

The separate `fb_submission_imports` ledger binds the source namespace/key to a canonical payload hash and submission. An identical repeat returns the existing submission with `reconciled=true`; changed payloads, references, forms, attachment bytes, or inconsistent ledger state throw and roll back. Callers must map source fields/statuses, place attachments beneath the configured private `form-builder` storage root, and perform their own authorization before calling the API.

Public submissions use Core stateless CSRF, honeypot and signed fill-time checks, fail-closed database rate limits, idempotency, strict field/file validation, private staged storage, and post-commit mail/observers. Reviewers can independently manage read/trash state, workflow status, append-only notes, formatted XLSX or CSV exports, and private attachment downloads. Submission details use an addressable responsive page rather than a nested modal.

## Verification

Run `php tests/security_contract.php`, `php tests/behavior_contract.php`, and PHP lint over all PHP files. `php tests/mysql_contract.php --core-env` runs the guarded integration suite in a unique disposable `fb_contract_*` database and always drops it; without an explicit opt-in it skips. Build a deterministic flat ZIP with `php tools/build-package.php [output.zip]`; `plugin.json` is written at the archive root and only the build environment needs PHP's `zip` extension.

Storage defaults exactly to `dirname(PLUGIN_PATH)/private_files/form-builder`. On the first validated upload, Form Builder safely creates the missing default `private_files` and dedicated `form-builder` directories with private permissions. Existing storage must be writable by the PHP runtime. The `fb_files_base_dir` filter may relocate storage only to an absolute, normalized, existing-parent path whose dedicated leaf remains `form-builder`; custom parents are deployment-provisioned and are never created implicitly.

Complete uninstall is controlled by Core's keep-data choice. A complete uninstall removes plugin tables, settings, migration history (Core-owned), and the exact contained private upload tree; unsafe filesystem state aborts cleanup.
