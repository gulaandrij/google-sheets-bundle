<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Service;

use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets\BatchUpdateValuesResponse;
use Google\Service\Sheets\ClearValuesResponse;
use Gulaandrij\GoogleSheetsBundle\Exception\DuplicateHeaderException;
use Gulaandrij\GoogleSheetsBundle\Exception\InvalidHeaderException;
use Gulaandrij\GoogleSheetsBundle\Exception\MixedRowShapeException;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Revolution\Google\Sheets\SheetsClient;

/**
 * @internal
 */
#[CoversClass(SheetsService::class)]
#[AllowMockObjectsWithoutExpectations]
final class SheetsServiceTest extends TestCase
{
    public function testReadRawReturnsAllRowsAsIs(): void
    {
        $rows = [
            ['Name', 'Email'],
            ['Alice', 'alice@example.com'],
            ['Bob', 'bob@example.com'],
        ];

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with('SHEET_ID')->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('People')->willReturnSelf();
        $client->expects(self::never())->method('range');
        $client->expects(self::once())->method('all')->willReturn($rows);

        $service = $this->serviceWithClients($client);

        self::assertSame($rows, $service->readRaw('SHEET_ID', 'People'));
    }

    public function testReadRawAppliesRangeWhenProvided(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A1:B10')->willReturnSelf();
        $client->expects(self::once())->method('all')->willReturn([]);

        $service = $this->serviceWithClients($client);

        self::assertSame([], $service->readRaw('SHEET_ID', 'People', 'A1:B10'));
    }

    public function testReadAssocCombinesHeaderAndRows(): void
    {
        $rows = [
            ['Name', 'Email'],
            ['Alice', 'alice@example.com'],
            ['Bob', 'bob@example.com'],
        ];

        $service = $this->serviceWithClients($this->stubClientReturning($rows));

        $expected = [
            ['Name' => 'Alice', 'Email' => 'alice@example.com'],
            ['Name' => 'Bob', 'Email' => 'bob@example.com'],
        ];

        self::assertSame($expected, $service->readAssoc('SHEET_ID', 'People'));
    }

    public function testReadAssocPadsShortRowsWithEmptyStrings(): void
    {
        $rows = [
            ['Name', 'Email', 'Phone'],
            ['Alice', 'alice@example.com'], // missing phone
        ];

        $service = $this->serviceWithClients($this->stubClientReturning($rows));

        self::assertSame(
            [['Name' => 'Alice', 'Email' => 'alice@example.com', 'Phone' => '']],
            $service->readAssoc('SHEET_ID', 'People'),
        );
    }

    public function testReadAssocTruncatesOverflowCells(): void
    {
        $rows = [
            ['Name', 'Email'],
            ['Alice', 'alice@example.com', 'extra-cell-ignored'],
        ];

        $service = $this->serviceWithClients($this->stubClientReturning($rows));

        self::assertSame(
            [['Name' => 'Alice', 'Email' => 'alice@example.com']],
            $service->readAssoc('SHEET_ID', 'People'),
        );
    }

    public function testReadAssocReturnsEmptyArrayWhenSheetIsEmpty(): void
    {
        $service = $this->serviceWithClients($this->stubClientReturning([]));

        self::assertSame([], $service->readAssoc('SHEET_ID', 'People'));
    }

    public function testReadAssocThrowsOnDuplicateHeaderValues(): void
    {
        $rows = [
            ['Name', 'Notes', 'Notes'],
            ['Alice', 'a', 'b'],
        ];

        $service = $this->serviceWithClients($this->stubClientReturning($rows));

        $this->expectException(DuplicateHeaderException::class);
        $this->expectExceptionMessage('Duplicate header value "Notes"');
        $service->readAssoc('SHEET_ID', 'People');
    }

    public function testReadAssocThrowsOnNonScalarHeaderCell(): void
    {
        $rows = [
            ['Name', ['nested', 'error']],
            ['Alice', 'a'],
        ];

        $service = $this->serviceWithClients($this->stubClientReturning($rows));

        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage('Header cell at index 1');
        $service->readAssoc('SHEET_ID', 'People');
    }

    public function testListSheetsReturnsListOfTabNames(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with('SHEET_ID')->willReturnSelf();
        $client->expects(self::once())->method('sheetList')->willReturn([101 => 'One', 202 => 'Two', 303 => 'Three']);

        $service = $this->serviceWithClients($client);

        self::assertSame(['One', 'Two', 'Three'], $service->listSheets('SHEET_ID'));
    }

    public function testListSheetsWithIdsPreservesTheSheetIdMap(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with('SHEET_ID')->willReturnSelf();
        $client->expects(self::once())->method('sheetList')->willReturn([101 => 'One', 202 => 'Two']);

        $service = $this->serviceWithClients($client);

        self::assertSame([101 => 'One', 202 => 'Two'], $service->listSheetsWithIds('SHEET_ID'));
    }

