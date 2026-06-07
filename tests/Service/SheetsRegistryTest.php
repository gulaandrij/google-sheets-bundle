<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Service;

use Closure;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsClientFactory;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsRegistry;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * @internal
 */
#[CoversClass(SheetsRegistry::class)]
#[AllowMockObjectsWithoutExpectations]
final class SheetsRegistryTest extends TestCase
{
    public function testNamesEnumeratesBindings(): void
    {
        $registry = $this->buildRegistry(
            [
                'allocators' => ['id' => '1abc', 'sheet' => 'List'],
                'reports' => ['id' => '1xyz', 'sheet' => null],
            ],
            [],
        );

        self::assertSame(['allocators', 'reports'], $registry->names());
        self::assertTrue($registry->has('allocators'));
        self::assertFalse($registry->has('missing'));
    }

    public function testMetadataReturnsConfiguredEntry(): void
    {
        $registry = $this->buildRegistry(
            ['allocators' => ['id' => '1abc', 'sheet' => 'List']],
            [],
        );

        self::assertSame(['id' => '1abc', 'sheet' => 'List'], $registry->metadata('allocators'));
    }

    public function testMetadataThrowsForUnknownBinding(): void
    {
        $registry = $this->buildRegistry(['allocators' => ['id' => '1', 'sheet' => null]], []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No spreadsheet named "reports"');

        $registry->metadata('reports');
    }

    public function testServiceReturnsLocatedInstance(): void
    {
        $sheets = new SheetsService($this->createMock(SheetsClientFactory::class), '1abc', 'List');

        $registry = $this->buildRegistry(
            ['allocators' => ['id' => '1abc', 'sheet' => 'List']],
            ['allocators' => static fn (): SheetsService => $sheets],
        );

        self::assertSame($sheets, $registry->service('allocators'));
    }

    public function testServiceThrowsForUnknownBinding(): void
    {
        $registry = $this->buildRegistry([], []);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('(none)');

        $registry->service('reports');
    }

    /**
     * @param array<string, array{id: string, sheet: string|null}> $metadata
     * @param array<string, Closure(): SheetsService>              $factories
     */
    private function buildRegistry(array $metadata, array $factories): SheetsRegistry
    {
        return new SheetsRegistry($metadata, new ServiceLocator($factories));
    }
}
