# Recipes

Common patterns for using `SheetsService` in real applications. All snippets assume the service has been autowired into your class:

```php
public function __construct(private readonly SheetsService $sheets) {}
```

## Import a sheet as typed objects

Combine `readAssoc` with the Symfony Serializer to map header-keyed rows onto DTOs:

```php
use App\Dto\Allocator;
use Symfony\Component\Serializer\SerializerInterface;

public function importAllocators(string $spreadsheetId): void
{
    $rows = $this->sheets->readAssoc($spreadsheetId, 'Allocator List');
    /** @var Allocator[] $allocators */
    $allocators = $this->serializer->denormalize($rows, Allocator::class.'[]');

    foreach ($allocators as $allocator) {
        $this->entityManager->persist($allocator);
    }
    $this->entityManager->flush();
}
```

If your header has friendly names like `"Record ID - Contact"` that don't map to DTO property names, use the serializer's `name_converter` or `#[SerializedName]` attribute on the DTO.

## Sync new records up to a sheet

Append rows that aren't already present. The associative form lets you write the column-to-value mapping inline:

```php
public function syncToSheet(string $spreadsheetId): void
{
    $existing = $this->sheets->readAssoc($spreadsheetId, 'Allocator List');
    $existingIds = array_column($existing, 'Record ID - Contact');

    $newRows = array_filter(
        $this->loadAllocators(),
        static fn (Allocator $a): bool => !in_array($a->getId(), $existingIds, true),
    );

    if ([] === $newRows) {
        return;
    }

    $this->sheets->append($spreadsheetId, 'Allocator List', array_map(
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

The underlying client reorders associative rows to match the sheet header. Just make sure every row has the same shape — `MixedRowShapeException` fires immediately if some rows are positional.

## Schedule a nightly export

Pair with Symfony Scheduler. A command annotated with `#[AsCronTask]` runs on a cron expression and stays out of the request lifecycle. This composes nicely with the bundle because `SheetsService` is a regular autowired service.

```php
#[AsCommand(name: 'app:sheets:nightly-export')]
#[AsCronTask('0 4 * * *')]
final class NightlyExportCommand extends Command
{
    public function __construct(private readonly SheetsService $sheets) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->buildExportRows();
        $this->sheets->clear('1abc...XYZ', 'Daily Export');
        $this->sheets->update('1abc...XYZ', 'Daily Export', 'A1', $rows);
        return Command::SUCCESS;
    }
}
```

Pair with Sentry check-ins if you want monitoring (see your project's `CLAUDE.md` for the exact pattern your codebase uses).

## Look up a tab by ID, not by name

Tab IDs are stable across renames; tab names are not. Use `listSheetsWithIds` to keep the mapping:

```php
$idsToTitles = $this->sheets->listSheetsWithIds('1abc...XYZ');
// [0 => 'Sheet1', 837423919 => 'Archive', ...]

$archiveTitle = $idsToTitles[837423919] ?? null;
if (null === $archiveTitle) {
    throw new \RuntimeException('Archive tab missing');
}
$rows = $this->sheets->readAssoc('1abc...XYZ', $archiveTitle);
```

If the user renames "Archive" to "Old Records", the sheetId stays `837423919` so your job keeps working.

## Read a sub-range only

When the sheet is huge but you only care about the first two columns of the first 1,000 rows:

```php
$rows = $this->sheets->readRaw('1abc...XYZ', 'Allocator List', 'A1:B1000');
```

A1-notation ranges go through unchanged to the Sheets API. Passing just `A2:C` (no row bound) is also valid — Sheets reads to the last filled row in those columns.

## Bulk update a column

`update` writes a 2-D array into a range. To overwrite column B for rows 2 through 11:

```php
$values = array_map(static fn (int $i): array => [$computed[$i]], range(0, 9));
$this->sheets->update('1abc...XYZ', 'Allocator List', 'B2:B11', $values);
```

Each inner array is one row; one cell per inner array because the range is one column wide.

## Use Sheets formulas / formatting

`valueInputOption: 'USER_ENTERED'` makes the API interpret strings as the user would type them — so `'=SUM(A2:A10)'` becomes a real formula, `'$1,234'` becomes a currency-formatted number, etc.:

```php
$this->sheets->append('1abc...XYZ', 'Stats', [
    ['Date', 'Total', 'Formula'],
    ['2026-01-01', 1234, '=B2*1.1'],
], 'USER_ENTERED');
```

Default is `'RAW'`, which writes everything as a literal string.

## Reach for the underlying client

For batch operations, properties, or anything the high-level service doesn't cover:

```php
$client = $this->sheets->client();
$client->spreadsheet('1abc...XYZ')->addSheet('Imports '.date('Y-m-d'));
```

Each `client()` call returns a fresh `SheetsClient`. State you set on the returned instance — `valueRenderOption()`, `majorDimension()`, etc. — does not leak to other consumers. See [architecture.md](architecture.md) for why.

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
$this->sheets->update('1abc...XYZ', 'Export', 'A1', $rows);
```

Update sends one batch request, so build the array in memory first and update once instead of appending 10k rows individually.
