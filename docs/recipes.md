# Recipes

Common patterns for using `SheetsService` in real applications. The bundle binds one `SheetsService` instance per named entry under `google_sheets.spreadsheets` — each instance is fixed to one spreadsheet ID and (optionally) one default tab. Inject by variable name:

```php
public function __construct(
    private readonly SheetsService $allocators, // → google_sheets.spreadsheets.allocators
    private readonly SheetsService $reports,    // → google_sheets.spreadsheets.reports
) {}
```

If you have only one spreadsheet, `__construct(SheetsService $sheets)` also works — the bare alias points at your `default_spreadsheet`.

All call snippets below assume the matching binding has `sheet:` set in config. To target a different tab on the same spreadsheet, pass an explicit `$sheetName` argument.

## Import a sheet as typed objects

Combine `readAssoc` with the Symfony Serializer to map header-keyed rows onto DTOs:

```php
use App\Dto\Allocator;
use Symfony\Component\Serializer\SerializerInterface;

public function importAllocators(): void
{
    $rows = $this->allocators->readAssoc();
    /** @var Allocator[] $allocators */
    $allocators = $this->serializer->denormalize($rows, Allocator::class.'[]');

    foreach ($allocators as $allocator) {
        $this->entityManager->persist($allocator);
    }
    $this->entityManager->flush();
}
```

## Sync new records up to a sheet

Append rows that aren't already present:

```php
public function syncToSheet(): void
{
    $existing = $this->allocators->readAssoc();
    $existingIds = array_column($existing, 'Record ID - Contact');

    $newRows = array_filter(
        $this->loadAllocators(),
        static fn (Allocator $a): bool => !in_array($a->getId(), $existingIds, true),
    );

    if ([] === $newRows) {
        return;
    }

    $this->allocators->append(array_map(
        static fn (Allocator $a): array => [
            'Record ID - Contact' => $a->getId(),
            'First Name' => $a->getFirstName(),
            'Last Name' => $a->getLastName(),
            'Email' => $a->getEmail(),
        ],
        $newRows,
    ));
}
```

All associative rows must share the same key set — divergent keys throw `MixedRowShapeException`, preventing silent data loss when the underlying client maps keys to the sheet header.

## Schedule a nightly export

Pair with Symfony Scheduler:

```php
#[AsCommand(name: 'app:sheets:nightly-export')]
#[AsCronTask('0 4 * * *')]
final class NightlyExportCommand extends Command
{
    public function __construct(private readonly SheetsService $reports) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->buildExportRows();
        $this->reports->clear();
        $this->reports->update('A1', $rows);
        return Command::SUCCESS;
    }
}
```

## Talk to several tabs from one spreadsheet

If your spreadsheet has multiple tabs you read from regularly, declare separate bindings:

```yaml
spreadsheets:
    allocators_list:
        id: '%env(GOOGLE_ALLOCATORS_SHEET_ID)%'
        sheet: 'Allocator List'
    allocators_archive:
        id: '%env(GOOGLE_ALLOCATORS_SHEET_ID)%'  # same ID, different tab
        sheet: 'Archive'
default_spreadsheet: allocators_list
```

```php
public function __construct(
    private readonly SheetsService $allocatorsList,
    private readonly SheetsService $allocatorsArchive,
) {}

public function moveToArchive(): void
{
    $rows = $this->allocatorsList->readAssoc();
    $this->allocatorsArchive->append($rows);
    $this->allocatorsList->clear();
}
```

Alternatively, omit `sheet:` from the config and pass it at the call site:

```php
$rows = $this->allocators->readAssoc('Allocator List');
$this->allocators->append($rows, 'Archive');
```

## Look up a tab by ID, not by name

Tab IDs are stable across renames; tab names are not. Use `listSheetsWithIds` to keep the mapping:

```php
$idsToTitles = $this->reports->listSheetsWithIds();
// [0 => 'Sheet1', 837423919 => 'Archive', ...]

$archiveTitle = $this->reports->findSheetNameById(837423919);
if (null === $archiveTitle) {
    throw new \RuntimeException('Archive tab missing');
}
$rows = $this->reports->readAssoc($archiveTitle);
```

## Read a sub-range only

When the sheet is huge but you only care about the first two columns of the first 1,000 rows:

```php
$rows = $this->allocators->readRaw(range: 'A1:B1000');
```

A1-notation ranges go through unchanged to the Sheets API. Passing just `A2:C` (no row bound) is also valid — Sheets reads to the last filled row in those columns.

