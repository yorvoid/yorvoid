<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger for tests: keeps every entry, level, message and context, in order.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $logs = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        \assert(\is_string($level));
        $this->logs[] = [$level, (string) $message, $context];
    }

    /**
     * The entries of one level.
     *
     * @return list<array{string, string, array<mixed>}>
     */
    public function of(string $level): array
    {
        return array_values(array_filter($this->logs, static fn (array $entry): bool => $level === $entry[0]));
    }
}
