<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Profiler;

use Gulaandrij\GoogleSheetsBundle\Profiler\SheetsCollector;
use Gulaandrij\GoogleSheetsBundle\Profiler\TraceableSheetsService;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Revolution\Google\Sheets\SheetsClient;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(TraceableSheetsService::class)]
#[AllowMockObjectsWithoutExpectations]
final class TraceableSheetsServiceTest extends TestCase
{
    public function testRecordsSuccessfulReadRaw(): void
    {
        $collector = new SheetsCollector();
        $service = $this->buildService($collector, $this->stubClientReturning([['Name'], ['Alice']]));

        $service->readRaw();

        self::assertSame(1, $collector->getTotalCalls());
        $call = $collector->getCalls()[0];
        self::assertSame('allocators', $call['service']);
        self::assertSame('readRaw', $call['method']);
        self::assertSame('SHEET_ID', $call['spreadsheet_id']);
        self::assertSame('BoundTab', $call['sheet']);
        self::assertNull($call['range']);
        self::assertNull($call['error']);
        self::assertGreaterThanOrEqual(0.0, $call['duration_ms']);
    }

    public function testRecordsExplicitSheetNameOverride(): void
    {
        $collector = new SheetsCollector();
        $service = $this->buildService($collector, $this->stubClientReturning([]));

        $service->readAssoc('OtherTab', range: 'A1:B10');

        $call = $collector->getCalls()[0];
        self::assertSame('readAssoc', $call['method']);
        self::assertSame('OtherTab', $call['sheet']);
        self::assertSame('A1:B10', $call['range']);
    }

    public function testRecordsErrorAndRethrows(): void
    {
        $collector = new SheetsCollector();

        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->method('all')->willThrowException(new RuntimeException('upstream error'));

        $service = $this->buildService($collector, $client);

        try {
            $service->readRaw();
            self::fail('Expected RuntimeException to be rethrown');
        } catch (RuntimeException $e) {
            self::assertSame('upstream error', $e->getMessage());
        }

        self::assertSame(1, $collector->getTotalCalls());
        self::assertSame(1, $collector->getErrorCount());
        self::assertSame(
            'RuntimeException: upstream error',
            $collector->getCalls()[0]['error'],
        );
    }

    public function testReadEntitiesRecordsAsItsOwnEntry(): void
    {
        $collector = new SheetsCollector();

        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->method('all')->willReturn([
            ['Record ID - Contact', 'First Name', 'Email'],
            ['c-1', 'Alice', 'alice@example.com'],
        ]);

        $factory = $this->createMock(SheetsClientFactory::class);
        $factory->method('create')->willReturn($client);

        $denormalizer = new \Symfony\Component\Serializer\Serializer(
            [new \Symfony\Component\Serializer\Normalizer\ObjectNormalizer()],
        );

        $service = new TraceableSheetsService(
            $factory,
            'SHEET_ID',
            'BoundTab',
            $denormalizer,
            $collector,
            'allocators',
        );

        $service->readEntities(\Gulaandrij\GoogleSheetsBundle\Tests\Fixtures\PersonDto::class);

        $methods = array_map(static fn (array $call): string => $call['method'], $collector->getCalls());
        // readEntities surfaces as its own call (with the DTO short name);
        // the inner readAssoc is NOT separately recorded (it's part of the
        // readEntities work).
        self::assertContains('readEntities<PersonDto>', $methods);
        self::assertNotContains('readAssoc', $methods, 'inner readAssoc should not produce a separate trace entry');
    }

    public function testReadAssocIterableRecordsOneEntryOnCompletion(): void
    {
        $collector = new SheetsCollector();

        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->method('range')->willReturnSelf();
        $client->method('first')->willReturn(['Name', 'Email']);
        $callCount = 0;
        $client->method('all')->willReturnCallback(static function () use (&$callCount): array {
            ++$callCount;
            if (1 === $callCount) {
                return [['Alice', 'a@x'], ['Bob', 'b@x']];
            }

            return [];
        });

        $factory = $this->createMock(SheetsClientFactory::class);
        $factory->method('create')->willReturn($client);

        $service = new TraceableSheetsService(
            $factory,
            'SHEET_ID',
            'BoundTab',
            null,
            $collector,
            'reports',
        );

        $rows = iterator_to_array($service->readAssocIterable(batchSize: 500), false);
        self::assertCount(2, $rows);

        $calls = $collector->getCalls();
        self::assertCount(1, $calls);
        self::assertStringStartsWith('readAssocIterable(', $calls[0]['method']);
        self::assertStringContainsString('yielded=2', $calls[0]['method']);
    }

    public function testListSheetsRecordsWithoutSheet(): void
    {
        $collector = new SheetsCollector();
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheetList')->willReturn([101 => 'One']);

        $service = $this->buildService($collector, $client);

        $service->listSheets();

        $call = $collector->getCalls()[0];
        self::assertSame('listSheets', $call['method']);
        self::assertNull($call['sheet']);
    }

    /**
     * @param list<list<mixed>> $rows
     */
    private function stubClientReturning(array $rows): SheetsClient
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->method('all')->willReturn($rows);

        return $client;
    }

    private function buildService(SheetsCollector $collector, SheetsClient $client): TraceableSheetsService
    {
        $factory = $this->createMock(SheetsClientFactory::class);
        $factory->method('create')->willReturn($client);

        return new TraceableSheetsService($factory, 'SHEET_ID', 'BoundTab', null, $collector, 'allocators');
    }
}
