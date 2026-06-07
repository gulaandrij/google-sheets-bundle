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
        allocators: '%env(GOOGLE_ALLOCATORS_SHEET_ID)%'
        reports:    '%env(GOOGLE_REPORTS_SHEET_ID)%'
    default_spreadsheet: allocators     # required when more than one spreadsheet is declared
```

Each entry under `spreadsheets` becomes:

- A service at `google_sheets.sheets_service.<name>` (public).
- An autowire-by-name binding `SheetsService $<camelCaseName>` so `__construct(SheetsService $allocators)` resolves automatically.
- For the `default_spreadsheet` entry, the bare `SheetsService` alias and `SheetsService::class` autowire target.

`spreadsheets` is optional — if you only need the lower-level `SheetsClient` / `SheetsClientFactory` services, omit it entirely. If you declare exactly one spreadsheet, it becomes the default automatically; with more than one you must set `default_spreadsheet` explicitly (the bundle fails at boot otherwise).

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
SheetsService::readRaw(string $sheetName, ?string $range = null, ?string $majorDimension = null, ?string $valueRenderOption = null, ?string $dateTimeRenderOption = null): array
SheetsService::readAssoc(string $sheetName, ?string $range = null, ?string $majorDimension = null, ?string $valueRenderOption = null, ?string $dateTimeRenderOption = null): array
SheetsService::firstRow(string $sheetName, ?string $range = null, ?string $majorDimension = null, ?string $valueRenderOption = null, ?string $dateTimeRenderOption = null): array
```

The optional read modifiers map onto the Sheets API's `majorDimension` / `valueRenderOption` / `dateTimeRenderOption` query parameters. Class constants are provided for convenience: `SheetsService::MAJOR_DIMENSION_ROWS|COLUMNS`, `VALUE_RENDER_FORMATTED|UNFORMATTED|FORMULA`, `DATE_TIME_RENDER_SERIAL|FORMATTED`.

#### Writing

```php
SheetsService::append(string $sheetName, array $rows, string $valueInputOption = 'RAW', string $insertDataOption = 'OVERWRITE'): AppendValuesResponse
SheetsService::update(string $sheetName, string $range, array $values, string $valueInputOption = 'RAW'): BatchUpdateValuesResponse
SheetsService::clear(string $sheetName, ?string $range = null): ?ClearValuesResponse
```

Use the `SheetsService::VALUE_INPUT_RAW` / `VALUE_INPUT_USER_ENTERED` and `INSERT_DATA_OVERWRITE` / `INSERT_DATA_INSERT_ROWS` constants for the option arguments.

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
SheetsService::listSpreadsheets(): array
```

`listSpreadsheets` is a Drive query — it lists every spreadsheet the credential can see, independent of the bound spreadsheet ID. Requires a Drive read scope.

#### Metadata

```php
SheetsService::spreadsheetProperties(): object
SheetsService::sheetProperties(string $sheetName): object
```

Returned objects mirror the Sheets API's `SpreadsheetProperties` / `SheetProperties` resources (`title`, `locale`, `timeZone`, `gridProperties`, etc.).

#### Escape hatches

```php
SheetsService::client(): SheetsClient
SheetsService::driveService(): \Google\Service\Drive
SheetsService::getSpreadsheetId(): string
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
