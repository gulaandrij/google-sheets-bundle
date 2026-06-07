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
    private const SHEET_ID = '1abcSHEET';

    public function testGetSpreadsheetIdExposesTheBoundId(): void
    {
        $service = $this->serviceWithClients($this->createMock(SheetsClient::class));

        self::assertSame(self::SHEET_ID, $service->getSpreadsheetId());
    }

    public function testReadRawReturnsAllRowsAsIs(): void
    {
        $rows = [
            ['Name', 'Email'],
            ['Alice', 'alice@example.com'],
            ['Bob', 'bob@example.com'],
        ];

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('People')->willReturnSelf();
        $client->expects(self::never())->method('range');
        $client->expects(self::once())->method('all')->willReturn($rows);

        $service = $this->serviceWithClients($client);

        self::assertSame($rows, $service->readRaw('People'));
    }

    public function testReadRawAppliesRangeWhenProvided(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A1:B10')->willReturnSelf();
        $client->expects(self::once())->method('all')->willReturn([]);

        $service = $this->serviceWithClients($client);

        self::assertSame([], $service->readRaw('People', 'A1:B10'));
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

        self::assertSame($expected, $service->readAssoc('People'));
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
            $service->readAssoc('People'),
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
            $service->readAssoc('People'),
        );
    }

    public function testReadAssocReturnsEmptyArrayWhenSheetIsEmpty(): void
    {
        $service = $this->serviceWithClients($this->stubClientReturning([]));

        self::assertSame([], $service->readAssoc('People'));
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
        $service->readAssoc('People');
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
        $service->readAssoc('People');
    }

    public function testListSheetsReturnsListOfTabNames(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheetList')->willReturn([101 => 'One', 202 => 'Two', 303 => 'Three']);

        $service = $this->serviceWithClients($client);

        self::assertSame(['One', 'Two', 'Three'], $service->listSheets());
    }

    public function testListSheetsWithIdsPreservesTheSheetIdMap(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheetList')->willReturn([101 => 'One', 202 => 'Two']);

        $service = $this->serviceWithClients($client);

        self::assertSame([101 => 'One', 202 => 'Two'], $service->listSheetsWithIds());
    }

    public function testAppendForwardsRowsToTheUnderlyingClient(): void
    {
        $response = $this->createMock(AppendValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('Allocators')->willReturnSelf();
        $client->expects(self::once())
            ->method('append')
            ->with([['a', 'b']], 'USER_ENTERED', 'INSERT_ROWS')
            ->willReturn($response);

        $service = $this->serviceWithClients($client);

        self::assertSame(
            $response,
            $service->append('Allocators', [['a', 'b']], 'USER_ENTERED', 'INSERT_ROWS'),
        );
    }

    public function testAppendThrowsWhenRowsMixPositionalAndAssoc(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::never())->method('append');

        $service = $this->serviceWithClients($client);

        $this->expectException(MixedRowShapeException::class);
        $service->append('tab', [
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

        self::assertSame($response, $service->append('tab', $rows));
    }

    public function testUpdateForwardsRangeAndValuesToTheUnderlyingClient(): void
    {
        $response = $this->createMock(BatchUpdateValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('People')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A2:B2')->willReturnSelf();
        $client->expects(self::once())->method('update')->with([['Carol', 'c@example.com']], 'RAW')->willReturn($response);

        $service = $this->serviceWithClients($client);

        self::assertSame(
            $response,
            $service->update('People', 'A2:B2', [['Carol', 'c@example.com']]),
        );
    }

    public function testClearWithoutRangeClearsWholeSheet(): void
    {
        $response = $this->createMock(ClearValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('People')->willReturnSelf();
        $client->expects(self::never())->method('range');
        $client->expects(self::once())->method('clear')->willReturn($response);

        $service = $this->serviceWithClients($client);

        self::assertSame($response, $service->clear('People'));
    }

    public function testClearWithRangeAppliesTheRange(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A2:Z')->willReturnSelf();
        $client->expects(self::once())->method('clear')->willReturn(null);

        $service = $this->serviceWithClients($client);

        self::assertNull($service->clear('People', 'A2:Z'));
    }

    public function testEachCallObtainsAFreshClientFromTheFactory(): void
    {
        $clientA = $this->createMock(SheetsClient::class);
        $clientA->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $clientA->expects(self::once())->method('sheet')->with('tabA')->willReturnSelf();
        $clientA->expects(self::never())->method('range');
        $clientA->expects(self::once())->method('all')->willReturn([]);

        $clientB = $this->createMock(SheetsClient::class);
        $clientB->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $clientB->expects(self::once())->method('sheet')->with('tabB')->willReturnSelf();
        $clientB->expects(self::never())->method('range');
        $clientB->expects(self::once())->method('all')->willReturn([]);

        $service = $this->serviceWithClients($clientA, $clientB);

        self::assertSame([], $service->readRaw('tabA'));
        self::assertSame([], $service->readRaw('tabB'));
    }

    public function testClientReturnsAFreshInstanceEachCall(): void
    {
        $clientA = $this->createMock(SheetsClient::class);
        $clientB = $this->createMock(SheetsClient::class);

        $service = $this->serviceWithClients($clientA, $clientB);

        self::assertSame($clientA, $service->client());
        self::assertSame($clientB, $service->client());
    }

    public function testReadRawForwardsAllReadOptions(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A1:Z')->willReturnSelf();
        $client->expects(self::once())->method('majorDimension')->with(SheetsService::MAJOR_DIMENSION_COLUMNS)->willReturnSelf();
        $client->expects(self::once())->method('valueRenderOption')->with(SheetsService::VALUE_RENDER_UNFORMATTED)->willReturnSelf();
        $client->expects(self::once())->method('dateTimeRenderOption')->with(SheetsService::DATE_TIME_RENDER_FORMATTED)->willReturnSelf();
        $client->expects(self::once())->method('all')->willReturn([]);

        $service = $this->serviceWithClients($client);

        $service->readRaw(
            'tab',
            range: 'A1:Z',
            majorDimension: SheetsService::MAJOR_DIMENSION_COLUMNS,
            valueRenderOption: SheetsService::VALUE_RENDER_UNFORMATTED,
            dateTimeRenderOption: SheetsService::DATE_TIME_RENDER_FORMATTED,
        );
    }

    public function testFirstRowReturnsFirstValue(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('tab')->willReturnSelf();
        $client->expects(self::once())->method('first')->willReturn(['Name', 'Email']);

        $service = $this->serviceWithClients($client);

        self::assertSame(['Name', 'Email'], $service->firstRow('tab'));
    }

    public function testFirstRowAppliesReadOptions(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A1:B1')->willReturnSelf();
        $client->expects(self::once())->method('valueRenderOption')->with(SheetsService::VALUE_RENDER_FORMULA)->willReturnSelf();
        $client->expects(self::once())->method('first')->willReturn([]);

        $service = $this->serviceWithClients($client);

        self::assertSame([], $service->firstRow('tab', 'A1:B1', valueRenderOption: SheetsService::VALUE_RENDER_FORMULA));
    }

    public function testAddSheetCreatesNewTab(): void
    {
        $response = $this->createMock(\Google\Service\Sheets\BatchUpdateSpreadsheetResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('addSheet')->with('Archive')->willReturn($response);

        $service = $this->serviceWithClients($client);

        self::assertSame($response, $service->addSheet('Archive'));
    }

    public function testDeleteSheetRemovesTab(): void
    {
        $response = $this->createMock(\Google\Service\Sheets\BatchUpdateSpreadsheetResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('deleteSheet')->with('Old')->willReturn($response);

        $service = $this->serviceWithClients($client);

        self::assertSame($response, $service->deleteSheet('Old'));
    }

    public function testFindSheetNameByIdResolvesAgainstSheetList(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheetList')->willReturn([101 => 'Allocators', 837423919 => 'Archive']);

        $service = $this->serviceWithClients($client);

        self::assertSame('Archive', $service->findSheetNameById(837423919));
    }

    public function testFindSheetNameByIdReturnsNullForUnknownId(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheetList')->willReturn([101 => 'Allocators']);

        $service = $this->serviceWithClients($client);

        self::assertNull($service->findSheetNameById(999));
    }

    public function testListSpreadsheetsForwardsToDriveQuery(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::never())->method('spreadsheet'); // global Drive call ignores bound id
        $client->expects(self::once())->method('spreadsheetList')->willReturn(['fileA' => 'Allocators', 'fileB' => 'Reports']);

        $service = $this->serviceWithClients($client);

        self::assertSame(['fileA' => 'Allocators', 'fileB' => 'Reports'], $service->listSpreadsheets());
    }

    public function testSpreadsheetPropertiesForwardsToTheClient(): void
    {
        $properties = (object) ['title' => 'My Spreadsheet', 'locale' => 'en_US'];

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('spreadsheetProperties')->willReturn($properties);

        $service = $this->serviceWithClients($client);

        self::assertSame($properties, $service->spreadsheetProperties());
    }

    public function testSheetPropertiesForwardsToTheClient(): void
    {
        $properties = (object) ['title' => 'Allocators', 'index' => 0];

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('Allocators')->willReturnSelf();
        $client->expects(self::once())->method('sheetProperties')->willReturn($properties);

        $service = $this->serviceWithClients($client);

        self::assertSame($properties, $service->sheetProperties('Allocators'));
    }

    public function testDriveServiceReturnsTheUnderlyingDriveInstance(): void
    {
        $drive = $this->createMock(\Google\Service\Drive::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('getDriveService')->willReturn($drive);

        $service = $this->serviceWithClients($client);

        self::assertSame($drive, $service->driveService());
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
     * Build a SheetsService bound to SHEET_ID and backed by a
     * SheetsClientFactory that returns the given clients in order.
     */
    private function serviceWithClients(SheetsClient $firstClient, SheetsClient ...$rest): SheetsService
    {
        $factory = $this->createMock(SheetsClientFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls($firstClient, ...array_values($rest));

        return new SheetsService($factory, self::SHEET_ID);
    }
}
