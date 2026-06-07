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
use Gulaandrij\GoogleSheetsBundle\Exception\MissingSheetNameException;
use Gulaandrij\GoogleSheetsBundle\Exception\MixedRowShapeException;
use Revolution\Google\Sheets\SheetsClient;

/**
 * High-level wrapper around `SheetsClient`, bound to a single spreadsheet and
 * (optionally) a single default sheet/tab.
 *
 * The bundle creates one instance per `google_sheets.spreadsheets.<name>`
 * config entry; inject by variable name (e.g. `SheetsService $allocators`)
 * to receive the instance bound to that spreadsheet. Each public method runs
 * against a fresh `SheetsClient` from the factory so stateful selectors
 * (`range`, `majorDimension`, `valueRenderOption`, `dateTimeRenderOption`)
 * never leak between calls or between consumers.
 *
 * Methods that operate on a sheet/tab take `?string $sheetName` — pass null
 * to use the bound default sheet (set via `google_sheets.spreadsheets.<name>.sheet`);
 * pass a string to target a different tab. If neither is set the method
 * throws `MissingSheetNameException`.
 *
 * For dynamic spreadsheet IDs (only known at runtime), inject
 * `SheetsClientFactory` instead and drive the client directly.
 */
class SheetsService
{
    public const VALUE_RENDER_FORMATTED = 'FORMATTED_VALUE';
    public const VALUE_RENDER_UNFORMATTED = 'UNFORMATTED_VALUE';
    public const VALUE_RENDER_FORMULA = 'FORMULA';

    public const DATE_TIME_RENDER_SERIAL = 'SERIAL_NUMBER';
    public const DATE_TIME_RENDER_FORMATTED = 'FORMATTED_STRING';

    public const MAJOR_DIMENSION_ROWS = 'ROWS';
    public const MAJOR_DIMENSION_COLUMNS = 'COLUMNS';

    public const VALUE_INPUT_RAW = 'RAW';
    public const VALUE_INPUT_USER_ENTERED = 'USER_ENTERED';

    public const INSERT_DATA_OVERWRITE = 'OVERWRITE';
    public const INSERT_DATA_INSERT_ROWS = 'INSERT_ROWS';

    public function __construct(
        private readonly SheetsClientFactory $factory,
        private readonly string $spreadsheetId,
        private readonly ?string $boundSheet = null,
    ) {
    }

    public function getSpreadsheetId(): string
    {
        return $this->spreadsheetId;
    }

    public function getBoundSheet(): ?string
    {
        return $this->boundSheet;
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
        ?string $sheetName = null,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        return $this->doReadRaw($sheetName, $range, $majorDimension, $valueRenderOption, $dateTimeRenderOption);
    }