    public function testAppendForwardsRowsToTheUnderlyingClient(): void
    {
        $response = $this->createMock(AppendValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with('SHEET_ID')->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('Allocators')->willReturnSelf();
        $client->expects(self::once())
            ->method('append')
            ->with([['a', 'b']], 'USER_ENTERED', 'INSERT_ROWS')
            ->willReturn($response);

        $service = $this->serviceWithClients($client);

        self::assertSame(
            $response,
            $service->append('SHEET_ID', 'Allocators', [['a', 'b']], 'USER_ENTERED', 'INSERT_ROWS'),
        );
    }

    public function testAppendThrowsWhenRowsMixPositionalAndAssoc(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::never())->method('append');

        $service = $this->serviceWithClients($client);

        $this->expectException(MixedRowShapeException::class);
        $service->append('SHEET_ID', 'tab', [
            ['Name' => 'A', 'Email' => 'a@x'],
            ['B', 'b@x'],
        ]);
    }

    public function testAppendAcceptsAllAssociativeRows(): void
    {
        $response = $this->createMock(AppendValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('append')->willReturn($response);

        $service = $this->serviceWithClients($client);

        $rows = [
            ['Name' => 'A', 'Email' => 'a@x'],
            ['Name' => 'B', 'Email' => 'b@x'],
        ];

        self::assertSame($response, $service->append('SHEET_ID', 'tab', $rows));
    }

    public function testUpdateForwardsRangeAndValuesToTheUnderlyingClient(): void
    {
        $response = $this->createMock(BatchUpdateValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with('SHEET_ID')->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('People')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A2:B2')->willReturnSelf();
        $client->expects(self::once())->method('update')->with([['Carol', 'c@example.com']], 'RAW')->willReturn($response);

        $service = $this->serviceWithClients($client);

        self::assertSame(
            $response,
            $service->update('SHEET_ID', 'People', 'A2:B2', [['Carol', 'c@example.com']]),
        );
    }

    public function testClearWithoutRangeClearsWholeSheet(): void
    {
        $response = $this->createMock(ClearValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with('SHEET_ID')->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('People')->willReturnSelf();
        $client->expects(self::never())->method('range');
        $client->expects(self::once())->method('clear')->willReturn($response);

        $service = $this->serviceWithClients($client);

        self::assertSame($response, $service->clear('SHEET_ID', 'People'));
    }

    public function testClearWithRangeAppliesTheRange(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A2:Z')->willReturnSelf();
        $client->expects(self::once())->method('clear')->willReturn(null);

        $service = $this->serviceWithClients($client);

        self::assertNull($service->clear('SHEET_ID', 'People', 'A2:Z'));
    }

    public function testEachCallObtainsAFreshClientFromTheFactory(): void
    {
        $clientA = $this->createMock(SheetsClient::class);
        $clientA->expects(self::once())->method('spreadsheet')->with('A')->willReturnSelf();
        $clientA->expects(self::once())->method('sheet')->with('tab')->willReturnSelf();
        $clientA->expects(self::never())->method('range');
        $clientA->expects(self::once())->method('all')->willReturn([]);

        // The second call must NOT reach $clientA at all — it must go through a
        // brand-new client. Otherwise stateful selectors from call A would leak
        // into call B (the bug the factory pattern exists to prevent).
        $clientB = $this->createMock(SheetsClient::class);
        $clientB->expects(self::once())->method('spreadsheet')->with('B')->willReturnSelf();
        $clientB->expects(self::once())->method('sheet')->with('tab')->willReturnSelf();
        $clientB->expects(self::never())->method('range');
        $clientB->expects(self::once())->method('all')->willReturn([]);

        $service = $this->serviceWithClients($clientA, $clientB);

        self::assertSame([], $service->readRaw('A', 'tab'));
        self::assertSame([], $service->readRaw('B', 'tab'));
    }

    public function testClientReturnsAFreshInstanceEachCall(): void
    {
        $clientA = $this->createMock(SheetsClient::class);
        $clientB = $this->createMock(SheetsClient::class);

        $service = $this->serviceWithClients($clientA, $clientB);

        self::assertSame($clientA, $service->client());
        self::assertSame($clientB, $service->client());
    }

    /**
     * @param list<list<mixed>> $rows
     */
    private function stubClientReturning(array $rows): SheetsClient&MockObject
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->method('all')->willReturn($rows);

        return $client;
    }

    /**
     * Build a SheetsService backed by a SheetsClientFactory that returns the
     * given clients in order — one per `create()` call.
     */
    private function serviceWithClients(SheetsClient ...$clients): SheetsService
    {
        $factory = $this->createMock(SheetsClientFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(...array_values($clients));

        return new SheetsService($factory);
    }
}
