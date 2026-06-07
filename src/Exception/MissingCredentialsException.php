<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Exception;

final class MissingCredentialsException extends GoogleSheetsException
{
    public const string DEFAULT_MESSAGE = 'No Google credentials configured. Set at least one of '
        .'google_sheets.auth.api_key, google_sheets.auth.client_id/client_secret, '
        .'or google_sheets.auth.auth_config.';

    public static function create(): self
    {
        return new self(self::DEFAULT_MESSAGE);
    }
}
