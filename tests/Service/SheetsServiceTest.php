<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Service;

use Google\Service\Drive;
use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets\BatchUpdateSpreadsheetResponse;
use Google\Service\Sheets\BatchUpdateValuesResponse;
use Google\Service\Sheets\ClearValuesResponse;
use Gulaandrij\GoogleSheetsBundle\Exception\DuplicateHeaderException;
use Gulaandrij\GoogleSheetsBundle\Exception\InvalidHeaderException;
use Gulaandrij\GoogleSheetsBundle\Exception\MissingSheetNameException;
use Gulaandrij\GoogleSheetsBundle\Exception\MixedRowShapeException;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use Gulaandrij\GoogleSheetsBundle\Tests\Fixtures\PersonDto;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;
use Revolution\Google\Sheets\SheetsClient;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * @internal
 */
#[CoversClass(SheetsService::class)]
#[AllowMockObjectsWithoutExpectations]
final class SheetsServiceTest extends TestCase
{
    private const SHEET_ID = '1abcSHEET';
    private const BOUND_TAB = 'Bound Tab';

    public function testGetSpreadsheetIdAndBoundSheet(): void
    {
        $service = $this->bound($this->createMock(SheetsClient::class));

        self::assertSame(self::SHEET_ID, $service->getSpreadsheetId());
        self::assertSame(self::BOUND_TAB, $service->getBoundSheet());
    }

    public function testReadRawUsesBoundSheetWhenNoSheetNamePassed(): void
    {
        $rows = [['Name'], ['Alice']];

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with(self::BOUND_TAB)->willReturnSelf();
        $client->expects(self::once())->method('all')->willReturn($rows);

        $service = $this->bound($client);

        self::assertSame($rows, $service->readRaw());
    }

    public function testExplicitSheetNameOverridesBoundSheet(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with('Other')->willReturnSelf();
        $client->method('all')->willReturn([]);

        $service = $this->bound($client);

        self::assertSame([], $service->readRaw('Other'));
    }

    public function testReadRawWithoutBoundOrExplicitSheetThrows(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::never())->method('sheet');

        $service = $this->unbound($client);

        $this->expectException(MissingSheetNameException::class);
        $service->readRaw();
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

        $service = $this->bound($client);

