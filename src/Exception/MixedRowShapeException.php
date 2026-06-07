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

    /**
     * @param list<string> $extra
     * @param list<string> $missing
     */
    public static function divergentAssocKeys(int $index, array $extra, array $missing): self
    {
        $parts = [];
        if ([] !== $extra) {
            $parts[] = sprintf('extra keys [%s]', implode(', ', $extra));
        }
        if ([] !== $missing) {
            $parts[] = sprintf('missing keys [%s]', implode(', ', $missing));
        }

        return new self(sprintf(
            'append(): associative row at index %d has a different key set from the first row (%s). All rows must share the same keys so values are not silently dropped or padded.',
            $index,
            implode(', ', $parts),
        ));
    }
}
