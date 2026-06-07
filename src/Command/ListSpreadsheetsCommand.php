<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Command;

use Gulaandrij\GoogleSheetsBundle\Service\SheetsRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-sheets:list',
    description: 'List every spreadsheet binding configured under google_sheets.spreadsheets.',
)]
final class ListSpreadsheetsCommand extends Command
{
    public function __construct(private readonly SheetsRegistry $registry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $names = $this->registry->names();
        if ([] === $names) {
            $io->warning('No spreadsheets configured. Add entries under google_sheets.spreadsheets.<name>.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($names as $name) {
            $meta = $this->registry->metadata($name);
            $rows[] = [
                $name,
                $meta['id'],
                $meta['sheet'] ?? '<no bound sheet>',
            ];
        }

        $io->title(sprintf('%d configured spreadsheet(s)', count($rows)));
        $io->table(['Name', 'Spreadsheet ID', 'Bound sheet'], $rows);

        return Command::SUCCESS;
    }
}
