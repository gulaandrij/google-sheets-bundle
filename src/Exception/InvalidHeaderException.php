<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Exception;

final class InvalidHeaderException extends GoogleSheetsException
{
    public static function nonScalarCell(int $index, string $actualType): self
    {
        return new self(sprintf(
            'Header cell at index %d is of type "%s"; only scalar (and null) cells are supported. Use readRaw() if your header contains structured values.',
            $index,
            $actualType,
        ));
    }
}
