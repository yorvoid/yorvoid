<?php

declare(strict_types=1);

namespace YorvoID\Core\Log;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use YorvoID\Core\Log\Event\AuditFailed;

/**
 * Raises the alert for an audit record that couldn't be written (AUD-006): a critical log entry, then
 * the event. Neither needs the database, and a listener that fails is reported, never thrown: what the
 * failure led to, refusing or going on, stands.
 *
 * @internal
 */
final readonly class AuditAlarm
{
    public function __construct(
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
    ) {
    }

    public function raise(AuditFailed $failure): void
    {
        $this->logger->critical('YorvoID couldn\'t write {records} audit record(s) to the {log}{refused}: {message}', [
            'log' => str_replace('_', ' ', $failure->log),
            'records' => $failure->records,
            'refused' => $failure->refused ? ', and refused what they were for' : '',
            'realm' => $failure->realm?->name,
            'message' => $failure->error->getMessage(),
            'exception' => $failure->error,
        ]);

        try {
            $this->events->dispatch($failure);
        } catch (\Throwable $e) {
            $this->logger->error('A listener of YorvoID\'s audit alert failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
