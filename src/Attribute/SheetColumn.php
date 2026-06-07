<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Attribute;

use Attribute;

/**
 * Maps a DTO property to a spreadsheet header column with a different name.
 *
 * Place on a property to tell `SheetsService::readEntities()` (and the
 * companion `SheetsRowDenormalizer`) which column in the sheet feeds this
 * property. Without it, the property name is used as-is.
 *
 *     #[SheetColumn('Record ID - Contact')]
 *     public string $contactId;
 *
 *     #[SheetColumn('First Name')]
 *     public ?string $firstName = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class SheetColumn
{
    public function __construct(public readonly string $name)
    {
    }
}
