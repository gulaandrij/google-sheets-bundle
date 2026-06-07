<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Command;

use Gulaandrij\GoogleSheetsBundle\Service\SheetsRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $names = $this->registry->names();
        if ([] === $names) {
            $io->warning('No spreadsheets configured.');

            return Command::SUCCESS;
        }

        $io->title('Probing each binding…');

        $allOk = true;
        $rows = [];

        foreach ($names as $name) {
            $service = $this->registry->service($name);
            $meta = $this->registry->metadata($name);

            $start = microtime(true);
            try {
                $tabs = $service->listSheetsWithIds();
                $boundSheet = $meta['sheet'];
                $boundOk = null === $boundSheet || in_array($boundSheet, $tabs, true);

                $status = $boundOk ? '<info>OK</info>' : '<comment>BOUND-SHEET-MISSING</comment>';
                $detail = sprintf('%d tab(s) reachable', count($tabs));
                if (!$boundOk) {
                    $detail .= sprintf('; bound sheet "%s" not found', $boundSheet);
                    $allOk = false;
                }
            } catch (Throwable $e) {
                $status = '<error>FAIL</error>';
                $detail = sprintf('%s: %s', $e::class, $e->getMessage());
                $allOk = false;
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

        if ($allOk) {
            $io->success('All bindings reachable.');

            return Command::SUCCESS;
        }

        $io->error('One or more bindings failed. See detail column above.');

        return Command::FAILURE;
    }
}
