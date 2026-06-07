<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Service;

use Google\Service\Sheets as GoogleSheets;
use Revolution\Google\Sheets\SheetsClient;

/**
 * Builds a fresh `SheetsClient` per call so consumers never inherit stateful
 * selectors (`range`, `majorDimension`, `valueRenderOption`, etc.) from another
 * caller. The underlying `Google\Service\Sheets` is shared because it is
 * stateless after construction.
 */
class SheetsClientFactory
{
    public function __construct(private readonly GoogleSheets $service)
    {
    }

    public function create(): SheetsClient
    {
        $client = new SheetsClient();
        $client->setService($this->service);

        return $client;
    }
}
