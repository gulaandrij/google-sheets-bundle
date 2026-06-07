<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Profiler;

use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets\BatchUpdateSpreadsheetResponse;
use Google\Service\Sheets\BatchUpdateValuesResponse;
use Google\Service\Sheets\ClearValuesResponse;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use Throwable;

/**
 * Decorates `SheetsService`, recording every public method call into a
 * {@see SheetsCollector} for the Symfony Web Profiler. Only registered by
 * the bundle when `kernel.debug` is true.
 */
final class TraceableSheetsService extends SheetsService
{
    public function __construct(
        SheetsClientFactory $factory,
        string $spreadsheetId,
        ?string $boundSheet,
        private readonly SheetsCollector $collector,
        private readonly string $serviceName,
    ) {
        parent::__construct($factory, $spreadsheetId, $boundSheet);
    }

    public function readRaw(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        return $this->trace(
            'readRaw',
            ['sheet' => $sheetName ?? $this->getBoundSheet(), 'range' => $range],
            fn (): array => parent::readRaw($sheetName, $range, $majorDimension, $valueRenderOption, $dateTimeRenderOption),
        );
    }

    public function readAssoc(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $majorDimension = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        return $this->trace(
            'readAssoc',
            ['sheet' => $sheetName ?? $this->getBoundSheet(), 'range' => $range],
            fn (): array => parent::readAssoc($sheetName, $range, $majorDimension, $valueRenderOption, $dateTimeRenderOption),
        );
    }

    public function firstRow(
        ?string $sheetName = null,
        ?string $range = null,
        ?string $valueRenderOption = null,
        ?string $dateTimeRenderOption = null,
    ): array {
        return $this->trace(
            'firstRow',
            ['sheet' => $sheetName ?? $this->getBoundSheet(), 'range' => $range],
            fn (): array => parent::firstRow($sheetName, $range, $valueRenderOption, $dateTimeRenderOption),
        );
    }

    public function append(
        array $rows,
        ?string $sheetName = null,
        string $valueInputOption = self::VALUE_INPUT_RAW,
        string $insertDataOption = self::INSERT_DATA_OVERWRITE,
    ): AppendValuesResponse {
        return $this->trace(
            'append',
            ['sheet' => $sheetName ?? $this->getBoundSheet()],
            fn (): AppendValuesResponse => parent::append($rows, $sheetName, $valueInputOption, $insertDataOption),
        );
    }

    public function update(
        string $range,
        array $values,
        ?string $sheetName = null,
        string $valueInputOption = self::VALUE_INPUT_RAW,
    ): BatchUpdateValuesResponse {
        return $this->trace(
            'update',
            ['sheet' => $sheetName ?? $this->getBoundSheet(), 'range' => $range],
            fn (): BatchUpdateValuesResponse => parent::update($range, $values, $sheetName, $valueInputOption),
        );
    }

    public function clear(?string $sheetName = null, ?string $range = null): ?ClearValuesResponse
    {
        return $this->trace(
            'clear',
            ['sheet' => $sheetName ?? $this->getBoundSheet(), 'range' => $range],
            fn (): ?ClearValuesResponse => parent::clear($sheetName, $range),
        );
    }

    public function addSheet(string $title): BatchUpdateSpreadsheetResponse
    {
        return $this->trace(
            'addSheet',
            ['sheet' => $title],
            fn (): BatchUpdateSpreadsheetResponse => parent::addSheet($title),
        );
    }

    public function deleteSheet(string $title): BatchUpdateSpreadsheetResponse
    {
        return $this->trace(
            'deleteSheet',
            ['sheet' => $title],
            fn (): BatchUpdateSpreadsheetResponse => parent::deleteSheet($title),
        );
    }

    public function listSheets(): array
    {
        return $this->trace('listSheets', ['sheet' => null], fn (): array => parent::listSheets());
    }

    public function listSheetsWithIds(): array
    {
        return $this->trace('listSheetsWithIds', ['sheet' => null], fn (): array => parent::listSheetsWithIds());
    }

    public function spreadsheetProperties(): object
    {
        return $this->trace('spreadsheetProperties', ['sheet' => null], fn (): object => parent::spreadsheetProperties());
    }

    public function sheetProperties(?string $sheetName = null): object
    {
        return $this->trace(
            'sheetProperties',
            ['sheet' => $sheetName ?? $this->getBoundSheet()],
            fn (): object => parent::sheetProperties($sheetName),
        );
    }

    /**
     * @template T
     *
     * @param array{sheet: string|null, range?: string|null} $context
     * @param callable(): T                                  $fn
     *
     * @return T
     */
    private function trace(string $method, array $context, callable $fn): mixed
    {
        $start = microtime(true);
        try {
            $result = $fn();
            $this->collector->record(
                $this->serviceName,
                $method,
                [
                    'spreadsheet_id' => $this->getSpreadsheetId(),
                    'sheet' => $context['sheet'],
                    'range' => $context['range'] ?? null,
                ],
                (microtime(true) - $start) * 1000.0,
            );

            return $result;
        } catch (Throwable $e) {
            $this->collector->record(
                $this->serviceName,
                $method,
                [
                    'spreadsheet_id' => $this->getSpreadsheetId(),
                    'sheet' => $context['sheet'],
                    'range' => $context['range'] ?? null,
                ],
                (microtime(true) - $start) * 1000.0,
                $e,
            );

            throw $e;
        }
    }
}
