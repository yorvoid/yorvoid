<?php

declare(strict_types=1);

namespace YorvoID\Core\Key;

/**
 * @api
 */
enum KeyStatus: string
{
    /** Signs and verifies; a key ring has exactly one */
    case Active = 'active';

    /** Only verifies: a new key not signing yet, or an old one whose signatures are still checked */
    case VerifyOnly = 'verify-only';
}
