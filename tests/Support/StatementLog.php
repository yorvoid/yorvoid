<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Records the SQL a connection runs, with DBAL's logging middleware: to compare the work two paths do.
 */
final class StatementLog extends AbstractLogger
{
    /** @var list<string> */
    private array $statements = [];

    /**
     * The statements run since the last call.
     *
     * @return list<string>
     */
    public function take(): array
    {
        $statements = $this->statements;
        $this->statements = [];

        return $statements;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $sql = $context['sql'] ?? null;

        if (\is_string($sql)) {
            $this->statements[] = $sql;
        }
    }
}
