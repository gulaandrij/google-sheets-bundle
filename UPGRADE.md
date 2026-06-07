# Upgrade Guide

Behavioural and API changes between bundle versions. For dependency bumps and bug fixes see [CHANGELOG.md](CHANGELOG.md).

## Upgrading to 0.x → 1.0 (unreleased)

Major API will be locked at 1.0; no breaking changes planned for this release.

## Pre-1.0 → 0.4.0

The bundle now binds an optional sheet/tab name per spreadsheet entry, and methods accept it as the optional last argument. `append()`, `update()`, `clear()`, and the read methods all changed parameter order.

### Update your config

```diff
 google_sheets:
     spreadsheets:
-        allocators: '%env(GOOGLE_ALLOCATORS_SHEET_ID)%'
+        allocators:
+            id: '%env(GOOGLE_ALLOCATORS_SHEET_ID)%'
+            sheet: 'Allocator List'   # optional — when set, methods may be called without $sheetName
```

The scalar short-form `name: '1abc'` is no longer accepted. Always use `name: { id: '1abc', sheet?: 'tab' }`.

### Update your call sites

`$sheetName` moved from the first argument to the optional last argument on every method (and is omitted entirely when the binding has `sheet:` set).

```diff
-$this->allocators->readAssoc('Allocator List');
+$this->allocators->readAssoc();                          // uses bound sheet

-$this->allocators->append('Allocator List', $rows);
+$this->allocators->append($rows);

-$this->reports->update('Daily Export', 'A1', $rows);
+$this->reports->update('A1', $rows);

-$this->reports->clear('Daily Export');
+$this->reports->clear();
```

To target a different tab on the same spreadsheet, pass `$sheetName` explicitly:

```php
$this->allocators->readAssoc('Archive');                  // override
$this->allocators->append($rows, 'Archive');
```

### listSpreadsheets moved

`SheetsService::listSpreadsheets()` was removed — it was a global Drive query that didn't belong on a spreadsheet-bound service. Use `SheetsClientFactory::listSpreadsheets()` instead.

## Pre-1.0 → 0.3.0

The bundle adopted the `league/flysystem-bundle` pattern for spreadsheet selection. `SheetsService` became bound to a single spreadsheet (configured under `google_sheets.spreadsheets`), and its methods no longer take a `$spreadsheetId` argument.

### Update your config

```diff
 google_sheets:
     auth:
         api_key: '%env(GOOGLE_API_KEY)%'
+    spreadsheets:
+        allocators:
+            id: '%env(GOOGLE_ALLOCATORS_SHEET_ID)%'
+        reports:
+            id: '%env(GOOGLE_REPORTS_SHEET_ID)%'
+    default_spreadsheet: allocators
```

`default_spreadsheet` is required when you declare more than one spreadsheet; with exactly one it's inferred automatically.

### Update your call sites

```diff
 final class AllocatorReport
 {
-    public function __construct(private readonly SheetsService $sheets) {}
+    public function __construct(private readonly SheetsService $allocators) {}

     public function run(): void
     {
-        $rows = $this->sheets->readAssoc('1abcDEFghi...', 'Allocator List');
-        $this->sheets->append('1abcDEFghi...', 'Allocator List', $newRows);
+        $rows = $this->allocators->readAssoc('Allocator List');
+        $this->allocators->append('Allocator List', $newRows);
     }
 }
```

If your spreadsheet ID is only known at runtime, drop down to `SheetsClientFactory`:

```php
public function __construct(private readonly SheetsClientFactory $factory) {}

public function read(string $spreadsheetId, string $tab): array
{
    return $this->factory->create()->spreadsheet($spreadsheetId)->sheet($tab)->all();
}
```

## Pre-1.0 → 0.2.0

Pre-1.0 releases may break public API at any time. The 0.2.0 release introduced:

- `SheetsClientFactory` as the canonical way to obtain a `SheetsClient`. If you were autowiring `SheetsClient` directly, your existing code keeps working — the bundle just guarantees fresh instances now. If you were holding a `SheetsClient` reference across requests, replace it with `SheetsClientFactory` and call `create()` per use.
- Strict input validation in `readAssoc` (`DuplicateHeaderException`, `InvalidHeaderException`) and `append` (`MixedRowShapeException`). Previously these conditions silently dropped data; now they fail loudly. If you were relying on the lenient behavior, normalise inputs at the call site or fall back to `readRaw()` + manual processing.

## From the raw `revolution/laravel-google-sheets` library

If you're migrating off the underlying library directly:

1. Declare the spreadsheet IDs you read/write under `google_sheets.spreadsheets` and inject the corresponding `SheetsService $<name>`. For one-off spreadsheets, inject `SheetsClientFactory` and drive the client directly.
2. Move credential setup from your code (`$client->setScopes(...)`, `$client->setAuthConfig(...)`) into `config/packages/google_sheets.yaml`.
3. Replace chained `spreadsheet(...)->sheet(...)->get()` patterns with `SheetsService::readRaw($tab)` or `readAssoc($tab)`.
4. Replace `$sheets->collection($header, $rows)->toArray()` with `readAssoc` (which performs the same operation while enforcing header uniqueness).
5. Audit for sticky-state bugs: anywhere you set `range()`, `valueRenderOption()`, or `majorDimension()` and reused the client, switch to the per-call methods on `SheetsService` (which always fetch a fresh client).
