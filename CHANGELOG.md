# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.1] — 2026-06-07

Review-driven follow-up to 1.2.0. Ten findings addressed — three of them user-visible bugs in the new decorators, the rest cleanup and UX polish. No public API changes.

### Fixed
- **`CachedSheetsService` now forwards the `DenormalizerInterface`** to the parent constructor. Previously, any binding that opted into `cache: { … }` had `readEntities()` throw `LogicException: requires symfony/serializer` even when the serializer was wired and the plain `SheetsService` codepath worked. Bundle wiring updated to pass the denormalizer.
- **`CachedSheetsService` invalidates the cache on writes**. `append()` / `update()` / `clear()` / `addSheet()` / `deleteSheet()` now drop every cache key this instance populated so the next read goes back to Google. Previously a write followed by a read returned the pre-write data until the TTL expired.
- **`TraceableSheetsService` now overrides `readEntities()` and `readAssocIterable()`**. Previously these methods bypassed the profiler entirely: `readEntities` showed up as its inner `readAssoc` (with no denormalization timing), and a `readAssocIterable` over a 50k-row sheet recorded zero entries because it walked the private `doReadRaw` helpers. `readEntities` now appears as `readEntities<ShortClassName>`; `readAssocIterable` records a single completion entry with `batchSize` and `yielded` count.
- **`captureOrigin` no longer filters out bundle test classes**. The namespace filter is now narrowed to `Gulaandrij\GoogleSheetsBundle\Profiler\` and `…\Service\`, so the bundle's own tests can verify the captured frame.
- **Cache pool validation at boot**. Configuring `cache: { pool: misspelled }` now fails with a clear `google_sheets.spreadsheets["name"].cache.pool refers to service "misspelled"` message instead of the generic Symfony `ServiceNotFoundException` rooted somewhere in container compilation.

### Added
- `InMemorySheetsService` accepts an optional `sheetIds: array<int, string>` constructor argument so tests that exercise stable-ID lookups (`findSheetNameById`, `listSheetsWithIds`) get realistic emulation instead of positional indices (`0, 1, …`).
- `google-sheets:doctor --strict` flag. Without `--strict` (default), a missing bound sheet is reported as a warning but the command exits 0 — useful for bindings whose tab is created on first run. With `--strict`, missing bound sheets fail the command — match for deploy pipelines that expect every tab to already exist.
- `google-sheets:peek --with-header` flag. When `--range` is set, the command no longer auto-strips the first row as a header (it would otherwise treat row 5's data as the header for `--range=A5:Z20`); pass `--with-header` to opt back in when your range happens to start at the actual header row. The default header for `--range` is the A1-notation column letters (`A`, `B`, `C`, …).

### Changed
- `SheetsService::buildColumnMap()` is memoised per class. Previously a 10k-row `readEntities()` call would re-reflect the target class 10k times.
- Internal `readAssoc` → `doReadAssoc` extraction (private helper) so `readEntities` calls the inner path directly. Mirrors the existing `readRaw` → `doReadRaw` and `listSheets*` → `doListSheetsWithIds` pattern that lets the trace decorator record each public call exactly once.

## [1.2.0] — 2026-06-07

DX-focused release: introspection commands, typed reads, streaming, opt-in caching, profiler enhancements, in-memory test fake.

### Added
- **Console commands** for introspection and debugging:
  - `google-sheets:list` — print every configured binding (name, ID, bound tab).
  - `google-sheets:tabs <binding>` — list every tab in the bound spreadsheet (uses `listSheetsWithIds`).
  - `google-sheets:peek <binding> [sheet] [--rows=N] [--range=A1:Z…]` — dump the first N rows as a Symfony table; useful for sanity-checking auth + connectivity.
  - `google-sheets:doctor` — probe every binding, report `OK / BOUND-SHEET-MISSING / FAIL` per row with timing. Catches misconfig at deploy time instead of at first call.
- **`SheetsRegistry`** service (`google_sheets.registry`, public, autowire alias) — read-only directory of every named binding plus a ServiceLocator that resolves each binding's `SheetsService` by name.
- **`SheetsService::readEntities(string $className): list<T>`** — denormalizes rows directly into typed DTOs via the Symfony Serializer. The new **`#[SheetColumn('Header Name')]`** attribute on DTO properties maps spreadsheet headers to property names so DTOs can stay PHP-idiomatic. Auto-wired when `framework.serializer` is enabled; throws a clear `LogicException` otherwise.
- **`SheetsService::readAssocIterable(?string $sheetName = null, int $batchSize = 500): \Generator`** — streams assoc rows in fixed-size batches via column-letter pagination, avoiding the OOM that comes with loading a 100k-row sheet all at once.
- **`CachedSheetsService`** decorator with per-binding `cache: { ttl: <seconds>, pool: <service id> }` config. Read methods are memoised through the configured Symfony cache pool (default `cache.app`); writes go through unchanged. Tracing wins over caching in dev (`kernel.debug=true`) — the profiler should show real Sheets calls.
- **`Gulaandrij\GoogleSheetsBundle\Test\InMemorySheetsService`** — `final` drop-in for `SheetsService` backed by an in-memory map. Use in functional tests via `self::getContainer()->set('google_sheets.sheets_service.<binding>', new InMemorySheetsService([...]))` to skip Google entirely.
- **Profiler enhancements**:
  - Calls are now grouped by service binding in the panel (collapsible per-binding sections with sub-totals).
  - Each call captures and shows the **caller origin** (first stack frame outside the bundle) so you can jump straight to the file that triggered each Sheets call.