## Bulk update a column

`update` writes a 2-D array into a range. To overwrite column B for rows 2 through 11:

```php
$values = array_map(static fn (int $i): array => [$computed[$i]], range(0, 9));
$this->allocators->update('B2:B11', $values);
```

## Use Sheets formulas / formatting

`valueInputOption: SheetsService::VALUE_INPUT_USER_ENTERED` makes the API interpret strings as the user would type them — so `'=SUM(A2:A10)'` becomes a real formula:

```php
$this->reports->append([
    ['Date', 'Total', 'Formula'],
    ['2026-01-01', 1234, '=B2*1.1'],
], valueInputOption: SheetsService::VALUE_INPUT_USER_ENTERED);
```

Default is `'RAW'`, which writes everything as a literal string.

## Archive a tab and create a fresh one

Useful for nightly imports where you want yesterday's tab preserved:

```php
public function rotateAllocators(): void
{
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $this->allocators->addSheet("Allocators $today");
    // ...write today's rows into the new tab via override...
    $this->allocators->append($rows, "Allocators $today");

    foreach ($this->allocators->listSheets() as $title) {
        if (str_starts_with($title, 'Allocators ') && $title < "Allocators $yesterday") {
            $this->allocators->deleteSheet($title);
        }
    }
}
```

Tab CRUD requires the `https://www.googleapis.com/auth/spreadsheets` scope; the read-only default won't be enough.

## Inspect spreadsheet metadata

```php
$props = $this->allocators->spreadsheetProperties();
$this->logger->info('Spreadsheet locale', ['locale' => $props->locale, 'timeZone' => $props->timeZone]);

$tab = $this->allocators->sheetProperties();
$this->logger->info('Bound tab shape', [
    'rows' => $tab->gridProperties->rowCount,
    'columns' => $tab->gridProperties->columnCount,
]);
```

## Tune read rendering

```php
// Get raw numeric values instead of formatted display strings
$rows = $this->reports->readRaw(valueRenderOption: SheetsService::VALUE_RENDER_UNFORMATTED);

// Get the formulas themselves
$formulas = $this->reports->readRaw(valueRenderOption: SheetsService::VALUE_RENDER_FORMULA);

// Walk the sheet column-first
$cols = $this->reports->readRaw(majorDimension: SheetsService::MAJOR_DIMENSION_COLUMNS);
```

## Reach for the underlying client

For batch operations, properties, or anything the high-level service doesn't cover:

```php
$client = $this->reports->client();
$client->spreadsheet($this->reports->getSpreadsheetId())->addSheet('Imports '.date('Y-m-d'));
```

Each `client()` call returns a fresh `SheetsClient`. State you set on the returned instance — `valueRenderOption()`, `majorDimension()`, etc. — does not leak to other consumers. See [architecture.md](architecture.md) for why.

## Discover all spreadsheets the credential can see

`listSpreadsheets()` is a global Drive query (not bound to any spreadsheet) — it lives on `SheetsClientFactory`:

```php
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;

public function __construct(private readonly SheetsClientFactory $factory) {}

public function listAvailable(): array
{
    return $this->factory->listSpreadsheets();
    // ['fileIdA' => 'My Spreadsheet', 'fileIdB' => 'Allocators', ...]
}
```

## Talk to a spreadsheet whose ID is only known at runtime

For dynamic IDs that come from user input, a database row, or per-tenant config, inject `SheetsClientFactory` and drive the client directly:

```php
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;

public function __construct(private readonly SheetsClientFactory $factory) {}

public function read(string $spreadsheetId, string $tab): array
{
    return $this->factory->create()
        ->spreadsheet($spreadsheetId)
        ->sheet($tab)
        ->all();
}
```

Each `create()` call returns a fresh `SheetsClient`, so consecutive reads against different spreadsheets do not leak state.

## Handle large exports without OOM

Symfony's Doctrine ORM tends to balloon under big exports. The pattern that works for tens of thousands of rows:

```php
$query = $em->getRepository(Allocator::class)->createQueryBuilder('a')->getQuery();
$rows = [];
foreach ($query->toIterable() as $i => $allocator) {
    $rows[] = $this->rowFor($allocator);
    if (0 === $i % 500) {
        $em->clear(); // flush identity map
    }
}
$this->reports->update('A1', $rows);
```

Update sends one batch request, so build the array in memory first and update once instead of appending 10k rows individually.
