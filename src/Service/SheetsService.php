<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Service;

use Google\Service\Drive;
use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets\BatchUpdateSpreadsheetResponse;
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
    public const MAJOR_DIMENSION_ROWS = 'ROWS';
    public const MAJOR_DIMENSION_COLUMNS = 'COLUMNS';

    public const VALUE_RENDER_FORMATTED = 'FORMATTED_VALUE';
    public const VALUE_RENDER_UNFORMATTED = 'UNFORMATTED_VALUE';
    public const VALUE_RENDER_FORMULA = 'FORMULA';

    public const DATE_TIME_RENDER_SERIAL = 'SERIAL_NUMBER';
    public const DATE_TIME_RENDER_FORMATTED = 'FORMATTED_STRING';

    public const VALUE_INPUT_RAW = 'RAW';
    public const VALUE_INPUT_USER_ENTERED = 'USER_ENTERED';

    public const INSERT_DATA_OVERWRITE = 'OVERWRITE';
    public const INSERT_DATA_INSERT_ROWS = 'INSERT_ROWS';

    public function __construct(
        private readonly SheetsClientFactory $factory,
        private readonly string $spreadsheetId,
    ) {
    }

    public function getSpreadsheetId(): string
    {
        return $this->spreadsheetId;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * Read all rows from a sheet (or sub-range) as raw indexed arrays.
     *
     * @return list<list<mixed>>
     */
    public function readRaw(
        string $sheetName,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        $selection = $this->applyReadOptions(
            $this->factory->create()->spreadsheet($this->spreadsheetId)->sheet($sheetName),
            $range,
            $majorDimension,
            $valueRenderOption,
            $dateTimeRenderOption,
        );

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
    public function readAssoc(
        string $sheetName,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        $rows = $this->readRaw($sheetName, $range, $majorDimension, $valueRenderOption, $dateTimeRenderOption);

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
     * Read just the first row of a sheet (or sub-range).
     *
     * @return list<mixed>
     */
    public function firstRow(
        string $sheetName,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        $selection = $this->applyReadOptions(
            $this->factory->create()->spreadsheet($this->spreadsheetId)->sheet($sheetName),
            $range,
            $majorDimension,
            $valueRenderOption,
            $dateTimeRenderOption,
        );

        /** @var list<mixed> $first */
        $first = $selection->first();

        return $first;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

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
        string $valueInputOption = self::VALUE_INPUT_RAW,
        string $insertDataOption = self::INSERT_DATA_OVERWRITE,
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
        string $valueInputOption = self::VALUE_INPUT_RAW,
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

    // ------------------------------------------------------------------
    // Tab CRUD
    // ------------------------------------------------------------------

    /**
     * Create a new tab in the bound spreadsheet.
     */
    public function addSheet(string $title): BatchUpdateSpreadsheetResponse
    {
        return $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->addSheet($title);
    }

    /**
     * Delete a tab from the bound spreadsheet.
     */
    public function deleteSheet(string $title): BatchUpdateSpreadsheetResponse
    {
        return $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->deleteSheet($title);
    }

    // ------------------------------------------------------------------
    // Discovery
    // ------------------------------------------------------------------

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
     * Resolve a stable sheet ID to its current title.
     */
    public function findSheetNameById(int $sheetId): ?string
    {
        return $this->listSheetsWithIds()[$sheetId] ?? null;
    }

    /**
     * List every Google Sheets file the credential can see, as a
     * `fileId => title` map. Requires a Drive read scope on the credential.
     *
     * Note: this is a global Drive query — it ignores the bound spreadsheet ID
     * and lists all spreadsheets visible to the credential. Convenient for
     * discovery, but cross-cuts the per-spreadsheet binding.
     *
     * @return array<string, string>
     */
    public function listSpreadsheets(): array
    {
        /** @var array<string, string> $list */
        $list = $this->factory->create()->spreadsheetList();

        return $list;
    }

    // ------------------------------------------------------------------
    // Metadata
    // ------------------------------------------------------------------

    /**
     * Get metadata about the bound spreadsheet (title, locale, timezone, etc.).
     */
    public function spreadsheetProperties(): object
    {
        return $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->spreadsheetProperties();
    }

    /**
     * Get metadata about a single tab (gridProperties, index, sheetType, etc.).
     */
    public function sheetProperties(string $sheetName): object
    {
        return $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->sheet($sheetName)
            ->sheetProperties();
    }

    // ------------------------------------------------------------------
    // Escape hatches
    // ------------------------------------------------------------------

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
     * Return the shared `Google\Service\Drive` instance for Drive-level
     * operations (file metadata, permissions, etc.) not covered by this
     * service.
     */
    public function driveService(): Drive
    {
        return $this->factory->create()->getDriveService();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function applyReadOptions(
        SheetsClient $selection,
        ?string $range,
        ?string $majorDimension,
        ?string $valueRenderOption,
        ?string $dateTimeRenderOption,
    ): SheetsClient {
        if (null !== $range) {
            $selection = $selection->range($range);
        }
        if (null !== $majorDimension) {
            $selection = $selection->majorDimension($majorDimension);
        }
        if (null !== $valueRenderOption) {
            $selection = $selection->valueRenderOption($valueRenderOption);
        }
        if (null !== $dateTimeRenderOption) {
            $selection = $selection->dateTimeRenderOption($dateTimeRenderOption);
        }

        return $selection;
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

        // array_find_key is PHP 8.4; polyfilled via symfony/polyfill-php84 for 8.3 hosts.
        $mismatch = array_find_key(
            $rows,
            fn (array $row): bool => $this->isAssoc($row) !== $firstIsAssoc,
        );

        if (null !== $mismatch) {
            throw MixedRowShapeException::atIndex($mismatch, $firstIsAssoc);
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
