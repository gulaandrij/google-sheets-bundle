<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Service;

use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets\BatchUpdateValuesResponse;
use Google\Service\Sheets\ClearValuesResponse;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
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

        $service = new SheetsService($client);

        self::assertSame($rows, $service->readRaw('SHEET_ID', 'People'));
    }

    public function testReadRawAppliesRangeWhenProvided(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A1:B10')->willReturnSelf();
        $client->expects(self::once())->method('all')->willReturn([]);

        $service = new SheetsService($client);

        self::assertSame([], $service->readRaw('SHEET_ID', 'People', 'A1:B10'));
    }

    public function testReadAssocCombinesHeaderAndRows(): void
    {
        $rows = [
            ['Name', 'Email'],
            ['Alice', 'alice@example.com'],
            ['Bob', 'bob@example.com'],
        ];

        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->method('all')->willReturn($rows);

        $service = new SheetsService($client);

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

        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->method('all')->willReturn($rows);

        $service = new SheetsService($client);

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

        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->method('all')->willReturn($rows);

        $service = new SheetsService($client);

        self::assertSame(
            [['Name' => 'Alice', 'Email' => 'alice@example.com']],
            $service->readAssoc('SHEET_ID', 'People'),
        );
    }

    public function testReadAssocReturnsEmptyArrayWhenSheetIsEmpty(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->method('all')->willReturn([]);

        $service = new SheetsService($client);

        self::assertSame([], $service->readAssoc('SHEET_ID', 'People'));
    }

    public function testListSheetsReturnsListOfTabNames(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with('SHEET_ID')->willReturnSelf();
        $client->expects(self::once())->method('sheetList')->willReturn(['One', 'Two', 'Three']);

        $service = new SheetsService($client);

        self::assertSame(['One', 'Two', 'Three'], $service->listSheets('SHEET_ID'));
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

        $service = new SheetsService($client);

        self::assertSame(
            $response,
            $service->append('SHEET_ID', 'Allocators', [['a', 'b']], 'USER_ENTERED', 'INSERT_ROWS'),
        );
    }

    public function testUpdateForwardsRangeAndValuesToTheUnderlyingClient(): void
    {
        $response = $this->createMock(BatchUpdateValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with('SHEET_ID')->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('People')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A2:B2')->willReturnSelf();
        $client->expects(self::once())->method('update')->with([['Carol', 'c@example.com']], 'RAW')->willReturn($response);

        $service = new SheetsService($client);

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

        $service = new SheetsService($client);

        self::assertSame($response, $service->clear('SHEET_ID', 'People'));
    }

    public function testClearWithRangeAppliesTheRange(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A2:Z')->willReturnSelf();
        $client->expects(self::once())->method('clear')->willReturn(null);

        $service = new SheetsService($client);

        self::assertNull($service->clear('SHEET_ID', 'People', 'A2:Z'));
    }

    public function testClientReturnsUnderlyingInstance(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $service = new SheetsService($client);

        self::assertSame($client, $service->client());
    }
}
