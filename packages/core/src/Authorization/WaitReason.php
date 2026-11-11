<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * Why a WaitRemedy waits, so the person can be told. Closed: a wait of the application's own, for a
 * reason of its own, is a remedy of its own, with its own message.
 *
 * @api
 */
enum WaitReason: string
{
    /** No reason given: the person is told only when they can try again */
    case Unspecified = 'unspecified';

    /** The account was recovered recently, and sensitive changes wait out the cool-down (ATN-029) */
    case Recovered = 'recovered';
}
