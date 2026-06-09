<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Profiler;

use Generator;
use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets\BatchUpdateSpreadsheetResponse;
use Google\Service\Sheets\BatchUpdateValuesResponse;
use Google\Service\Sheets\ClearValuesResponse;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
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
        ?DenormalizerInterface $denormalizer,
        private readonly SheetsCollector $collector,
        private readonly string $serviceName,
    ) {
        parent::__construct($factory, $spreadsheetId, $boundSheet, $denormalizer);
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

    public function readEntities(
        string $className,
        ?string $sheetName = null,
        ?string $range = null,
    ): array {
        return $this->trace(
            sprintf('readEntities<%s>', $this->shortClassName($className)),
            ['sheet' => $sheetName ?? $this->getBoundSheet(), 'range' => $range],
            fn (): array => parent::readEntities($className, $sheetName, $range),
        );
    }

    public function readAssocIterable(
        ?string $sheetName = null,
        int $batchSize = 500,
    ): Generator {
        // Generators don't compose cleanly with trace() because the work
        // happens lazily as the caller pulls. Time the whole stream up to
        // exhaustion and emit one trace entry on completion.
        $origin = $this->captureOrigin();
        $start = microtime(true);
        $count = 0;
        try {
            foreach (parent::readAssocIterable($sheetName, $batchSize) as $row) {
                ++$count;
                yield $row;
            }
            $this->recordIterableCompletion($sheetName, $batchSize, $count, $origin, $start);
        } catch (Throwable $e) {
            $this->recordIterableCompletion($sheetName, $batchSize, $count, $origin, $start, $e);

            throw $e;
        }
    }

    private function recordIterableCompletion(
        ?string $sheetName,
        int $batchSize,
        int $count,
        ?string $origin,
        float $start,
        ?Throwable $error = null,
    ): void {
        $this->collector->record(
            $this->serviceName,
            sprintf('readAssocIterable(batchSize=%d, yielded=%d)', $batchSize, $count),
            [
                'spreadsheet_id' => $this->getSpreadsheetId(),
                'sheet' => $sheetName ?? $this->getBoundSheet(),
                'range' => null,
                'origin' => $origin,
            ],
            (microtime(true) - $start) * 1000.0,
            $error,
        );
    }

    private function shortClassName(string $className): string
    {
        $pos = strrpos($className, '\\');

        return false === $pos ? $className : substr($className, $pos + 1);
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
        $origin = $this->captureOrigin();
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
                    'origin' => $origin,
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
                    'origin' => $origin,
                ],
                (microtime(true) - $start) * 1000.0,
                $e,
            );

            throw $e;
        }
    }

    /**
     * Walk the stack until the first frame that isn't bundle plumbing —
     * that's the call site the user actually wants to see in the profiler.
     * Only the bundle's own runtime classes (Service + Profiler namespaces)
     * are filtered out; consumer code in `…\Tests\…` namespaces still counts
     * as a legitimate origin so the bundle's own test suite can verify the
     * captured frame.
     */
    private function captureOrigin(): ?string
    {
        foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $class = $frame['class'] ?? '';
            if (self::isBundleRuntimeClass($class)) {
                continue;
            }
            if (!isset($frame['file'], $frame['line'])) {
                continue;
            }

            return sprintf('%s:%d', $frame['file'], $frame['line']);
        }

        return null;
    }

    private static function isBundleRuntimeClass(string $class): bool
    {
        return str_starts_with($class, 'Gulaandrij\\GoogleSheetsBundle\\Profiler\\')
            || str_starts_with($class, 'Gulaandrij\\GoogleSheetsBundle\\Service\\');
    }
}
