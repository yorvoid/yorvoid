<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * The row of the precedence that decided (06 §3), or Permit-offline (P1′).
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's explainer uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
enum Precedence: string
{
    case ForbidHolds = 'forbid_holds';
    case ForbidUnknown = 'forbid_unknown';
    case AuthorityInvalid = 'authority_invalid';
    case AuthorityUnknown = 'authority_unknown';
    case PermitHolds = 'permit_holds';
    case PermitUnknown = 'permit_unknown';
    case NoPermit = 'no_permit';
    case Offline = 'offline';

    /**
     * Its row, 1 to 7; null for Permit-offline, which overrides an Indeterminate.
     */
    public function row(): ?int
    {
        return match ($this) {
            self::ForbidHolds => 1,
            self::ForbidUnknown => 2,
            self::AuthorityInvalid => 3,
            self::AuthorityUnknown => 4,
            self::PermitHolds => 5,
            self::PermitUnknown => 6,
            self::NoPermit => 7,
            self::Offline => null,
        };
    }
}
