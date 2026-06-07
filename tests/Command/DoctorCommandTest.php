<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Command;

use Gulaandrij\GoogleSheetsBundle\Command\DoctorCommand;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsRegistry;
use Gulaandrij\GoogleSheetsBundle\Test\InMemorySheetsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * @internal
 */
#[CoversClass(DoctorCommand::class)]
final class DoctorCommandTest extends TestCase
{
    public function testAllOkReturnsSuccess(): void
    {
        $tester = new CommandTester($this->buildCommand(boundExists: true));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('All bindings reachable', $tester->getDisplay());
    }

    public function testMissingBoundSheetWarnsButSucceedsByDefault(): void
    {
        $tester = new CommandTester($this->buildCommand(boundExists: false));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Some bound sheets do not exist', $tester->getDisplay());
        self::assertStringContainsString('--strict', $tester->getDisplay());
    }

    public function testMissingBoundSheetFailsWhenStrictIsSet(): void
    {
        $tester = new CommandTester($this->buildCommand(boundExists: false));

        self::assertSame(Command::FAILURE, $tester->execute(['--strict' => true]));
        self::assertStringContainsString('(--strict)', $tester->getDisplay());
    }

    private function buildCommand(bool $boundExists): DoctorCommand
    {
        $sheets = $boundExists ? ['Allocator List' => [['Name'], ['Alice']]] : ['Something Else' => []];
        $service = new InMemorySheetsService($sheets, boundSheet: 'Allocator List');

        $registry = new SheetsRegistry(
            ['allocators' => ['id' => '1abc', 'sheet' => 'Allocator List']],
            new ServiceLocator(['allocators' => fn () => $service]),
        );

        return new DoctorCommand($registry);
    }
}
