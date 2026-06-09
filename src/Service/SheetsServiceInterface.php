<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Service;

use Generator;
use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets\BatchUpdateSpreadsheetResponse;
use Google\Service\Sheets\BatchUpdateValuesResponse;
use Google\Service\Sheets\ClearValuesResponse;
use Gulaandrij\GoogleSheetsBundle\Exception\DuplicateHeaderException;
use Gulaandrij\GoogleSheetsBundle\Exception\InvalidHeaderException;
use Gulaandrij\GoogleSheetsBundle\Exception\MissingSheetNameException;
use Gulaandrij\GoogleSheetsBundle\Exception\MixedRowShapeException;

/**
 * Consumer-facing contract for the bound-spreadsheet sheets service.
 *
 * Type-hint against this interface (instead of the concrete
 * {@see SheetsService}) when you want production code to be decoupled from the
 * decorated implementation — the bundle wires `TraceableSheetsService` in
 * debug mode, `CachedSheetsService` when caching is enabled, and plain
 * `SheetsService` otherwise. Tests can then drop in the
 * {@see \Gulaandrij\GoogleSheetsBundle\Test\InMemorySheetsService} fake against
 * the same interface.
 *
 * Escape hatches (`client()`, `driveService()`) intentionally live on the
 * concrete `SheetsService` only — they leak implementation detail and would
 * force every fake to expose a real Google client.
 */
interface SheetsServiceInterface
{
    public function getSpreadsheetId(): string;

    public function getBoundSheet(): ?string;

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * @return list<list<mixed>>
     */
    public function readRaw(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array;

    /**
     * @return list<array<string, mixed>>
     *
     * @throws DuplicateHeaderException
     * @throws InvalidHeaderException
     */
    public function readAssoc(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array;

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return list<T>
     */
    public function readEntities(
        string $className,
        ?string $sheetName = null,
        ?string $range = null,
    ): array;

    /**
     * @return Generator<int, array<string, mixed>>
     *
     * @throws DuplicateHeaderException
     * @throws InvalidHeaderException
     */
    public function readAssocIterable(
        ?string $sheetName = null,
        int $batchSize = 500,
    ): Generator;

    /**
     * @return list<mixed>
     */
    public function firstRow(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array;

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>>|list<list<mixed>> $rows
     *
     * @throws MixedRowShapeException
     * @throws MissingSheetNameException
     */
    public function append(
        array $rows,
        ?string $sheetName = null,
        string $valueInputOption = SheetsService::VALUE_INPUT_RAW,
        string $insertDataOption = SheetsService::INSERT_DATA_OVERWRITE,
    ): AppendValuesResponse;

    /**
     * @param list<list<mixed>> $values
     */
    public function update(
        string $range,
        array $values,
        ?string $sheetName = null,
        string $valueInputOption = SheetsService::VALUE_INPUT_RAW,
    ): BatchUpdateValuesResponse;

    public function clear(?string $sheetName = null, ?string $range = null): ?ClearValuesResponse;

    // ------------------------------------------------------------------
    // Tab CRUD
    // ------------------------------------------------------------------

    public function addSheet(string $title): BatchUpdateSpreadsheetResponse;

    public function deleteSheet(string $title): BatchUpdateSpreadsheetResponse;

    // ------------------------------------------------------------------
    // Discovery
    // ------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public function listSheets(): array;

    /**
     * @return array<int, string>
     */
    public function listSheetsWithIds(): array;

    public function findSheetNameById(int $sheetId): ?string;

    // ------------------------------------------------------------------
    // Metadata
    // ------------------------------------------------------------------

    public function spreadsheetProperties(): object;

    public function sheetProperties(?string $sheetName = null): object;
}
