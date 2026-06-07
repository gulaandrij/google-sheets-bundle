# GoogleSheetsBundle

A Symfony bundle wrapping [`revolution/laravel-google-sheets`](https://github.com/invokable/laravel-google-sheets) with a focused, Symfony-native service that handles credentials, scopes, and common Sheets operations.

The bundle exists because v7 of `revolution/laravel-google-sheets` turned the convenient `Sheets` class into a Laravel facade that requires the Laravel container to resolve. This bundle pins the underlying `SheetsClient` into the Symfony DI graph and adds a higher-level `SheetsService` on top so application code does not need to chain the fluent API or worry about thread-local state.

## Documentation

- [Authentication](docs/authentication.md) — picking and configuring API key, OAuth, or service account credentials.
- [Recipes](docs/recipes.md) — common patterns: imports, exports, scheduled jobs, bulk updates, formulas.
- [Architecture](docs/architecture.md) — service graph, why per-call `SheetsClient` instances, design decisions.
- [Troubleshooting](docs/troubleshooting.md) — common errors and what to do about them.
- [Upgrade guide](UPGRADE.md) — moving between bundle versions.
- [Changelog](CHANGELOG.md) — version history.
- [Contributing](CONTRIBUTING.md) — local workflow and quality gates.

## Requirements

- PHP 8.3+ (PHP 8.4+ required for Symfony 8)
- Symfony 6.4, 7.x, or 8.x
- `google/apiclient` 2.16+
- `revolution/laravel-google-sheets` 7.2+

## Installation

```bash
composer require gulaandrij/google-sheets-bundle
```

If your project does not use Symfony Flex, register the bundle manually in `config/bundles.php`:

```php
return [
    // ...
    Gulaandrij\GoogleSheetsBundle\GoogleSheetsBundle::class => ['all' => true],
];
```

## Configuration

The bundle follows the same shape as [`league/flysystem-bundle`](https://github.com/thephpleague/flysystem-bundle): declare one or more named spreadsheets, and the bundle wires a dedicated `SheetsService` instance per name that you can autowire by variable name.

Create `config/packages/google_sheets.yaml`:

```yaml
google_sheets:
    application_name: 'My App'      # optional, sent to the Google API
    auth:
        # Provide at least one of the following. Combine api_key + oauth + auth_config as needed.
        api_key: '%env(GOOGLE_API_KEY)%'
        client_id: '%env(GOOGLE_CLIENT_ID)%'
        client_secret: '%env(GOOGLE_CLIENT_SECRET)%'
        auth_config: '%env(resolve:GOOGLE_AUTH_CONFIG)%'   # path to service-account JSON or JSON string
    scopes:
        - 'https://www.googleapis.com/auth/spreadsheets'
        - 'https://www.googleapis.com/auth/drive.readonly'
    spreadsheets:
        allocators:
            id: '%env(GOOGLE_ALLOCATORS_SHEET_ID)%'
            sheet: 'Allocator List'              # optional: bind a default tab
        reports:
            id: '%env(GOOGLE_REPORTS_SHEET_ID)%' # sheet omitted — pass per-call
    default_spreadsheet: allocators              # required when more than one spreadsheet is declared
```

Each entry under `spreadsheets` becomes:

- A service at `google_sheets.sheets_service.<name>` (public).
- An autowire-by-name binding `SheetsService $<camelCaseName>` so `__construct(SheetsService $allocators)` resolves automatically.
- For the `default_spreadsheet` entry, the bare `SheetsService` alias and `SheetsService::class` autowire target.

`spreadsheets` is optional — if you only need the lower-level `SheetsClient` / `SheetsClientFactory` services, omit it entirely. If you declare exactly one spreadsheet, it becomes the default automatically; with more than one you must set `default_spreadsheet` explicitly (the bundle fails at boot otherwise).

Spreadsheet names must contain only letters, digits, underscores, dashes, or dots, and must start with a letter or underscore so they can be camelCased into a PHP variable name. Invalid names fail at config-tree validation.

The `sheet` key is optional. When set, `SheetsService::readRaw()`, `readAssoc()`, `firstRow()`, `append()`, `update()`, `clear()`, and `sheetProperties()` may be called without a `$sheetName` argument and operate on the bound tab. Passing an explicit `$sheetName` overrides the bound default — useful when a single spreadsheet has many tabs and you want one service to cover them all.

If no `scopes` are set the bundle defaults to read-only access:

```yaml
scopes:
    - https://www.googleapis.com/auth/spreadsheets.readonly
    - https://www.googleapis.com/auth/drive.readonly
```

### Choosing an auth method

| Method                                | When to use                                                                                   |
|--------------------------------------|-----------------------------------------------------------------------------------------------|
| `api_key`                            | Public spreadsheets and quick prototypes. Read-only access only.                              |
| `client_id` + `client_secret`        | User-facing OAuth flows where your app prompts each user to log in.                            |
| `auth_config`                        | Server-to-server access via a Google service account. Recommended for cron jobs and backends. |

The bundle will refuse to build the `Google\Client` if none of the three are configured, throwing `Gulaandrij\GoogleSheetsBundle\Exception\MissingCredentialsException`.

## Usage

Each named spreadsheet has its own `SheetsService` instance bound to that spreadsheet ID. Type-hint `SheetsService` and name the constructor parameter after the spreadsheet:

```php
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;

final class AllocatorReport
{
    public function __construct(
        private readonly SheetsService $allocators,   // → bound to spreadsheets.allocators
        private readonly SheetsService $reports,      // → bound to spreadsheets.reports
    ) {}

    public function run(): void
    {
        $rows = $this->allocators->readAssoc('Allocator List');
        // $rows = [['Name' => 'Alice', 'Email' => 'a@example.com'], ...]

        $this->allocators->append('Allocator List', [
            ['Name' => 'Bob', 'Email' => 'b@example.com'],
        ]);

        $this->reports->update('Daily', 'A1', [['Date', 'Count'], ['2026-06-07', 42]]);
    }
}
```

Variable names with underscores or dashes in the config key become camelCase bindings: `billing_data` → `$billingData`, `my-reports` → `$myReports`.

If you need a dynamic spreadsheet ID (only known at runtime), inject `SheetsClientFactory` instead and drive the client directly — see [Architecture](docs/architecture.md).

### API

`SheetsService` wraps every public method on the underlying `Revolution\Google\Sheets\SheetsClient`, grouped by intent. Every method runs against a fresh `SheetsClient` from the factory so selector state never leaks between calls.

#### Reading

```php
SheetsService::readRaw(?string $sheetName = null, ?string $range = null, ?string $majorDimension = null, ?string $valueRenderOption = null, ?string $dateTimeRenderOption = null): array
SheetsService::readAssoc(?string $sheetName = null, ?string $range = null, ?string $majorDimension = null, ?string $valueRenderOption = null, ?string $dateTimeRenderOption = null): array
SheetsService::firstRow(?string $sheetName = null, ?string $range = null, ?string $valueRenderOption = null, ?string $dateTimeRenderOption = null): array
```

`$sheetName` defaults to the configured `sheet` for the binding; pass an explicit value to target a different tab. The optional read modifiers map onto the Sheets API's `majorDimension` / `valueRenderOption` / `dateTimeRenderOption` query parameters. Class constants are provided for convenience: `SheetsService::MAJOR_DIMENSION_ROWS|COLUMNS`, `VALUE_RENDER_FORMATTED|UNFORMATTED|FORMULA`, `DATE_TIME_RENDER_SERIAL|FORMATTED`. `firstRow()` deliberately does not expose `majorDimension` — under `COLUMNS` it would return the first column.

#### Writing

```php
SheetsService::append(array $rows, ?string $sheetName = null, string $valueInputOption = 'RAW', string $insertDataOption = 'OVERWRITE'): AppendValuesResponse
SheetsService::update(string $range, array $values, ?string $sheetName = null, string $valueInputOption = 'RAW'): BatchUpdateValuesResponse
SheetsService::clear(?string $sheetName = null, ?string $range = null): ?ClearValuesResponse
```

Use the `SheetsService::VALUE_INPUT_RAW` / `VALUE_INPUT_USER_ENTERED` and `INSERT_DATA_OVERWRITE` / `INSERT_DATA_INSERT_ROWS` constants for the option arguments.

`append()` enforces row-shape consistency upfront: all rows must be positional, or all rows must be associative with the **same key set** — mixed shapes or divergent assoc keys throw `MixedRowShapeException` instead of letting the underlying client silently drop values.

#### Tab management

```php
SheetsService::addSheet(string $title): BatchUpdateSpreadsheetResponse
SheetsService::deleteSheet(string $title): BatchUpdateSpreadsheetResponse
```

Both require the `https://www.googleapis.com/auth/spreadsheets` scope.

#### Discovery

```php
SheetsService::listSheets(): array
SheetsService::listSheetsWithIds(): array
SheetsService::findSheetNameById(int $sheetId): ?string

SheetsClientFactory::listSpreadsheets(): array     // global Drive query — lives on the factory, not a bound SheetsService
```

`listSpreadsheets` lives on `SheetsClientFactory` because it's a global Drive query (`fileId => title`) and is independent of any spreadsheet binding. Requires a Drive read scope.

#### Metadata

```php
SheetsService::spreadsheetProperties(): object
SheetsService::sheetProperties(?string $sheetName = null): object
```

Returned objects mirror the Sheets API's `SpreadsheetProperties` / `SheetProperties` resources (`title`, `locale`, `timeZone`, `gridProperties`, etc.).

#### Escape hatches

```php
SheetsService::client(): SheetsClient
SheetsService::driveService(): \Google\Service\Drive
SheetsService::getSpreadsheetId(): string
SheetsService::getBoundSheet(): ?string
```

`client()` returns a brand-new `SheetsClient` per call; `driveService()` returns the shared `Google\Service\Drive` instance for Drive-level operations not covered by this service.

- `readRaw` returns each row as a positional array (`list<list<mixed>>`).
- `readAssoc` treats the first row as the header and yields associative rows. Short rows are padded with empty strings; overflow cells beyond the header are discarded. Duplicate header values throw `DuplicateHeaderException`; non-scalar header cells throw `InvalidHeaderException` — fall back to `readRaw` for non-conforming sheets.
- `listSheets` returns just the tab titles in a list; `listSheetsWithIds` returns the `sheetId => title` map for callers that need the stable IDs (e.g. for `sheetById()` lookups).
- `append` accepts either positional rows (`list<list<mixed>>`) or associative rows (`list<array<string,mixed>>`); rows must be uniform — mixing shapes throws `MixedRowShapeException` to prevent the underlying client from silently dropping data.
- `update` and `clear` target a specific A1-notation range; pass `null` to `clear` to wipe the whole sheet.
- `client()` returns a **fresh** `Revolution\Google\Sheets\SheetsClient` for advanced operations not covered above (batch operations, properties, drive metadata, etc.). Because every call constructs a new instance, mutating selectors like `valueRenderOption()` on the returned client never leaks into other callers.

### State isolation

The underlying `Revolution\Google\Sheets\SheetsClient` keeps `range`, `majorDimension`, `valueRenderOption`, and `dateTimeRenderOption` as instance fields — and neither `spreadsheet()` nor `sheet()` reset them. To prevent cross-call leakage, this bundle wires the `SheetsClient` service as **non-shared**: each `SheetsService` method (and each `client()` call) gets a brand-new instance via `SheetsClientFactory::create()`. If you autowire `SheetsClient` directly into your own services, every constructor injection still gets its own instance; if you fetch it from the container at runtime, each `get()` returns a new one.

### Direct access to lower-level services

Each layer is registered both under a typed alias and an explicit service ID:

| Service                                                                   | Notes                                                                          |
|---------------------------------------------------------------------------|--------------------------------------------------------------------------------|
| `Gulaandrij\GoogleSheetsBundle\Service\SheetsService`                     | Public; resolves to the `default_spreadsheet` instance. Also bindable by name. |
| `Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory`               | Builds a fresh `SheetsClient` per `create()` call.                              |
| `Revolution\Google\Sheets\SheetsClient`                                   | Non-shared; configured client with `setService(...)` already called.            |
| `Google\Service\Sheets`                                                   | Raw `spreadsheets` resource used to issue arbitrary v4 API calls.               |
| `Google\Client`                                                           | Authenticated transport; reuse for other Google APIs in your project.           |

All five are autowireable by class name. `SheetsService` is additionally autowireable by variable name as described above.

## Console commands

The bundle ships four introspection commands that use the configured bindings:

| Command                                       | Purpose                                                                                              |
|-----------------------------------------------|------------------------------------------------------------------------------------------------------|
| `google-sheets:list`                          | List every binding configured under `google_sheets.spreadsheets` (name, ID, bound tab).             |
| `google-sheets:tabs <binding>`                | List every tab in the bound spreadsheet for one binding.                                            |
| `google-sheets:peek <binding> [sheet] [--rows=N] [--range=A1:Z]` | Dump the first N rows as a Symfony table — useful for sanity-checking auth + connectivity. |
| `google-sheets:doctor`                        | Probe every binding, report reachability + whether the bound sheet exists.                          |

`doctor` returns a non-zero exit code if any binding fails — wire it into your deploy pipeline to catch misconfiguration before the first runtime call.

## Typed reads with DTOs

For DTOs whose property names don't match sheet headers, place `#[SheetColumn]` attributes and call `readEntities` instead of `readAssoc`:

```php
use Gulaandrij\GoogleSheetsBundle\Attribute\SheetColumn;

final class Allocator
{
    #[SheetColumn('Record ID - Contact')]
    public ?string $contactId = null;

    #[SheetColumn('First Name')]
    public ?string $firstName = null;

    #[SheetColumn('Email')]
    public ?string $email = null;
}

// In your service:
$allocators = $this->allocators->readEntities(Allocator::class);
// returns Allocator[] — the serializer denormalizes each row.
```

Requires `framework.serializer` to be enabled (Symfony auto-installs it; nothing else to configure). The bundle wires the denormalizer automatically when available; without it `readEntities()` throws a clear `LogicException`.

## Streaming large sheets

`readAssoc()` loads the whole sheet into memory — fine for thousands of rows, harsh for hundreds of thousands. `readAssocIterable()` yields rows one at a time, fetching them from the API in batches:

```php
foreach ($this->reports->readAssocIterable(batchSize: 1000) as $row) {
    $this->process($row);
}
```

## Opt-in read caching

Slowly-changing reference data (e.g. keyword tables, dictionaries) can be cached transparently. Add a `cache` block to the binding:

```yaml
google_sheets:
    spreadsheets:
        category_keywords:
            id: '%env(GOOGLE_CATEGORIES_SHEET_ID)%'
            sheet: 'Keywords'
            cache:
                ttl: 3600                # seconds
                pool: cache.app          # any service implementing Symfony\Contracts\Cache\CacheInterface
```

Reads (`readAssoc`, `readRaw`, `firstRow`, `listSheets*`, `*Properties`) are now memoised. Writes pass through unchanged. **Caching is skipped when `kernel.debug` is true** — the profiler shows real Sheets calls in dev.

## Testing your code

Drop `Gulaandrij\GoogleSheetsBundle\Test\InMemorySheetsService` into the container in test setup to skip Google entirely:

```php
use Gulaandrij\GoogleSheetsBundle\Test\InMemorySheetsService;

// tests/Functional/AllocatorImportTest.php
protected function setUp(): void
{
    parent::setUp();
    self::getContainer()->set(
        'google_sheets.sheets_service.hubspot_allocators',
        new InMemorySheetsService([
            'Allocator List' => [
                ['Record ID - Contact', 'First Name', 'Email'],
                ['c-1', 'Alice', 'alice@example.com'],
            ],
        ], boundSheet: 'Allocator List'),
    );
}
```

The fake supports the full read/write surface (`readRaw`, `readAssoc`, `append`, `update`, `clear`, `addSheet`, `deleteSheet`, `listSheets*`, `findSheetNameById`, etc.) so most tests don't need any extra mocking.

## Web Profiler

When `kernel.debug` is true (typically the `dev` environment), the bundle automatically wraps every named `SheetsService` with a tracing decorator and registers a Symfony Web Profiler data collector. A "Google Sheets" toolbar item shows the total call count and total time spent in Sheets calls; clicking it opens a panel listing each call (service binding, method, spreadsheet ID, sheet, range, duration, status).

There is nothing to configure — install the bundle, enable the profiler in your dev config, and the panel appears. In production (`kernel.debug = false`) the decorator and collector are skipped so there is zero overhead.

## Testing

The package ships with PHPUnit, PHPStan (level 8), and PHP-CS-Fixer configured. Run:

```bash
composer test     # PHPUnit
composer stan     # PHPStan
composer lint     # PHP-CS-Fixer dry run
composer lint:fix # PHP-CS-Fixer apply
```

## License

[MIT](LICENSE).
