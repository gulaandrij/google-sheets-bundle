# Upgrade Guide

Behavioural and API changes between bundle versions. For dependency bumps and bug fixes see [CHANGELOG.md](CHANGELOG.md).

## Upgrading to 0.x → 1.0 (unreleased)

Major API will be locked at 1.0; no breaking changes planned for this release.

## Pre-1.0 → 0.2.0

Pre-1.0 releases may break public API at any time. The 0.2.0 release introduced:

- `SheetsClientFactory` as the canonical way to obtain a `SheetsClient`. If you were autowiring `SheetsClient` directly, your existing code keeps working — the bundle just guarantees fresh instances now. If you were holding a `SheetsClient` reference across requests, replace it with `SheetsClientFactory` and call `create()` per use.
- Strict input validation in `readAssoc` (`DuplicateHeaderException`, `InvalidHeaderException`) and `append` (`MixedRowShapeException`). Previously these conditions silently dropped data; now they fail loudly. If you were relying on the lenient behavior, normalise inputs at the call site or fall back to `readRaw()` + manual processing.

## From the raw `revolution/laravel-google-sheets` library

If you're migrating off the underlying library directly:

1. Replace direct `new Revolution\Google\Sheets\SheetsClient()` calls with the bundle's autowired `SheetsService` (recommended) or `SheetsClientFactory::create()` (for full client control).
2. Move credential setup from your code (`$client->setScopes(...)`, `$client->setAuthConfig(...)`) into `config/packages/google_sheets.yaml`.
3. Replace chained `spreadsheet(...)->sheet(...)->get()` patterns with `SheetsService::readRaw($spreadsheetId, $tab)` or `readAssoc`.
4. Replace `$sheets->collection($header, $rows)->toArray()` with `readAssoc` (which performs the same operation while enforcing header uniqueness).
5. Audit for sticky-state bugs: anywhere you set `range()`, `valueRenderOption()`, or `majorDimension()` and reused the client, switch to the per-call methods on `SheetsService` (which always fetch a fresh client).
