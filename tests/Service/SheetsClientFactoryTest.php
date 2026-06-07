<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Service;

use Google\Service\Drive;
use Google\Service\Sheets as GoogleSheets;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @internal
 */
#[CoversClass(SheetsClientFactory::class)]
#[AllowMockObjectsWithoutExpectations]
final class SheetsClientFactoryTest extends TestCase
{
    public function testCreateReturnsAFreshSheetsClientWithBothServicesInjected(): void
    {
        $sheets = $this->createMock(GoogleSheets::class);
        $drive = $this->createMock(Drive::class);

        $factory = new SheetsClientFactory($sheets, $drive);

        $clientA = $factory->create();
        $clientB = $factory->create();

        self::assertNotSame($clientA, $clientB);
        self::assertSame($sheets, $clientA->getService());
        self::assertSame($drive, $clientA->getDriveService());
        self::assertSame($sheets, $clientB->getService());
        self::assertSame($drive, $clientB->getDriveService());
    }

    public function testListSpreadsheetsExists(): void
    {
        // The implementation forwards directly to SheetsClient::spreadsheetList(),
        // which hits Google Drive. The unit boundary here is the method
        // signature + delegation; an integration test against a real Google
        // credential covers the end-to-end behaviour.
        $reflection = new ReflectionMethod(SheetsClientFactory::class, 'listSpreadsheets');

        self::assertSame('array', (string) $reflection->getReturnType());
        self::assertSame([], $reflection->getParameters());
    }
}
