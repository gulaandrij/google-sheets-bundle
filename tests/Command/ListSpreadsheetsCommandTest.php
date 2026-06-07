<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Command;

use Gulaandrij\GoogleSheetsBundle\Command\ListSpreadsheetsCommand;
use Gulaandrij\GoogleSheetsBundle\Service\SheetsRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * @internal
 */
#[CoversClass(ListSpreadsheetsCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class ListSpreadsheetsCommandTest extends TestCase
{
    public function testEmptyRegistryWarnsButSucceeds(): void
    {
        $tester = new CommandTester(new ListSpreadsheetsCommand(new SheetsRegistry([], new ServiceLocator([]))));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('No spreadsheets configured', $tester->getDisplay());
    }

    public function testListsConfiguredBindings(): void
    {
        $registry = new SheetsRegistry(
            [
                'allocators' => ['id' => '1abc_allocators', 'sheet' => 'List'],
                'reports' => ['id' => '1xyz_reports', 'sheet' => null],
            ],
            new ServiceLocator([]),
        );
        $tester = new CommandTester(new ListSpreadsheetsCommand($registry));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('allocators', $output);
        self::assertStringContainsString('1abc_allocators', $output);
        self::assertStringContainsString('List', $output);
        self::assertStringContainsString('reports', $output);
        self::assertStringContainsString('<no bound sheet>', $output);
    }
}
