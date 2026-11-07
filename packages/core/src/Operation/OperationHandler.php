<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * A service that runs operations: it says which command each of its methods handles. Each method takes
 * the command, a {@see Permit} and the origin, and calls `$permit->use($command)` before it changes
 * anything.
 *
 * @api
 */
interface OperationHandler
{
    /**
     * @return array<class-string<Command<mixed>>, \Closure> By command class, e.g. [SuspendPrincipal::class => $this->suspend(...)]
     */
    public function operations(): array;
}
