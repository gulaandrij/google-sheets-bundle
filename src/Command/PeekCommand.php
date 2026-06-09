<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Command;

use Gulaandrij\GoogleSheetsBundle\Service\SheetsRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'google-sheets:peek',
    description: 'Print the first N rows from a binding (or a specific tab) as a console table.',
)]
final class PeekCommand extends Command
{
    public function __construct(private readonly SheetsRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('binding', InputArgument::REQUIRED, 'Spreadsheet binding name.')
            ->addArgument('sheet', InputArgument::OPTIONAL, 'Sheet/tab name (defaults to the binding\'s bound sheet).')
            ->addOption('rows', null, InputOption::VALUE_REQUIRED, 'Max rows to display.', '10')
            ->addOption('range', null, InputOption::VALUE_REQUIRED, 'A1-notation range to fetch (e.g. A1:Z20). When set, the first row of the range is shown as data, not the header — pass --with-header if your range happens to start at the actual header row.')
            ->addOption('with-header', null, InputOption::VALUE_NONE, 'Force header parsing even when --range is set (use when your range includes the header row).')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $bindingRaw = $input->getArgument('binding');
        $binding = is_string($bindingRaw) ? $bindingRaw : '';

        $sheet = $input->getArgument('sheet');
        $rowsLimitRaw = $input->getOption('rows');
        $rowsLimit = max(1, is_numeric($rowsLimitRaw) ? (int) $rowsLimitRaw : 10);
        $range = $input->getOption('range');

        if (!$this->registry->has($binding)) {
            $configured = [] === $this->registry->names() ? '(none)' : implode(', ', $this->registry->names());
            $io->error(sprintf('Unknown binding "%s". Configured: %s', $binding, $configured));

            return Command::INVALID;
        }

        $service = $this->registry->service($binding);
        $sheetName = is_string($sheet) ? $sheet : null;
        $rangeStr = is_string($range) ? $range : null;

        try {
            $rows = $service->readRaw($sheetName, $rangeStr);
        } catch (Throwable $e) {
            $io->error(sprintf('Read failed: %s — %s', $e::class, $e->getMessage()));

            return Command::FAILURE;
        }

        if ([] === $rows) {
            $io->warning('Sheet is empty.');

            return Command::SUCCESS;
        }

        // When --range is set the user usually wants to see ALL rows in the
        // range, not skip the first one assuming it's a header — only the A1
        // (or whole-sheet) read gets the auto-header behaviour. The
        // --with-header flag opts back in when the range happens to include
        // the real header row.
        if (null === $rangeStr || true === $input->getOption('with-header')) {
            $header = array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '?',
                $rows[0],
            );
            $body = array_slice($rows, 1, $rowsLimit);
        } else {
            $columnCount = count($rows[0]);
            // range(0, -1) returns [0] in PHP — would emit a phantom 'A'
            // column for a leading empty row.
            $header = 0 === $columnCount
                ? []
                : array_map(
                    static fn (int $i): string => self::columnLetter($i + 1),
                    range(0, $columnCount - 1),
                );
            $body = array_slice($rows, 0, $rowsLimit);
        }
        $stringify = static function (mixed $v): string {
            if (null === $v) {
                return '';
            }
            if (is_scalar($v)) {
                return (string) $v;
            }
            $encoded = json_encode($v);

            return false === $encoded ? '<unencodable>' : $encoded;
        };
        $bodyAsStrings = array_map(
            static fn (array $row): array => array_map($stringify, $row),
            $body,
        );

        $io->title(sprintf(
            'Peek %s/%s (%d row(s) shown%s)',
            $binding,
            $sheetName ?? $service->getBoundSheet() ?? '<unbound>',
            count($body),
            null !== $rangeStr ? ', range '.$rangeStr : '',
        ));
        $io->table($header, $bodyAsStrings);

        return Command::SUCCESS;
    }

    /**
     * Convert a 1-based column index into A1-notation column letters
     * (1 → A, 27 → AA). Mirrors SheetsService::columnLetter() but kept
     * local to keep that helper private.
     */
    private static function columnLetter(int $columnIndex): string
    {
        $letters = '';
        while ($columnIndex > 0) {
            --$columnIndex;
            $letters = chr(65 + ($columnIndex % 26)).$letters;
            $columnIndex = intdiv($columnIndex, 26);
        }

        return $letters;
    }
}
