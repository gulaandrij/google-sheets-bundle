<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Exception;

final class DuplicateHeaderException extends GoogleSheetsException
{
    public static function forName(string $name): self
    {
        return new self(sprintf(
            'Duplicate header value "%s" in the first row. readAssoc() requires unique header values to avoid silent column overwrites.',
            $name,
        ));
    }
}
