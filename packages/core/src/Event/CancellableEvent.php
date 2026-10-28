<?php

declare(strict_types=1);

namespace YorvoID\Core\Event;

use Psr\EventDispatcher\StoppableEventInterface;

/**
 * An "-ing" event, dispatched inside the operation's transaction before it takes effect, which a listener
 * can cancel (PLT-042): e.g. refuse a sign-in during maintenance, or refuse linking an account from
 * outside the organisation. Cancelling stops the other listeners and rolls the operation back.
 *
 * The reason is for the person who tried; the application decides how to show it.
 *
 * @api
 */
abstract class CancellableEvent implements StoppableEventInterface
{
    private ?string $reason = null;

    public function cancel(string $reason): void
    {
        $this->reason = $reason;
    }

    public function isCancelled(): bool
    {
        return null !== $this->reason;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    final public function isPropagationStopped(): bool
    {
        return $this->isCancelled();
    }
}
