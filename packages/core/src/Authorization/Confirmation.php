<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * Whether a decision record confirms that an attribute had a value.
 *
 * @api
 */
enum Confirmation: string
{
    /** The record holds that value, or its MAC */
    case Matches = 'matches';

    /** The record holds another */
    case Differs = 'differs';

    /** The record holds nothing for the attribute: not read, or not recorded */
    case NotRecorded = 'not_recorded';

    /** It was read, but not recorded: the realm had no MAC key */
    case Withheld = 'withheld';

    /** Its MAC's key is gone, so it can't be confirmed any more */
    case UnknownKey = 'unknown_key';
}