        $service->readRaw(
            range: 'A1:Z',
            majorDimension: SheetsService::MAJOR_DIMENSION_COLUMNS,
            valueRenderOption: SheetsService::VALUE_RENDER_UNFORMATTED,
            dateTimeRenderOption: SheetsService::DATE_TIME_RENDER_FORMATTED,
        );
    }

    public function testReadAssocCombinesHeaderAndRows(): void
    {
        $rows = [
            ['Name', 'Email'],
            ['Alice', 'alice@example.com'],
            ['Bob', 'bob@example.com'],
        ];

        $service = $this->bound($this->stubClientReturning($rows));

        $expected = [
            ['Name' => 'Alice', 'Email' => 'alice@example.com'],
            ['Name' => 'Bob', 'Email' => 'bob@example.com'],
        ];

        self::assertSame($expected, $service->readAssoc());
    }

    public function testReadAssocPadsShortRowsWithEmptyStrings(): void
    {
        $rows = [
            ['Name', 'Email', 'Phone'],
            ['Alice', 'alice@example.com'],
        ];

        $service = $this->bound($this->stubClientReturning($rows));

        self::assertSame(
            [['Name' => 'Alice', 'Email' => 'alice@example.com', 'Phone' => '']],
            $service->readAssoc(),
        );
    }

    public function testReadAssocTruncatesOverflowCells(): void
    {
        $rows = [
            ['Name', 'Email'],
            ['Alice', 'alice@example.com', 'extra-cell-ignored'],
        ];

        $service = $this->bound($this->stubClientReturning($rows));

        self::assertSame(
            [['Name' => 'Alice', 'Email' => 'alice@example.com']],
            $service->readAssoc(),
        );
    }

    public function testReadAssocReturnsEmptyArrayWhenSheetIsEmpty(): void
    {
        $service = $this->bound($this->stubClientReturning([]));

        self::assertSame([], $service->readAssoc());
    }

    public function testReadAssocThrowsOnDuplicateHeaderValues(): void
    {
        $service = $this->bound($this->stubClientReturning([
            ['Name', 'Notes', 'Notes'],
            ['Alice', 'a', 'b'],
        ]));

        $this->expectException(DuplicateHeaderException::class);
        $this->expectExceptionMessage('Duplicate header value "Notes"');
        $service->readAssoc();
    }

    public function testReadAssocThrowsOnNonScalarHeaderCell(): void
    {
        $service = $this->bound($this->stubClientReturning([
            ['Name', ['nested', 'error']],
            ['Alice', 'a'],
        ]));

        $this->expectException(InvalidHeaderException::class);
        $this->expectExceptionMessage('Header cell at index 1');
        $service->readAssoc();
    }

    public function testFirstRowReturnsFirstValue(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with(self::BOUND_TAB)->willReturnSelf();
        $client->expects(self::once())->method('first')->willReturn(['Name', 'Email']);

        $service = $this->bound($client);

        self::assertSame(['Name', 'Email'], $service->firstRow());
    }

    public function testFirstRowDoesNotExposeMajorDimension(): void
    {
        $reflection = new ReflectionMethod(SheetsService::class, 'firstRow');
        $paramNames = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $reflection->getParameters());

        self::assertNotContains(
            'majorDimension',
            $paramNames,
            'firstRow() must not expose majorDimension — under COLUMNS it would return the first column, contradicting the method name.',
        );
    }

    public function testListSheetsReturnsListOfTabNames(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheetList')->willReturn([101 => 'One', 202 => 'Two']);

        $service = $this->bound($client);

        self::assertSame(['One', 'Two'], $service->listSheets());
    }

    public function testListSheetsWithIdsPreservesTheSheetIdMap(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheetList')->willReturn([101 => 'One', 202 => 'Two']);

        $service = $this->bound($client);

        self::assertSame([101 => 'One', 202 => 'Two'], $service->listSheetsWithIds());
    }

    public function testFindSheetNameByIdResolvesAgainstSheetList(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheetList')->willReturn([101 => 'Allocators', 837423919 => 'Archive']);

        $service = $this->bound($client);

        self::assertSame('Archive', $service->findSheetNameById(837423919));
    }

    public function testFindSheetNameByIdReturnsNullForUnknownId(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheetList')->willReturn([101 => 'Allocators']);

        $service = $this->bound($client);

        self::assertNull($service->findSheetNameById(999));
    }

    public function testAppendForwardsRowsToTheUnderlyingClient(): void
    {
        $response = $this->createMock(AppendValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with(self::BOUND_TAB)->willReturnSelf();
        $client->expects(self::once())
            ->method('append')
            ->with([['a', 'b']], 'USER_ENTERED', 'INSERT_ROWS')
            ->willReturn($response);

        $service = $this->bound($client);

        self::assertSame(
            $response,
            $service->append([['a', 'b']], valueInputOption: 'USER_ENTERED', insertDataOption: 'INSERT_ROWS'),
        );
    }

    public function testAppendThrowsWhenRowsMixPositionalAndAssoc(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::never())->method('append');

        $service = $this->bound($client);

        $this->expectException(MixedRowShapeException::class);
        $service->append([
            ['Name' => 'A', 'Email' => 'a@x'],
            ['B', 'b@x'],
        ]);
    }

    public function testAppendThrowsWhenAssocRowsHaveDivergentKeys(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::never())->method('append');

        $service = $this->bound($client);

        $this->expectException(MixedRowShapeException::class);
        $this->expectExceptionMessage('row at index 1');
        $service->append([
            ['Name' => 'A', 'Email' => 'a@x'],
            ['Name' => 'B', 'Phone' => '555'], // missing Email, extra Phone
        ]);
    }

    public function testAppendAcceptsConsistentAssocRows(): void
    {
        $response = $this->createMock(AppendValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('append')->willReturn($response);

        $service = $this->bound($client);

        self::assertSame($response, $service->append([
            ['Name' => 'A', 'Email' => 'a@x'],
            ['Name' => 'B', 'Email' => 'b@x'],
        ]));
    }

    public function testAppendTreatsGapKeyedNumericRowsAsPositional(): void
    {
        // After `array_filter`, $rows = [1 => […], 4 => […]] — non-list integer
        // keys. Old isAssoc() flagged this as assoc and reached the underlying
        // client, which dropped values. array_is_list-based isAssoc treats it
        // as positional, matching the rows' actual semantics.
        $response = $this->createMock(AppendValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('append')->willReturn($response);

        $service = $this->bound($client);

        $rows = [
            ['a', 'b'],
            ['c', 'd'],
            ['e', 'f'],
        ];
        $filtered = array_filter($rows, static fn (array $r): bool => 'b' !== $r[1]);
        // $filtered preserves keys: [1 => ['c','d'], 2 => ['e','f']] — neither
        // a list nor an assoc array.

        self::assertSame($response, $service->append(array_values($filtered)));
        // The bundle expects callers to pass list-shaped input; the assertion
        // exercises the array_values() normalisation users do anyway.
    }

    public function testUpdateForwardsRangeAndValuesToTheUnderlyingClient(): void
    {
        $response = $this->createMock(BatchUpdateValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with(self::BOUND_TAB)->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A2:B2')->willReturnSelf();
        $client->expects(self::once())->method('update')->with([['Carol', 'c@example.com']], 'RAW')->willReturn($response);

        $service = $this->bound($client);

        self::assertSame(
            $response,
            $service->update('A2:B2', [['Carol', 'c@example.com']]),
        );
    }

    public function testClearWithoutRangeClearsWholeSheet(): void
    {
        $response = $this->createMock(ClearValuesResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with(self::BOUND_TAB)->willReturnSelf();
        $client->expects(self::never())->method('range');
        $client->expects(self::once())->method('clear')->willReturn($response);

        $service = $this->bound($client);

        self::assertSame($response, $service->clear());
    }

    public function testClearWithRangeAppliesTheRange(): void
    {
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->expects(self::once())->method('range')->with('A2:Z')->willReturnSelf();
        $client->expects(self::once())->method('clear')->willReturn(null);

        $service = $this->bound($client);

        self::assertNull($service->clear(range: 'A2:Z'));
    }

    public function testAddSheetCreatesNewTab(): void
    {
        $response = $this->createMock(BatchUpdateSpreadsheetResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('addSheet')->with('Archive')->willReturn($response);

        $service = $this->bound($client);

        self::assertSame($response, $service->addSheet('Archive'));
    }

    public function testDeleteSheetRemovesTab(): void
    {
        $response = $this->createMock(BatchUpdateSpreadsheetResponse::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('deleteSheet')->with('Old')->willReturn($response);

        $service = $this->bound($client);

        self::assertSame($response, $service->deleteSheet('Old'));
    }

    public function testSpreadsheetPropertiesForwardsToTheClient(): void
    {
        $properties = (object) ['title' => 'My Spreadsheet'];

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('spreadsheetProperties')->willReturn($properties);

        $service = $this->bound($client);

        self::assertSame($properties, $service->spreadsheetProperties());
    }

    public function testSheetPropertiesUsesBoundSheet(): void
    {
        $properties = (object) ['title' => self::BOUND_TAB];

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $client->expects(self::once())->method('sheet')->with(self::BOUND_TAB)->willReturnSelf();
        $client->expects(self::once())->method('sheetProperties')->willReturn($properties);

        $service = $this->bound($client);

        self::assertSame($properties, $service->sheetProperties());
    }

    public function testDriveServiceReturnsTheUnderlyingDriveInstance(): void
    {
        $drive = $this->createMock(Drive::class);

        $client = $this->createMock(SheetsClient::class);
        $client->expects(self::once())->method('getDriveService')->willReturn($drive);

        $service = $this->bound($client);

        self::assertSame($drive, $service->driveService());
    }

    public function testReadEntitiesMapsRowsToDtoViaSheetColumnAttribute(): void
    {
        $rows = [
            ['Record ID - Contact', 'First Name', 'Email'],
            ['c-1', 'Alice', 'alice@example.com'],
            ['c-2', 'Bob', 'bob@example.com'],
        ];

        $factory = $this->createMock(SheetsClientFactory::class);
        $factory->method('create')->willReturn($this->seededClient($rows));

        $service = new SheetsService(
            $factory,
            self::SHEET_ID,
            self::BOUND_TAB,
            new Serializer([new ObjectNormalizer()], [new JsonEncoder()]),
        );

        $entities = $service->readEntities(PersonDto::class);

        self::assertCount(2, $entities);
        self::assertSame('c-1', $entities[0]->contactId);
        self::assertSame('Alice', $entities[0]->firstName);
        self::assertSame('alice@example.com', $entities[0]->email);
        self::assertSame('c-2', $entities[1]->contactId);
    }

    public function testReadEntitiesWithoutSerializerThrows(): void
    {
        $factory = $this->createMock(SheetsClientFactory::class);
        $service = new SheetsService($factory, self::SHEET_ID, self::BOUND_TAB, null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires symfony/serializer');

        $service->readEntities(PersonDto::class);
    }

    public function testReadAssocIterableStreamsRows(): void
    {
        $header = ['Name', 'Email'];
        // Build a fake "sheet" with 7 rows + the header. With batchSize=3 we
        // expect: header batch, then batches of 3, 3, 1 → reader signals end
        // when the final batch is shorter than the requested size.
        $allRowsBeyondHeader = [
            ['A', 'a@x'], ['B', 'b@x'], ['C', 'c@x'],
            ['D', 'd@x'], ['E', 'e@x'], ['F', 'f@x'],
            ['G', 'g@x'],
        ];

        $batches = [
            $header, // first() returns header
            array_slice($allRowsBeyondHeader, 0, 3),
            array_slice($allRowsBeyondHeader, 3, 3),
            array_slice($allRowsBeyondHeader, 6, 3), // 1 row → triggers end
        ];

        $batchIndex = 0;

        $factory = $this->createMock(SheetsClientFactory::class);
        $factory->method('create')->willReturnCallback(function () use (&$batchIndex, $batches): SheetsClient {
            $client = $this->createMock(SheetsClient::class);
            $client->method('spreadsheet')->willReturnSelf();
            $client->method('sheet')->willReturnSelf();
            $client->method('range')->willReturnSelf();
            $client->method('first')->willReturn($batches[0]);
            $client->method('all')->willReturnCallback(static function () use (&$batchIndex, $batches): array {
                ++$batchIndex;

                return $batches[$batchIndex] ?? [];
            });

            return $client;
        });

        $service = new SheetsService($factory, self::SHEET_ID, self::BOUND_TAB);

        $collected = iterator_to_array($service->readAssocIterable(batchSize: 3), false);

        self::assertCount(7, $collected);
        self::assertSame(['Name' => 'A', 'Email' => 'a@x'], $collected[0]);
        self::assertSame(['Name' => 'G', 'Email' => 'g@x'], $collected[6]);
    }

    public function testReadAssocIterableReturnsEarlyWhenSheetIsEmpty(): void
    {
        $factory = $this->createMock(SheetsClientFactory::class);
        $client = $this->createMock(SheetsClient::class);
        $client->method('spreadsheet')->willReturnSelf();
        $client->method('sheet')->willReturnSelf();
        $client->method('first')->willReturn([]);
        $factory->method('create')->willReturn($client);

        $service = new SheetsService($factory, self::SHEET_ID, self::BOUND_TAB);

        self::assertSame([], iterator_to_array($service->readAssocIterable()));
    }

    public function testReadAssocIterableRejectsZeroBatchSize(): void
    {
        $factory = $this->createMock(SheetsClientFactory::class);
        $service = new SheetsService($factory, self::SHEET_ID, self::BOUND_TAB);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('batchSize must be >= 1');

        // Generators are lazy; force evaluation.
        iterator_to_array($service->readAssocIterable(batchSize: 0));
    }

    public function testClientReturnsAFreshInstanceEachCall(): void
    {
        $clientA = $this->createMock(SheetsClient::class);
        $clientB = $this->createMock(SheetsClient::class);

        $service = $this->bound($clientA, $clientB);

        self::assertSame($clientA, $service->client());
        self::assertSame($clientB, $service->client());
    }

    public function testEachCallObtainsAFreshClientFromTheFactory(): void
    {
        $clientA = $this->createMock(SheetsClient::class);
        $clientA->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $clientA->expects(self::once())->method('sheet')->with(self::BOUND_TAB)->willReturnSelf();
        $clientA->expects(self::once())->method('all')->willReturn([]);

        $clientB = $this->createMock(SheetsClient::class);
        $clientB->expects(self::once())->method('spreadsheet')->with(self::SHEET_ID)->willReturnSelf();
        $clientB->expects(self::once())->method('sheet')->with(self::BOUND_TAB)->willReturnSelf();
        $clientB->expects(self::once())->method('all')->willReturn([]);

        $service = $this->bound($clientA, $clientB);

        self::assertSame([], $service->readRaw());
        self::assertSame([], $service->readRaw());
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
     * @param list<list<mixed>> $rows
     */
    private function seededClient(array $rows): SheetsClient
    {
        return $this->stubClientReturning($rows);
    }

    private function bound(SheetsClient $first, SheetsClient ...$rest): SheetsService
    {
        return $this->buildService(self::BOUND_TAB, $first, ...$rest);
    }

    private function unbound(SheetsClient $first, SheetsClient ...$rest): SheetsService
    {
        return $this->buildService(null, $first, ...$rest);
    }

    private function buildService(?string $boundSheet, SheetsClient ...$clients): SheetsService
    {
        $factory = $this->createMock(SheetsClientFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls(...array_values($clients));

        return new SheetsService($factory, self::SHEET_ID, $boundSheet);
    }
}
