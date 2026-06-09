<?php

declare(strict_types=1);

namespace Gulaandrij\GoogleSheetsBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a successful write operation on a sheet (append/update/clear
 * + tab CRUD). Subscribers can use this to invalidate downstream caches, kick
 * off sync workflows, or emit audit logs. Read events are deliberately not
 * dispatched — they would fire on every row in streaming reads.
 *
 * Listen via:
 *
 *     #[AsEventListener(event: SheetsWriteEvent::class)]
 *     public function onSheetsWrite(SheetsWriteEvent $event): void { ... }
 */
final class SheetsWriteEvent extends Event
{
    public const OP_APPEND = 'append';
    public const OP_UPDATE = 'update';
    public const OP_CLEAR = 'clear';
    public const OP_ADD_SHEET = 'addSheet';
    public const OP_DELETE_SHEET = 'deleteSheet';

    /**
     * @param self::OP_*  $operation one of the OP_ constants
     * @param string|null $sheetName tab the operation touched — null for tab-CRUD
     *                               operations that don't target an existing tab
     * @param string|null $range     A1 range when applicable (update/clear)
     * @param int|null    $rowCount  number of rows written when known (append/update)
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $spreadsheetId,
        public readonly ?string $sheetName = null,
        public readonly ?string $range = null,
        public readonly ?int $rowCount = null,
    ) {
    }
}
