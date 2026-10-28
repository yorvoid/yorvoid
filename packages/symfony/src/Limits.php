<?php

declare(strict_types=1);

namespace YorvoID\Symfony;

use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Throttle\Limit;

/**
 * Limits as configuration writes them: "10/15m" is 10 attempts per 15 minutes.
 *
 * @internal
 */
final class Limits
{
    public const string FORMAT = '/^(?<attempts>[1-9][0-9]{0,5})\/(?<per>[1-9][0-9]{0,9}[smhd])$/D';

    public static function parse(string $limit): Limit
    {
        if (1 !== preg_match(self::FORMAT, $limit, $match)) {
            throw new IntegratorError(\sprintf('"%s" is not a limit.', $limit), 'Write attempts and a period, e.g. "10/15m" for 10 attempts per 15 minutes.', 'concepts/throttling.md#limits');
        }

        return new Limit((int) $match['attempts'], Duration::parse($match['per']));
    }
}
