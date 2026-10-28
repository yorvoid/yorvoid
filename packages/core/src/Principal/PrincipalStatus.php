<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

/**
 * A principal's status, and the changes it allows (IDN-004):
 *
 *     invited ──→ active ⇄ suspended
 *        │          │          │
 *        └──────────┴──────────┴──→ deleted
 *
 * @api
 */
enum PrincipalStatus: string
{
    /** Invited, not yet active */
    case Invited = 'invited';

    case Active = 'active';

    /** Can't sign in or act; can be activated again */
    case Suspended = 'suspended';

    /** Deleted: a tombstone that keeps the security log's references valid, and nothing else */
    case Deleted = 'deleted';

    public function canBecome(self $status): bool
    {
        return match ($this) {
            self::Invited => self::Active === $status || self::Deleted === $status,
            self::Active => self::Suspended === $status || self::Deleted === $status,
            self::Suspended => self::Active === $status || self::Deleted === $status,
            self::Deleted => false,
        };
    }
}
