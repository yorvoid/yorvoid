<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage;

use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use YorvoID\Core\Exception\YorvoIDException;

/**
 * A store couldn't be reached: the connection was refused or lost, it timed out, or a failover is in
 * progress (XP-02). Not an error in the data, nor data that's missing: a decision that needed the store
 * is Indeterminate with the reason store_unreachable. A store of your own throws it in those cases.
 *
 * @api
 */
final class StoreUnreachable extends \RuntimeException implements YorvoIDException
{
    /**
     * SQLSTATEs that mean the database can't be reached now, whatever the driver made of them: a
     * statement cancelled by its timeout (57014), and a server starting, failing over or shutting down
     * (57P01…57P03). Class 08 is a connection exception of any kind.
     */
    private const array UNREACHABLE = ['57014', '57P01', '57P02', '57P03'];

    /**
     * What an exception from a store means, if it's that the store couldn't be reached: this, or one of
     * DBAL's for a refused or lost connection or a timeout. Waiting for a lock is contention, not this.
     */
    public static function of(\Throwable $exception): ?self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        $state = $exception instanceof DriverException ? $exception->getSQLState() : null;

        if ($exception instanceof ConnectionException || (null !== $state && (str_starts_with($state, '08') || \in_array($state, self::UNREACHABLE, true)))) {
            return new self(\sprintf('The database couldn\'t be reached: %s', $exception->getMessage()), previous: $exception);
        }

        return null;
    }
}
