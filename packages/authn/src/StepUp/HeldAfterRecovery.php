<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp;

use YorvoID\Core\Exception\YorvoIDException;

/**
 * The account was recovered recently, and the operation waits out the cool-down (ATN-029): if it wasn't
 * its owner who recovered it, the owner has until then to notice and take it back, before their other
 * ways in can be removed. Stepping up doesn't help; tell the person when it's possible again.
 *
 * @api
 */
final class HeldAfterRecovery extends \RuntimeException implements YorvoIDException
{
    public function __construct(
        public readonly \DateTimeImmutable $until,
    ) {
        parent::__construct(\sprintf('Your account was recovered recently. For your security, this is possible again from %s.', $until->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i \U\T\C')));
    }
}
