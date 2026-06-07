# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] — 2026-06-07

First stable release. Locks the public API; any further breaking change will bump the major version.

### Added
- Named spreadsheets following the `league/flysystem-bundle` pattern: declare each spreadsheet under `google_sheets.spreadsheets.<name>` and inject the bound instance via `SheetsService $<camelCaseName>`. The bare `SheetsService` alias is wired to the `default_spreadsheet`.
- Each spreadsheet entry takes an `id` and an optional `sheet`. When `sheet` is set the bound `SheetsService` methods may be called without a `$sheetName` argument; pass `$sheetName` explicitly to override.
- Boot-time validation rejecting: `default_spreadsheet` values that don't match a configured spreadsheet, configurations with multiple spreadsheets but no `default_spreadsheet`, spreadsheet keys that can't be camelCased into a PHP variable name (e.g. `123:`, `my reports:`), and empty entries under `scopes`.
- Full API coverage of the underlying `Revolution\Google\Sheets\SheetsClient`: `readRaw`, `readAssoc`, `firstRow`, `listSheets`, `listSheetsWithIds`, `findSheetNameById`, `append`, `update`, `clear`, `addSheet`, `deleteSheet`, `spreadsheetProperties`, `sheetProperties`, plus escape hatches `client()`, `driveService()`, `getSpreadsheetId()`, `getBoundSheet()`.
- Optional `majorDimension`, `valueRenderOption`, `dateTimeRenderOption` arguments on `readRaw` / `readAssoc` for fine-grained read tuning. `firstRow` exposes only `valueRenderOption` / `dateTimeRenderOption` — `majorDimension: COLUMNS` would return the first column, contradicting the method name. Class constants for the Sheets API enum values.
- `SheetsClientFactory::listSpreadsheets()` — global Drive query returning `fileId => title`, independent of any spreadsheet binding.
- `Google\Service\Drive` is registered as `google_sheets.google_drive` (alias `Google\Service\Drive`) so Drive-aware paths work outside Laravel.
- Strict input validation: `DuplicateHeaderException` (duplicate header values in `readAssoc`), `InvalidHeaderException` (non-scalar header cells), `MixedRowShapeException` (rows of mixed shape or divergent assoc key sets in `append`), `MissingCredentialsException`, `MissingSheetNameException`.
- `auth_config` accepts a string (file path or inline JSON) or a decoded array.
- `symfony/polyfill-php84` and `symfony/polyfill-php85` declared as runtime deps so the bundle can use PHP 8.4/8.5 functions (`array_find_key`, etc.) on PHP 8.3.

### Fixed
- Singleton `SheetsClient` was leaking `range` / `majorDimension` / `valueRenderOption` / `dateTimeRenderOption` between callers because `spreadsheet()` and `sheet()` never reset them. `google_sheets.sheets_client` is now non-shared and `SheetsService` constructs a fresh client per call.
- `SheetsService::client()` returns a fresh client per call so escape-hatch consumers cannot pollute shared state.
- `isAssoc()` uses `array_is_list()` so gap-keyed numeric arrays (e.g. `array_filter` output) are correctly classified as positional.

### Compatibility
- PHP 8.3+ (8.4 recommended).
- Symfony 6.4, 7.x, 8.x.
- `revolution/laravel-google-sheets ^7.2`.
- `google/apiclient ^2.16`.

## [0.1.0]

### Added
- Initial release.
- `GoogleSheetsBundle` (AbstractBundle) with config tree for `application_name`, `auth`, and `scopes`.
- `GoogleClientFactory` building a configured `Google\Client` from any combination of API key, OAuth client id/secret, and service-account `auth_config`.
- `SheetsService` exposing `readRaw`, `readAssoc`, `listSheets`, `append`, `update`, `clear`, and an escape-hatch `client()` accessor.
- Autowire-friendly aliases for `SheetsService`, `SheetsClient`, `Google\Service\Sheets`, and `Google\Client`.
- PHPUnit, PHPStan (level 8), PHP-CS-Fixer, and GitHub Actions CI matrix (PHP 8.3/8.4 × Symfony 6.4/7.x).

[Unreleased]: https://github.com/gulaandrij/google-sheets-bundle/compare/1.0.0...HEAD
[1.0.0]: https://github.com/gulaandrij/google-sheets-bundle/releases/tag/1.0.0
[0.1.0]: https://github.com/gulaandrij/google-sheets-bundle/releases/tag/0.1.0
