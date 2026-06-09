<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Command;

use Gulaandrij\GoogleSheetsBundle\Service\SheetsRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'google-sheets:doctor',
    description: 'Probe every configured spreadsheet binding and report reachability + auth issues.',
)]
final class DoctorCommand extends Command
{
    public function __construct(private readonly SheetsRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'strict',
            null,
            InputOption::VALUE_NONE,
            'Treat a missing bound sheet as a failure (default: warning). Use in deploy pipelines that expect every configured tab to already exist.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $strict = true === $input->getOption('strict');

        $names = $this->registry->names();
        if ([] === $names) {
            $io->warning('No spreadsheets configured.');

            return Command::SUCCESS;
        }

        $io->title('Probing each binding…');

        $hardFailure = false;
        $missingSheets = false;
        $rows = [];

        foreach ($names as $name) {
            $service = $this->registry->service($name);
            $meta = $this->registry->metadata($name);

            $start = microtime(true);
            try {
                $tabs = $service->listSheetsWithIds();
                $boundSheet = $meta['sheet'];
                if (null === $boundSheet || in_array($boundSheet, $tabs, true)) {
                    $status = '<info>OK</info>';
                    $detail = sprintf('%d tab(s) reachable', count($tabs));
                } else {
                    $missingSheets = true;
                    $status = '<comment>BOUND-SHEET-MISSING</comment>';
                    $detail = sprintf('%d tab(s) reachable; bound sheet "%s" not found', count($tabs), $boundSheet);
                }
            } catch (Throwable $e) {
                $hardFailure = true;
                $status = '<error>FAIL</error>';
                $detail = sprintf('%s: %s', $e::class, $e->getMessage());
            }

            $durationMs = (microtime(true) - $start) * 1000.0;

            $rows[] = [
                $name,
                $meta['id'],
                $meta['sheet'] ?? '—',
                $status,
                sprintf('%.0f ms', $durationMs),
                $detail,
            ];
        }

        $io->table(
            ['Binding', 'Spreadsheet ID', 'Bound sheet', 'Status', 'Time', 'Detail'],
            $rows,
        );

        if ($hardFailure) {
            $io->error('One or more bindings failed to reach the API. See detail column above.');

            return Command::FAILURE;
        }

        if ($missingSheets) {
            if ($strict) {
                $io->error('One or more bindings reference a sheet that does not exist (--strict).');

                return Command::FAILURE;
            }
            $io->warning('Some bound sheets do not exist yet. Pass --strict to fail the command on this condition.');

            return Command::SUCCESS;
        }

        $io->success('All bindings reachable.');

        return Command::SUCCESS;
    }
}
