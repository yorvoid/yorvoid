<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * What evaluating a condition, or checking an actor's authority, gives (06 §3).
 *
 * @api
 */
enum TruthValue: string
{
    case Holds = 'holds';

    case Fails = 'fails';

    /** It couldn't be established: never treated as holding (P1) */
    case Unknown = 'unknown';
}
