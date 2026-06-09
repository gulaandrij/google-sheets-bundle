<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Test;

use Generator;
use Google\Client as GoogleClient;
use Google\Service\Drive;
use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetResponse;
use Google\Service\Sheets\BatchUpdateValuesResponse;
use Google\Service\Sheets\ClearValuesResponse;
use Gulaandrij\GoogleSheetsBundle\Exception\DuplicateHeaderException;
use Gulaandrij\GoogleSheetsBundle\Exception\InvalidHeaderException;
use Gulaandrij\GoogleSheetsBundle\Exception\MissingSheetNameException;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use LogicException;
use Revolution\Google\Sheets\SheetsClient;

/**
 * A test-friendly drop-in for `SheetsService` backed by an in-memory map of
 * `sheetName => list<list<mixed>>`. Use it in your project's functional and
 * unit tests instead of mocking `SheetsService` directly — read/append/clear
 * all operate on the seeded data with no Google API calls.
 *
 * Wiring in a Symfony test container:
 *
 *     // tests/Functional/MyControllerTest.php
 *     self::getContainer()->set(
 *         'google_sheets.sheets_service.allocators',
 *         new InMemorySheetsService(['Allocator List' => [
 *             ['Name', 'Email'],
 *             ['Alice', 'a@x'],
 *         ]]),
 *     );
 *
 * @phpstan-type SheetData list<list<mixed>>
 */
final class InMemorySheetsService extends SheetsService
{
    /**
     * @var array<string, SheetData>
     */
    private array $sheets;

    /**
     * @var array<int, string> Stable sheetId → title map (or empty for positional fallback)
     */
    private array $sheetIds;

    /**
     * @param array<string, SheetData> $sheets        seed data — `sheetName => rows` where the first row is the header
     * @param string                   $spreadsheetId synthetic spreadsheet ID returned by getSpreadsheetId()
     * @param string|null              $boundSheet    optional bound sheet, mirroring `SheetsService::getBoundSheet()`
     * @param array<int, string>       $sheetIds      optional `sheetId => title` map for tests that exercise stable-ID
     *                                                lookups (real Google sheets use random ints, not positional indices);
     *                                                if omitted, listSheetsWithIds/findSheetNameById fall back to positional indices
     */
    public function __construct(
        array $sheets = [],
        string $spreadsheetId = 'in-memory',
        ?string $boundSheet = null,
        array $sheetIds = [],
    ) {
        // Build a real SheetsClientFactory pointing at fresh, un-authenticated
        // Google services. None of the overridden methods reach into it — the
        // factory exists solely so the parent constructor's required arg is
        // satisfied. `new Google\Client()` performs no network I/O.
        $googleClient = new GoogleClient();
        $factory = new SheetsClientFactory(
            new GoogleSheets($googleClient),
            new Drive($googleClient),
        );

        parent::__construct($factory, $spreadsheetId, $boundSheet);

        $this->sheets = $sheets;
        $this->sheetIds = $sheetIds;
    }

    /**
     * Replace (or add) the data for a single sheet/tab.
     *
     * @param SheetData $rows
     */
    public function seed(string $sheetName, array $rows): void
    {
        $this->sheets[$sheetName] = $rows;
    }

    /**
     * @return array<string, SheetData>
     */
    public function dump(): array
    {
        return $this->sheets;
    }

    public function readRaw(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        $name = $this->resolveSheet($sheetName);

        return $this->sheets[$name] ?? [];
    }

    public function readAssoc(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        $rows = $this->readRaw($sheetName, $range);
        if ([] === $rows) {
            return [];
        }

        /** @var list<mixed> $headerRow */
        $headerRow = array_shift($rows);
        $header = [];
        $seen = [];
        foreach ($headerRow as $index => $cell) {
            if (null !== $cell && !is_scalar($cell)) {
                throw InvalidHeaderException::nonScalarCell($index, get_debug_type($cell));
            }
            $name = (string) $cell;
            if (isset($seen[$name])) {
                throw DuplicateHeaderException::forName($name);
            }
            $seen[$name] = true;
            $header[] = $name;
        }

        if ([] === $header) {
            return [];
        }

        $count = count($header);
        $result = [];
        foreach ($rows as $row) {
            $padded = array_pad(array_values($row), $count, '');
            $padded = array_slice($padded, 0, $count);
            /** @var array<string, mixed> $combined */
            $combined = array_combine($header, $padded);
            $result[] = $combined;
        }

        return $result;
    }

