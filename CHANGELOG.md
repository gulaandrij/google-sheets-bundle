# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Named spreadsheets following the `league/flysystem-bundle` pattern: declare each spreadsheet under `google_sheets.spreadsheets.<name>`, inject the bound instance via `SheetsService $<camelCaseName>`. The bare `SheetsService` alias is wired to the `default_spreadsheet`.
- Boot-time validation rejecting `default_spreadsheet` values that don't match a configured spreadsheet, and configurations with multiple spreadsheets but no `default_spreadsheet`.
- `SheetsService::getSpreadsheetId()` to read the bound ID.

### Changed
- **BREAKING**: `SheetsService` constructor now takes `string $spreadsheetId`; method signatures drop the `$spreadsheetId` parameter. See [UPGRADE.md](UPGRADE.md) for the migration recipe.
- The bare `SheetsService` autowire alias is now only registered when at least one spreadsheet is configured.

### Added
- `SheetsClientFactory` service registered as `google_sheets.sheets_client_factory` (autowire alias). `SheetsService` now obtains a fresh `SheetsClient` per call.
- `SheetsService::listSheetsWithIds()` — returns the `sheetId => title` map (the existing `listSheets()` still returns just the names).
- `DuplicateHeaderException`, `InvalidHeaderException`, `MixedRowShapeException` — explicit failure modes for the previously-silent corner cases.
- `auth_config` config node now accepts arrays in addition to strings.

### Fixed
- Singleton `SheetsClient` was leaking `range` / `majorDimension` / `valueRenderOption` / `dateTimeRenderOption` between callers because `spreadsheet()` and `sheet()` never reset them. `google_sheets.sheets_client` is now non-shared and `SheetsService` constructs a fresh client per call.
- `SheetsService::client()` now returns a fresh client per call so escape-hatch consumers cannot pollute shared state.
- `readAssoc()` no longer silently collapses duplicate header columns (throws `DuplicateHeaderException`) and no longer warns on non-scalar header cells (throws `InvalidHeaderException`).
- `append()` rejects mixed positional/associative rows (`MixedRowShapeException`) instead of letting the underlying client drop data for the non-conforming rows.

### Changed
- `phpunit/phpunit` constraint tightened to `^12.0` (test code uses `AllowMockObjectsWithoutExpectations`, a PHPUnit 12 attribute).

## [0.1.0]

### Added
- Initial release.
- Symfony 6.4, 7.x, and 8.x supported via `^6.4 || ^7.0 || ^8.0` constraints (PHP 8.4+ required for Symfony 8).
- `GoogleSheetsBundle` (AbstractBundle) with config tree for `application_name`, `auth`, and `scopes`.
- `GoogleClientFactory` building a configured `Google\Client` from any combination of API key, OAuth client id/secret, and service-account `auth_config`.
- `SheetsService` exposing `readRaw`, `readAssoc`, `listSheets`, `append`, `update`, `clear`, and an escape-hatch `client()` accessor.
- Autowire-friendly aliases for `SheetsService`, `SheetsClient`, `Google\Service\Sheets`, and `Google\Client`.
- PHPUnit, PHPStan (level 8), PHP-CS-Fixer, and GitHub Actions CI matrix (PHP 8.3/8.4 × Symfony 6.4/7.x).

[Unreleased]: https://github.com/gulaandrij/google-sheets-bundle/compare/0.1.0...HEAD
[0.1.0]: https://github.com/gulaandrij/google-sheets-bundle/releases/tag/0.1.0
