<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Tests\Fixtures;

use Gulaandrij\GoogleSheetsBundle\Attribute\SheetColumn;

final class PersonDto
{
    #[SheetColumn('Record ID - Contact')]
    public ?string $contactId = null;

    #[SheetColumn('First Name')]
    public ?string $firstName = null;

    #[SheetColumn('Email')]
    public ?string $email = null;
}