    public function firstRow(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        $rows = $this->readRaw($sheetName);

        /** @var list<mixed> $first */
        $first = $rows[0] ?? [];

        return $first;
    }

    public function readEntities(string $className, ?string $sheetName = null, ?string $range = null): array
    {
        throw new LogicException('InMemorySheetsService does not implement readEntities(); seed and assert against readAssoc() data directly in tests.');
    }

    public function readAssocIterable(?string $sheetName = null, int $batchSize = 500): Generator
    {
        yield from $this->readAssoc($sheetName);
    }

    public function listSheets(): array
    {
        return array_keys($this->sheets);
    }

    public function listSheetsWithIds(): array
    {
        if ([] !== $this->sheetIds) {
            return $this->sheetIds;
        }

        $map = [];
        $i = 0;
        foreach (array_keys($this->sheets) as $name) {
            $map[$i++] = $name;
        }

        return $map;
    }

    public function findSheetNameById(int $sheetId): ?string
    {
        return $this->listSheetsWithIds()[$sheetId] ?? null;
    }

    public function append(
        array $rows,
        ?string $sheetName = null,
        string $valueInputOption = self::VALUE_INPUT_RAW,
        string $insertDataOption = self::INSERT_DATA_OVERWRITE,
    ): AppendValuesResponse {
        $name = $this->resolveSheet($sheetName);
        $existing = $this->sheets[$name] ?? [];

        // For assoc rows we need a header to know column order — mirror the
        // real client's behaviour: if there's no existing header, fall back to
        // the assoc keys; otherwise reorder by header.
        foreach ($rows as $row) {
            if ($this->isAssocRow($row)) {
                /** @var array<string, mixed> $assocRow */
                $assocRow = $row;
                $existing[] = $this->reorderByHeader($name, $assocRow);
            } else {
                $existing[] = array_values($row);
            }
        }

        $this->sheets[$name] = $existing;

        return new AppendValuesResponse();
    }

    public function update(
        string $range,
        array $values,
        ?string $sheetName = null,
        string $valueInputOption = self::VALUE_INPUT_RAW,
    ): BatchUpdateValuesResponse {
        // Simplified semantics: replace the entire sheet with the given values.
        // Range-aware updates are out of scope for the in-memory fake.
        $name = $this->resolveSheet($sheetName);
        $this->sheets[$name] = $values;

        return new BatchUpdateValuesResponse();
    }

    public function clear(?string $sheetName = null, ?string $range = null): ClearValuesResponse
    {
        $name = $this->resolveSheet($sheetName);
        $this->sheets[$name] = [];

        return new ClearValuesResponse();
    }

    public function addSheet(string $title): BatchUpdateSpreadsheetResponse
    {
        $this->sheets[$title] ??= [];

        return new BatchUpdateSpreadsheetResponse();
    }

    public function deleteSheet(string $title): BatchUpdateSpreadsheetResponse
    {
        unset($this->sheets[$title]);

        return new BatchUpdateSpreadsheetResponse();
    }

    public function spreadsheetProperties(): object
    {
        return (object) ['title' => $this->getSpreadsheetId()];
    }

    public function sheetProperties(?string $sheetName = null): object
    {
        return (object) ['title' => $this->resolveSheet($sheetName)];
    }

    public function client(): SheetsClient
    {
        throw new LogicException('InMemorySheetsService does not expose a real SheetsClient; use the real bundle in integration tests if you need the escape hatch.');
    }

    public function driveService(): Drive
    {
        throw new LogicException('InMemorySheetsService does not expose a real Drive client.');
    }

    private function resolveSheet(?string $sheetName): string
    {
        $sheet = $sheetName ?? $this->getBoundSheet();
        if (null === $sheet) {
            throw MissingSheetNameException::create();
        }

        return $sheet;
    }

    /**
     * @param array<int|string, mixed> $row
     */
    private function isAssocRow(array $row): bool
    {
        return [] !== $row && !array_is_list($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<mixed>
     */
    private function reorderByHeader(string $sheetName, array $row): array
    {
        $existing = $this->sheets[$sheetName] ?? [];
        if ([] === $existing) {
            return array_values($row);
        }

        /** @var list<mixed> $headerRow */
        $headerRow = $existing[0];
        $reordered = [];
        foreach ($headerRow as $column) {
            $key = is_scalar($column) ? (string) $column : '';
            $reordered[] = $row[$key] ?? '';
        }

        return $reordered;
    }
}
