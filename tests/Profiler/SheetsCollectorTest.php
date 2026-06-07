<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Profiler;

use Gulaandrij\GoogleSheetsBundle\Profiler\SheetsCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
#[CoversClass(SheetsCollector::class)]
final class SheetsCollectorTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $collector = new SheetsCollector();

        self::assertSame(0, $collector->getTotalCalls());
        self::assertSame(0.0, $collector->getTotalDurationMs());
        self::assertSame(0, $collector->getErrorCount());
        self::assertSame([], $collector->getCalls());
    }

    public function testAccumulatesSuccessfulCalls(): void
    {
        $collector = new SheetsCollector();

        $collector->record(
            serviceName: 'allocators',
            method: 'readAssoc',
            context: ['spreadsheet_id' => '1abc', 'sheet' => 'List', 'range' => null],
            durationMs: 12.5,
        );
        $collector->record(
            serviceName: 'allocators',
            method: 'append',
            context: ['spreadsheet_id' => '1abc', 'sheet' => 'List', 'range' => null],
            durationMs: 30.0,
        );

        self::assertSame(2, $collector->getTotalCalls());
        self::assertSame(42.5, $collector->getTotalDurationMs());
        self::assertSame(0, $collector->getErrorCount());

        $calls = $collector->getCalls();
        self::assertCount(2, $calls);
        self::assertSame('readAssoc', $calls[0]['method']);
        self::assertSame('append', $calls[1]['method']);
        self::assertNull($calls[0]['error']);
    }

    public function testRecordsErrorAndStoresMessage(): void
    {
        $collector = new SheetsCollector();
        $error = new RuntimeException('boom');

        $collector->record(
            serviceName: 'reports',
            method: 'update',
            context: ['spreadsheet_id' => '1xyz', 'sheet' => 'Daily', 'range' => 'A1'],
            durationMs: 5.0,
            error: $error,
        );

        self::assertSame(1, $collector->getTotalCalls());
        self::assertSame(1, $collector->getErrorCount());
        self::assertSame(
            'RuntimeException: boom',
            $collector->getCalls()[0]['error'],
        );
    }

    public function testResetClearsState(): void
    {
        $collector = new SheetsCollector();
        $collector->record('s', 'm', ['spreadsheet_id' => '1', 'sheet' => null], 1.0);
        $collector->reset();

        self::assertSame(0, $collector->getTotalCalls());
        self::assertSame([], $collector->getCalls());
    }

    public function testCollectIsANoOp(): void
    {
        $collector = new SheetsCollector();
        $collector->record('s', 'm', ['spreadsheet_id' => '1', 'sheet' => null], 1.0);

        $collector->collect(new Request(), new Response());

        // Calls are recorded inline; collect() must not alter them.
        self::assertSame(1, $collector->getTotalCalls());
    }

    public function testTemplatePointsAtBundleTwig(): void
    {
        self::assertSame('@GoogleSheets/Collector/sheets.html.twig', SheetsCollector::getTemplate());
    }
}