    /**
     * @return list<list<mixed>>
     */
    private function doReadRaw(
        ?string $sheetName,
        ?string $range,
        ?string $majorDimension,
        ?string $valueRenderOption,
        ?string $dateTimeRenderOption,
    ): array {
        $selection = $this->applyReadOptions(
            $this->factory->create()->spreadsheet($this->spreadsheetId)->sheet($this->resolveSheetName($sheetName)),
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
        ?string $sheetName = null,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        // Call the private helper directly so a TraceableSheetsService subclass
        // doesn't double-record (readAssoc + the inner readRaw).
        $rows = $this->doReadRaw($sheetName, $range, $majorDimension, $valueRenderOption, $dateTimeRenderOption);

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
     * Read just the first row of a sheet (or sub-range). `majorDimension` is
     * deliberately not exposed — under `COLUMNS` it would return the first
     * column, contradicting the method name.
     *
     * @return list<mixed>
     */
    public function firstRow(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        $selection = $this->applyReadOptions(
            $this->factory->create()->spreadsheet($this->spreadsheetId)->sheet($this->resolveSheetName($sheetName)),
            $range,
            null,
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
     * (`list<list<mixed>>`) or all associative with identical key sets
     * (`list<array<string,mixed>>`). Mixed shapes throw
     * `MixedRowShapeException`; assoc rows with divergent key sets also
     * throw — the underlying client would silently drop values whose keys
     * are missing from the first row's header derivation.
     *
     * @param list<array<string, mixed>>|list<list<mixed>> $rows
     *
     * @throws MixedRowShapeException when $rows mixes shapes or assoc rows have inconsistent keys
     */
    public function append(
        array $rows,
        ?string $sheetName = null,
        string $valueInputOption = self::VALUE_INPUT_RAW,
        string $insertDataOption = self::INSERT_DATA_OVERWRITE,
    ): AppendValuesResponse {
        $this->assertHomogeneousRows($rows);

        return $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->sheet($this->resolveSheetName($sheetName))
            ->append($rows, $valueInputOption, $insertDataOption);
    }

    /**
     * Update a range with the given values.
     *
     * @param list<list<mixed>> $values
     */
    public function update(
        string $range,
        array $values,
        ?string $sheetName = null,
        string $valueInputOption = self::VALUE_INPUT_RAW,
    ): BatchUpdateValuesResponse {
        return $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->sheet($this->resolveSheetName($sheetName))
            ->range($range)
            ->update($values, $valueInputOption);
    }

    /**
     * Clear a range or the whole sheet (when `$range` is null).
     */
    public function clear(?string $sheetName = null, ?string $range = null): ?ClearValuesResponse
    {
        $selection = $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->sheet($this->resolveSheetName($sheetName));

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
        // Call the private helper directly so a TraceableSheetsService subclass
        // doesn't double-record (listSheets + the inner listSheetsWithIds).
        return array_values($this->doListSheetsWithIds());
    }

    /**
     * List all sheets in the bound spreadsheet as a `sheetId => title` map.
     *
     * @return array<int, string>
     */
    public function listSheetsWithIds(): array
    {
        return $this->doListSheetsWithIds();
    }

    /**
     * @return array<int, string>
     */
    private function doListSheetsWithIds(): array
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
        // Call the private helper directly so a TraceableSheetsService subclass
        // doesn't double-record (findSheetNameById + the inner listSheetsWithIds).
        return $this->doListSheetsWithIds()[$sheetId] ?? null;
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
    public function sheetProperties(?string $sheetName = null): object
    {
        return $this->factory->create()
            ->spreadsheet($this->spreadsheetId)
            ->sheet($this->resolveSheetName($sheetName))
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

    /**
     * @throws MissingSheetNameException
     */
    private function resolveSheetName(?string $explicit): string
    {
        $sheet = $explicit ?? $this->boundSheet;
        if (null === $sheet) {
            throw MissingSheetNameException::create();
        }

        return $sheet;
    }

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

        // First pass: every row must share the same shape (assoc vs positional).
        // array_find_key is PHP 8.4; polyfilled via symfony/polyfill-php84 for 8.3 hosts.
        $shapeMismatch = array_find_key(
            $rows,
            fn (array $row): bool => $this->isAssoc($row) !== $firstIsAssoc,
        );
        if (null !== $shapeMismatch) {
            throw MixedRowShapeException::atIndex($shapeMismatch, $firstIsAssoc);
        }

        if (!$firstIsAssoc) {
            return;
        }

        // Second pass: every assoc row must share the first row's key set.
        $firstKeys = array_keys($rows[0]);
        $firstKeysFlipped = array_flip($firstKeys);

        foreach ($rows as $index => $row) {
            if (0 === $index) {
                continue;
            }
            $rowKeys = array_keys($row);
            if ($rowKeys === $firstKeys) {
                continue;
            }
            $extra = array_values(array_diff($rowKeys, $firstKeys));
            $missing = array_values(array_diff(array_keys($firstKeysFlipped), $rowKeys));
            /** @var list<string> $extraStrings */
            $extraStrings = array_map(static fn (int|string $k): string => (string) $k, $extra);
            /** @var list<string> $missingStrings */
            $missingStrings = array_map(static fn (int|string $k): string => (string) $k, $missing);
            throw MixedRowShapeException::divergentAssocKeys($index, $extraStrings, $missingStrings);
        }
    }

    /**
     * @param array<int|string, mixed> $row
     */
    private function isAssoc(array $row): bool
    {
        return [] !== $row && !array_is_list($row);
    }
}
