<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Command;

use Gulaandrij\GoogleSheetsBundle\Service\SheetsRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'google-sheets:tabs',
    description: 'List every tab (sheet) in the spreadsheet bound to a given binding.',
)]
final class TabsCommand extends Command
{
    public function __construct(private readonly SheetsRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'binding',
            InputArgument::REQUIRED,
            'Spreadsheet binding name (key under google_sheets.spreadsheets).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $bindingRaw = $input->getArgument('binding');
        $binding = is_string($bindingRaw) ? $bindingRaw : '';

        if (!$this->registry->has($binding)) {
            $configured = [] === $this->registry->names() ? '(none)' : implode(', ', $this->registry->names());
            $io->error(sprintf('Unknown binding "%s". Configured: %s', $binding, $configured));

            return Command::INVALID;
        }

        $service = $this->registry->service($binding);
        $tabs = $service->listSheetsWithIds();

        if ([] === $tabs) {
            $io->warning('Spreadsheet has no tabs (or the credential cannot see them).');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($tabs as $sheetId => $title) {
            $rows[] = [(string) $sheetId, $title];
        }

        $io->title(sprintf('%d tab(s) in "%s" (%s)', count($rows), $binding, $service->getSpreadsheetId()));
        $io->table(['Sheet ID', 'Title'], $rows);

        return Command::SUCCESS;
    }
}
