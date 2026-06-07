<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Exception;

final class MissingSheetNameException extends GoogleSheetsException
{
    public static function create(): self
    {
        return new self(
            'No sheet name provided. Either pass $sheetName explicitly or set '
            .'`sheet` on the spreadsheet entry in google_sheets.spreadsheets.<name>.'
        );
    }
}
