<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Service;

use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets\BatchUpdateValuesResponse;
use Google\Service\Sheets\ClearValuesResponse;
use Gulaandrij\GoogleSheetsBundle\Exception\DuplicateHeaderException;
use Gulaandrij\GoogleSheetsBundle\Exception\InvalidHeaderException;
use Gulaandrij\GoogleSheetsBundle\Exception\MixedRowShapeException;
use Revolution\Google\Sheets\SheetsClient;

/**
 * High-level wrapper around `SheetsClient`, bound to a single spreadsheet.
 *
 * The bundle creates one instance per `google_sheets.spreadsheets.<name>`
 * config entry; inject by variable name (e.g. `SheetsService $allocators`)
 * to receive the instance bound to that spreadsheet. Each public method runs
 * against a fresh `SheetsClient` from the factory so stateful selectors
 * (`range`, `majorDimension`, `valueRenderOption`, `dateTimeRenderOption`)
 * never leak between calls or between consumers.
 *
 * For dynamic spreadsheet IDs (only known at runtime), inject
 * `SheetsClientFactory` instead and drive the client directly.
 */
final class SheetsService
{
    public function __construct(
        private readonly SheetsClientFactory $factory,
        private readonly string $spreadsheetId,
    ) {
    }

    public function getSpreadsheetId(): string
    {
        return $this->spreadsheetId;
    }

    /**
     * Read all rows from a sheet (or sub-range) as raw indexed arrays.
     *
     * @return list<list<mixed>>
     */
    public function readRaw(string $sheetName, ?string $range = null): array
    {
        $selection = $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->sheet($sheetName);

        if (null !== $range) {
            $selection = $selection->range($range);
        }

        /** @var list<list<mixed>> $rows */
        $rows = $selection->all();

        return $rows;
    }

    /**
     * Read rows from a sheet treating the first row as a header, returning
     * associative arrays keyed by the header values.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DuplicateHeaderException when the first row contains a duplicate cell value
     * @throws InvalidHeaderException   when a header cell is not a scalar/null
     */
    public function readAssoc(string $sheetName, ?string $range = null): array
    {
        $rows = $this->readRaw($sheetName, $range);

        if ([] === $rows) {
            return [];
        }

        $header = $this->normaliseHeader(array_shift($rows));

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

    /**
     * List all sheet (tab) names in the bound spreadsheet.
     *
     * @return list<string>
     */
    public function listSheets(): array
    {
        return array_values($this->listSheetsWithIds());
    }

    /**
     * List all sheets in the bound spreadsheet as a `sheetId => title` map.
     *
     * @return array<int, string>
     */
    public function listSheetsWithIds(): array
    {
        /** @var array<int, string> $map */
        $map = $this->factory->create()->spreadsheet($this->spreadsheetId)->sheetList();

        return $map;
    }

    /**
     * Append rows to the end of a sheet.
     *
     * Rows must all share the same shape: either all positional
     * (`list<list<mixed>>`) or all associative (`list<array<string,mixed>>`).
     * Associative rows are reordered to match the sheet header by the
     * underlying client; mixing shapes silently drops data, so it is rejected
     * upfront.
     *
     * @param list<array<string, mixed>>|list<list<mixed>> $rows
     *
     * @throws MixedRowShapeException when $rows mixes positional and associative entries
     */
    public function append(
        string $sheetName,
        array $rows,
        string $valueInputOption = 'RAW',
        string $insertDataOption = 'OVERWRITE',
    ): AppendValuesResponse {
        $this->assertHomogeneousRows($rows);

        return $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->sheet($sheetName)
            ->append($rows, $valueInputOption, $insertDataOption);
    }

    /**
     * Update a range with the given values.
     *
     * @param list<list<mixed>> $values
     */
    public function update(
        string $sheetName,
        string $range,
        array $values,
        string $valueInputOption = 'RAW',
    ): BatchUpdateValuesResponse {
        return $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->sheet($sheetName)
            ->range($range)
            ->update($values, $valueInputOption);
    }

    /**
     * Clear a range or the whole sheet (when `$range` is null).
     */
    public function clear(string $sheetName, ?string $range = null): ?ClearValuesResponse
    {
        $selection = $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->sheet($sheetName);

        if (null !== $range) {
            $selection = $selection->range($range);
        }

        return $selection->clear();
    }

    /**
     * Return a fresh `SheetsClient` for operations not covered by this service.
     * Each call constructs a new client so callers cannot leak state into other
     * consumers of the bundle.
     */
    public function client(): SheetsClient
    {
        return $this->factory->create();
    }

    /**
     * @param list<mixed> $headerRow
     *
     * @return list<string>
     */
    private function normaliseHeader(array $headerRow): array
    {
        $header = [];

        foreach ($headerRow as $index => $cell) {
            if (null !== $cell && !is_scalar($cell)) {
                throw InvalidHeaderException::nonScalarCell($index, get_debug_type($cell));
            }
            $header[] = (string) $cell;
        }

        $seen = [];
        foreach ($header as $name) {
            if (isset($seen[$name])) {
                throw DuplicateHeaderException::forName($name);
            }
            $seen[$name] = true;
        }

        return $header;
    }

    /**
     * @param list<array<string, mixed>>|list<list<mixed>> $rows
     */
    private function assertHomogeneousRows(array $rows): void
    {
        if ([] === $rows) {
            return;
        }

        $firstIsAssoc = $this->isAssoc($rows[0]);

        foreach ($rows as $index => $row) {
            if ($this->isAssoc($row) !== $firstIsAssoc) {
                throw MixedRowShapeException::atIndex($index, $firstIsAssoc);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $row
     */
    private function isAssoc(array $row): bool
    {
        if ([] === $row) {
            return false;
        }

        return array_keys($row) !== range(0, count($row) - 1);
    }
}
