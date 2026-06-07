<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Exception;

final class MixedRowShapeException extends GoogleSheetsException
{
    public static function atIndex(int $index, bool $firstIsAssoc): self
    {
        $expected = $firstIsAssoc ? 'associative' : 'positional';
        $actual = $firstIsAssoc ? 'positional' : 'associative';

        return new self(sprintf(
            'append(): row at index %d is %s but the first row is %s. All rows in a single append() call must share the same shape.',
            $index,
            $actual,
            $expected,
        ));
    }
}
