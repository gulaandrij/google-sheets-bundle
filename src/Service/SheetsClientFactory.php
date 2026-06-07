<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Service;

use Google\Service\Drive;
use Google\Service\Sheets as GoogleSheets;
use Revolution\Google\Sheets\SheetsClient;

/**
 * Builds a fresh `SheetsClient` per call so consumers never inherit stateful
 * selectors (`range`, `majorDimension`, `valueRenderOption`, etc.) from another
 * caller. The underlying `Google\Service\Sheets` and `Google\Service\Drive`
 * are shared because they are stateless after construction; only the
 * `SheetsClient` wrapping them carries selector state.
 *
 * Also exposes spreadsheet-agnostic operations (currently just
 * `listSpreadsheets()`) that don't fit on the per-spreadsheet `SheetsService`.
 */
class SheetsClientFactory
{
    public function __construct(
        private readonly GoogleSheets $service,
        private readonly Drive $drive,
    ) {
    }

    public function create(): SheetsClient
    {
        $client = new SheetsClient();
        $client->setService($this->service);
        $client->setDriveService($this->drive);

        return $client;
    }

    /**
     * List every Google Sheets file the credential can see, as a
     * `fileId => title` map. Global Drive query — independent of any
     * `SheetsService` binding. Requires a Drive read scope.
     *
     * @return array<string, string>
     */
    public function listSpreadsheets(): array
    {
        /** @var array<string, string> $list */
        $list = $this->create()->spreadsheetList();

        return $list;
    }
}
