<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Service;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;

/**
 * Read-only directory of every named spreadsheet configured under
 * `google_sheets.spreadsheets`. Lets consumers — typically console commands
 * and health checks — enumerate bindings and pull their `SheetsService`
 * instances by name without hard-coding service IDs.
 *
 * The service catalog is a Symfony ServiceLocator wired by the bundle; the
 * metadata array mirrors the resolved config tree.
 *
 * @phpstan-type SpreadsheetEntry array{id: string, sheet: string|null}
 */
final class SheetsRegistry
{
    /**
     * @param array<string, SpreadsheetEntry> $spreadsheets
     */
    public function __construct(
        private readonly array $spreadsheets,
        private readonly ContainerInterface $services,
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->spreadsheets);
    }

    public function has(string $name): bool
    {
        return isset($this->spreadsheets[$name]);
    }

    /**
     * @return SpreadsheetEntry
     */
    public function metadata(string $name): array
    {
        if (!isset($this->spreadsheets[$name])) {
            throw new InvalidArgumentException(sprintf('No spreadsheet named "%s" is configured. Declared: %s', $name, [] === $this->names() ? '(none)' : implode(', ', $this->names())));
        }

        return $this->spreadsheets[$name];
    }

    public function service(string $name): SheetsService
    {
        if (!$this->has($name)) {
            throw new InvalidArgumentException(sprintf('No spreadsheet named "%s" is configured. Declared: %s', $name, [] === $this->names() ? '(none)' : implode(', ', $this->names())));
        }

        /** @var SheetsService $service */
        $service = $this->services->get($name);

        return $service;
    }
}
