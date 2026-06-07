<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Test;

use Gulaandrij\GoogleSheetsBundle\Test\InMemorySheetsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(InMemorySheetsService::class)]
final class InMemorySheetsServiceTest extends TestCase
{
    public function testReadAssocReturnsSeededRows(): void
    {
        $service = new InMemorySheetsService([
            'Allocator List' => [
                ['Name', 'Email'],
                ['Alice', 'alice@example.com'],
                ['Bob', 'bob@example.com'],
            ],
        ], boundSheet: 'Allocator List');

        self::assertSame(
            [
                ['Name' => 'Alice', 'Email' => 'alice@example.com'],
                ['Name' => 'Bob', 'Email' => 'bob@example.com'],
            ],
            $service->readAssoc(),
        );
    }

    public function testAppendAssocRowsReorderedByExistingHeader(): void
    {
        $service = new InMemorySheetsService([
            'tab' => [['Name', 'Email']],
        ], boundSheet: 'tab');

        $service->append([
            ['Email' => 'a@x', 'Name' => 'Alice'],
        ]);

        self::assertSame(
            [['Name', 'Email'], ['Alice', 'a@x']],
            $service->dump()['tab'],
        );
    }

    public function testAppendPositionalRowsLandInOrder(): void
    {
        $service = new InMemorySheetsService(['tab' => []], boundSheet: 'tab');

        $service->append([['A', 'B'], ['C', 'D']]);

        self::assertSame([['A', 'B'], ['C', 'D']], $service->dump()['tab']);
    }

    public function testClearWipesTheSheet(): void
    {
        $service = new InMemorySheetsService([
            'tab' => [['Name'], ['Alice']],
        ], boundSheet: 'tab');

        $service->clear();

        self::assertSame([], $service->dump()['tab']);
    }

    public function testAddSheetCreatesEmptyTabAndDeleteSheetRemovesIt(): void
    {
        $service = new InMemorySheetsService();

        $service->addSheet('New');
        self::assertContains('New', $service->listSheets());

        $service->deleteSheet('New');
        self::assertNotContains('New', $service->listSheets());
    }

    public function testReadAssocIterableMatchesReadAssoc(): void
    {
        $service = new InMemorySheetsService([
            'tab' => [
                ['Name', 'Email'],
                ['Alice', 'a@x'],
                ['Bob', 'b@x'],
            ],
        ], boundSheet: 'tab');

        $streamed = iterator_to_array($service->readAssocIterable(), false);

        self::assertSame($service->readAssoc(), $streamed);
    }

    public function testFindSheetNameByIdResolvesAgainstPositionalIndex(): void
    {
        $service = new InMemorySheetsService([
            'first' => [],
            'second' => [],
        ]);

        self::assertSame('first', $service->findSheetNameById(0));
        self::assertSame('second', $service->findSheetNameById(1));
        self::assertNull($service->findSheetNameById(99));
    }

    public function testGetSpreadsheetIdAndBoundSheetExposeConstructorValues(): void
    {
        $service = new InMemorySheetsService(spreadsheetId: 'fake-id', boundSheet: 'Tab');

        self::assertSame('fake-id', $service->getSpreadsheetId());
        self::assertSame('Tab', $service->getBoundSheet());
    }
}
