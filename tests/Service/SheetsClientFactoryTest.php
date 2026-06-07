<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Service;

use Google\Service\Drive;
use Google\Service\Sheets as GoogleSheets;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
}
