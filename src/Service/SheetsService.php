<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Service;

use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets\BatchUpdateValuesResponse;
use Google\Service\Sheets\ClearValuesResponse;
use Revolution\Google\Sheets\SheetsClient;

/**
 * High-level wrapper around `SheetsClient` with method calls that select the
 * target spreadsheet and tab upfront. Each public method runs against a fresh
 * selection so callers cannot accidentally leak state between calls.
 */
final class SheetsService
{
    public function __construct(private readonly SheetsClient $client)
    {
    }

    /**
     * Read all rows from a sheet (or sub-range) as raw indexed arrays.
     *
     * @return list<list<mixed>>
     */
    public function readRaw(string $spreadsheetId, string $sheetName, ?string $range = null): array
    {
        $selection = $this->client
            ->spreadsheet($spreadsheetId)
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
     */
    public function readAssoc(string $spreadsheetId, string $sheetName, ?string $range = null): array
    {
        $rows = $this->readRaw($spreadsheetId, $sheetName, $range);

        if ([] === $rows) {
            return [];
        }

        /** @var list<string> $header */
        $header = array_map(static fn (mixed $cell): string => (string) $cell, array_shift($rows));

        if ([] === $header) {
            return [];
        }

        $count = count($header);
        $result = [];

        foreach ($rows as $row) {
            /** @var list<mixed> $padded */
            $padded = array_pad(array_values($row), $count, '');
            $padded = array_slice($padded, 0, $count);

            /** @var array<string, mixed> $combined */
            $combined = array_combine($header, $padded);
            $result[] = $combined;
        }

        return $result;
    }

    /**
     * List all sheet (tab) names in a spreadsheet.
     *
     * @return list<string>
     */
    public function listSheets(string $spreadsheetId): array
    {
        /** @var list<string> $names */
        $names = $this->client->spreadsheet($spreadsheetId)->sheetList();

        return array_values($names);
    }

    /**
     * Append rows to the end of a sheet.
     *
     * Rows may be positional (`list<list<mixed>>`) or associative
     * (`list<array<string, mixed>>`); associative rows are reordered to match
     * the sheet header by the underlying client.
     *
     * @param list<array<string, mixed>>|list<list<mixed>> $rows
     */
    public function append(
        string $spreadsheetId,
        string $sheetName,
        array $rows,
        string $valueInputOption = 'RAW',
        string $insertDataOption = 'OVERWRITE',
    ): AppendValuesResponse {
        return $this->client
            ->spreadsheet($spreadsheetId)
            ->sheet($sheetName)
            ->append($rows, $valueInputOption, $insertDataOption);
    }

    /**
     * Update a range with the given values.
     *
     * @param list<list<mixed>> $values
     */
    public function update(
        string $spreadsheetId,
        string $sheetName,
        string $range,
        array $values,
        string $valueInputOption = 'RAW',
    ): BatchUpdateValuesResponse {
        return $this->client
            ->spreadsheet($spreadsheetId)
            ->sheet($sheetName)
            ->range($range)
            ->update($values, $valueInputOption);
    }

    /**
     * Clear a range or the whole sheet (when `$range` is null).
     */
    public function clear(string $spreadsheetId, string $sheetName, ?string $range = null): ?ClearValuesResponse
    {
        $selection = $this->client
            ->spreadsheet($spreadsheetId)
            ->sheet($sheetName);

        if (null !== $range) {
            $selection = $selection->range($range);
        }

        return $selection->clear();
    }

    /**
     * Escape hatch returning the underlying `SheetsClient` for operations not
     * covered by this service.
     */
    public function client(): SheetsClient
    {
        return $this->client;
    }
}
