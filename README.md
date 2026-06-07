# GoogleSheetsBundle

A Symfony bundle wrapping [`revolution/laravel-google-sheets`](https://github.com/invokable/laravel-google-sheets) with a focused, Symfony-native service that handles credentials, scopes, and common Sheets operations.

The bundle exists because v7 of `revolution/laravel-google-sheets` turned the convenient `Sheets` class into a Laravel facade that requires the Laravel container to resolve. This bundle pins the underlying `SheetsClient` into the Symfony DI graph and adds a higher-level `SheetsService` on top so application code does not need to chain the fluent API or worry about thread-local state.

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
```

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

Autowire `SheetsService` and call its high-level methods. The service takes the spreadsheet ID and tab name on every call, so there is no shared state between operations.

```php
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;

final class AllocatorReport
{
    public function __construct(private readonly SheetsService $sheets) {}

    public function run(): void
    {
        $rows = $this->sheets->readAssoc('1abcDEFghi...', 'Allocator List');
        // $rows = [['Name' => 'Alice', 'Email' => 'a@example.com'], ...]

        $this->sheets->append('1abcDEFghi...', 'Allocator List', [
            ['Name' => 'Bob', 'Email' => 'b@example.com'],
        ]);
    }
}
```

### API

```php
SheetsService::readRaw(string $spreadsheetId, string $sheetName, ?string $range = null): array
SheetsService::readAssoc(string $spreadsheetId, string $sheetName, ?string $range = null): array
SheetsService::listSheets(string $spreadsheetId): array
SheetsService::append(string $spreadsheetId, string $sheetName, array $rows, string $valueInputOption = 'RAW', string $insertDataOption = 'OVERWRITE'): AppendValuesResponse
SheetsService::update(string $spreadsheetId, string $sheetName, string $range, array $values, string $valueInputOption = 'RAW'): BatchUpdateValuesResponse
SheetsService::clear(string $spreadsheetId, string $sheetName, ?string $range = null): ?ClearValuesResponse
SheetsService::client(): SheetsClient
```

- `readRaw` returns each row as a positional array (`list<list<mixed>>`).
- `readAssoc` treats the first row as the header and yields associative rows. Short rows are padded with empty strings; overflow cells beyond the header are discarded.
- `append` accepts either positional rows (`list<list<mixed>>`) or associative rows (`list<array<string,mixed>>`); associative rows are reordered to match the sheet header by the underlying client.
- `update` and `clear` target a specific A1-notation range; pass `null` to `clear` to wipe the whole sheet.
- `client()` returns the underlying `Revolution\Google\Sheets\SheetsClient` for advanced operations not covered above (batch operations, properties, drive metadata, etc.).

### Direct access to lower-level services

Each layer is registered both under a typed alias and an explicit service ID:

| Service                                       | Notes                                                                  |
|-----------------------------------------------|------------------------------------------------------------------------|
| `Gulaandrij\GoogleSheetsBundle\Service\SheetsService`     | Public, the recommended entry point.                                   |
| `Revolution\Google\Sheets\SheetsClient`       | Configured client with `setService(...)` already called.               |
| `Google\Service\Sheets`                       | Raw `spreadsheets` resource used to issue arbitrary v4 API calls.      |
| `Google\Client`                               | Authenticated transport; reuse for other Google APIs in your project.  |

All four are autowireable by their class name.

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
