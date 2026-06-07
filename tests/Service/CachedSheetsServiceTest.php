<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Service;

use Gulaandrij\GoogleSheetsBundle\Service\CachedSheetsService;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Revolution\Google\Sheets\SheetsClient;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @internal
 */
#[CoversClass(CachedSheetsService::class)]
#[AllowMockObjectsWithoutExpectations]
final class CachedSheetsServiceTest extends TestCase
{
    public function testReadAssocIsCachedAcrossCalls(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        // The underlying client's `all()` should be invoked exactly ONCE even
        // though we call readAssoc twice — the second call comes from cache.
        $client->expects(self::once())->method('all')->willReturn([
            ['Name'], ['Alice'], ['Bob'],
        ]);

        $factory = $this->createMock(SheetsClientFactory::class);
        $factory->method('create')->willReturn($client);

        $service = new CachedSheetsService(
            $factory,
            'SHEET_ID',
            'tab',
            new ArrayAdapter(),
            ttlSeconds: 60,
            serviceName: 'allocators',
        );

        $first = $service->readAssoc();
        $second = $service->readAssoc();

        self::assertSame($first, $second);
        self::assertSame([['Name' => 'Alice'], ['Name' => 'Bob']], $first);
    }

    public function testWritesInvalidateCachedReads(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        // After the write, the second readAssoc should hit Google again — so
        // we expect TWO `all()` calls total (one before write, one after).
        $client->expects(self::exactly(2))->method('all')->willReturnOnConsecutiveCalls(
            [['Name'], ['Alice']],
            [['Name'], ['Alice'], ['Bob']],
        );
        $client->method('append')->willReturn(new \Google\Service\Sheets\AppendValuesResponse());

        $factory = $this->createMock(SheetsClientFactory::class);
        $factory->method('create')->willReturn($client);

        $service = new CachedSheetsService(
            $factory,
            'SHEET_ID',
            'tab',
            new ArrayAdapter(),
            ttlSeconds: 60,
            serviceName: 'allocators',
        );

        $before = $service->readAssoc();
        self::assertSame([['Name' => 'Alice']], $before);

        // Same call again — would normally hit cache; we don't call readAssoc
        // here to keep the test minimal, just append and confirm the NEXT
        // read goes back to Google.
        $service->append([['Bob']]);

        $after = $service->readAssoc();
        self::assertSame([['Name' => 'Alice'], ['Name' => 'Bob']], $after);
    }

    public function testReadEntitiesWorksWhenDenormalizerIsInjected(): void
    {
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

        $service = new CachedSheetsService(
            $factory,
            'SHEET_ID',
            'tab',
            new ArrayAdapter(),
            ttlSeconds: 60,
            serviceName: 'allocators',
            denormalizer: $denormalizer,
        );

        $entities = $service->readEntities(\Gulaandrij\GoogleSheetsBundle\Tests\Fixtures\PersonDto::class);

        self::assertCount(1, $entities);
        self::assertSame('c-1', $entities[0]->contactId);
        self::assertSame('Alice', $entities[0]->firstName);
    }

    public function testDifferentArgumentsProduceDifferentCacheEntries(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->method('range')->willReturnSelf();
        // Two distinct $sheetName values should each trigger one upstream read.
        $client->expects(self::exactly(2))->method('all')->willReturnOnConsecutiveCalls(
            [['Name'], ['Alice']],
            [['Name'], ['Bob']],
        );

        $factory = $this->createMock(SheetsClientFactory::class);
        $factory->method('create')->willReturn($client);

        $service = new CachedSheetsService(
            $factory,
            'SHEET_ID',
            null,
            new ArrayAdapter(),
            ttlSeconds: 60,
            serviceName: 'reports',
        );

        self::assertSame([['Name' => 'Alice']], $service->readAssoc('tabA'));
        self::assertSame([['Name' => 'Bob']], $service->readAssoc('tabB'));
        // Repeat — both should hit cache, no extra upstream calls.
        self::assertSame([['Name' => 'Alice']], $service->readAssoc('tabA'));
        self::assertSame([['Name' => 'Bob']], $service->readAssoc('tabB'));
    }
}