- Class constants `SheetsService::VALUE_INPUT_*` / `INSERT_DATA_*` for the corresponding option arguments (alongside the existing render-option / major-dimension constants).
- `symfony/serializer`, `symfony/cache`, and `symfony/property-access` listed under `suggest` so users discover the relevant install commands.

### Changed
- `SheetsService` is no longer `final`; both `CachedSheetsService` and `InMemorySheetsService` extend it. Subclasses remain a documented bundle internal — application code should keep injecting the concrete `SheetsService` type (alias resolution picks the right decorator).
- `SheetsService::__construct` now accepts an optional `?DenormalizerInterface` as the 4th argument (auto-injected by the bundle); the existing 3-arg form continues to work.
- Internal `readAssoc` → `doReadRaw` and `firstRow` → `doFirstRow` helpers were already in place from 1.1.0 to keep tracing single-record; the streaming reader now uses `doFirstRow` + `doReadRaw` too so a single batch yields exactly one trace entry.

## [1.1.1] — 2026-06-07

### Fixed
- Profiler toolbar item and menu now use a dedicated Google-Sheets-style SVG icon (`@GoogleSheets/Icon/sheets.svg`) instead of the WebProfiler email-icon placeholder.

## [1.1.0] — 2026-06-07

### Added
- **Symfony Web Profiler integration**. When `kernel.debug` is true the bundle registers `google_sheets.profiler.collector` (a `SheetsCollector` extending `AbstractDataCollector`) and wraps every named `SheetsService` with `TraceableSheetsService` — a subclass that records each call (`method`, bound spreadsheet ID, sheet name, range, duration in ms, optional error) into the collector. A toolbar item shows the total call count + total time; clicking it opens a panel listing every call with status badges. The collector and the traceable wrapper are skipped in production (`kernel.debug = false`).
- `SheetsService::getBoundSheet()` is now used by the trace decorator to record the resolved sheet name when an explicit `$sheetName` is omitted.

### Changed
- `SheetsService` is no longer `final` so the bundle's own `TraceableSheetsService` (and downstream consumers) can extend it.
- Internal callers of `readRaw` from `readAssoc`, and of `listSheetsWithIds` from `listSheets` / `findSheetNameById`, now go through private `doReadRaw` / `doListSheetsWithIds` helpers so traceable subclasses record exactly once per public call (instead of once for the outer call plus once for the inner delegation).

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

[Unreleased]: https://github.com/gulaandrij/google-sheets-bundle/compare/1.2.1...HEAD
[1.2.1]: https://github.com/gulaandrij/google-sheets-bundle/releases/tag/1.2.1
[1.2.0]: https://github.com/gulaandrij/google-sheets-bundle/releases/tag/1.2.0
[1.1.1]: https://github.com/gulaandrij/google-sheets-bundle/releases/tag/1.1.1
[1.1.0]: https://github.com/gulaandrij/google-sheets-bundle/releases/tag/1.1.0
[1.0.0]: https://github.com/gulaandrij/google-sheets-bundle/releases/tag/1.0.0
[0.1.0]: https://github.com/gulaandrij/google-sheets-bundle/releases/tag/0.1.0
