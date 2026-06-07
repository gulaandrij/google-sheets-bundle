<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Profiler;

use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Web Profiler data collector that records every SheetsService call made
 * during a request. Wired by the bundle only when `kernel.debug` is true.
 *
 * @phpstan-type Call array{
 *     service: string,
 *     method: string,
 *     spreadsheet_id: string,
 *     sheet: string|null,
 *     range: string|null,
 *     origin: string|null,
 *     duration_ms: float,
 *     error: string|null,
 * }
 */
final class SheetsCollector extends AbstractDataCollector
{
    public function __construct()
    {
        $this->reset();
    }

    /**
     * @param array{
     *     spreadsheet_id: string,
     *     sheet: string|null,
     *     range?: string|null,
     *     origin?: string|null,
     * } $context
     */
    public function record(
        string $serviceName,
        string $method,
        array $context,
        float $durationMs,
        ?Throwable $error = null,
    ): void {
        /** @var list<Call> $calls */
        $calls = $this->data['calls'] ?? [];
        $calls[] = [
            'service' => $serviceName,
            'method' => $method,
            'spreadsheet_id' => $context['spreadsheet_id'],
            'sheet' => $context['sheet'] ?? null,
            'range' => $context['range'] ?? null,
            'origin' => $context['origin'] ?? null,
            'duration_ms' => $durationMs,
            'error' => null !== $error ? $error::class.': '.$error->getMessage() : null,
        ];
        $this->data['calls'] = $calls;
        $this->data['total_calls'] = $this->getTotalCalls() + 1;
        $this->data['total_duration_ms'] = $this->getTotalDurationMs() + $durationMs;
        if (null !== $error) {
            $this->data['error_count'] = $this->getErrorCount() + 1;
        }
    }

    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        // Calls are recorded inline via record(); nothing to do here.
    }

    public function reset(): void
    {
        $this->data = [
            'calls' => [],
            'total_calls' => 0,
            'total_duration_ms' => 0.0,
            'error_count' => 0,
        ];
    }

    public function getName(): string
    {
        return 'google_sheets';
    }

    public static function getTemplate(): string
    {
        return '@GoogleSheets/Collector/sheets.html.twig';
    }

    public function getTotalCalls(): int
    {
        $value = $this->data['total_calls'] ?? 0;

        return is_int($value) ? $value : 0;
    }

    public function getTotalDurationMs(): float
    {
        $value = $this->data['total_duration_ms'] ?? 0.0;

        return is_float($value) ? $value : (is_int($value) ? (float) $value : 0.0);
    }

    public function getErrorCount(): int
    {
        $value = $this->data['error_count'] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /**
     * @return list<Call>
     */
    public function getCalls(): array
    {
        /** @var list<Call> $calls */
        $calls = $this->data['calls'] ?? [];

        return $calls;
    }
}
