<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\DecisionLog;

/**
 * Which permits of abilities the decision log records: they're the volume, since every page asks.
 * Refusals, Indeterminate and every operation's decision are always recorded.
 *
 * @api
 */
enum PermitRecording: string
{
    /** None, but those of the abilities that always record theirs */
    case None = 'none';

    /** Those of a share of the requests, decided per correlation ID: a request is recorded whole or not at all */
    case Sampled = 'sampled';

    case All = 'all';
}
