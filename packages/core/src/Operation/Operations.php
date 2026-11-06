<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;

/**
 * The way into every operation (D-38): it asks the {@see Authorization} whether the actor may run it,
 * records a refusal in the security log, and on Permit calls the operation's handler with a
 * {@see Permit}. Handlers take nothing else as their authority, so nothing reaches them around it.
 *
 * ```php
 * $operations->run(new SuspendPrincipal($realm, $principalId), $session, $origin);
 * ```
 *
 * @api
 */
final readonly class Operations
{
    /** @var array<class-string, \Closure> */
    private array $handlers;

    /**
     * @param iterable<OperationHandler> $handlers
     */
    public function __construct(
        private OperationCatalogue $catalogue,
        private Authorization $authorization,
        private SecurityLog $log,
        iterable $handlers,
        private IdGenerator $ids = new UuidV7Generator(),
    ) {
        $map = [];

        foreach ($handlers as $handler) {
            foreach ($handler->operations() as $command => $method) {
                if (isset($map[$command])) {
                    throw new IntegratorError(\sprintf('Two handlers run %s.', $command), 'Give each command one handler.', 'concepts/operations-and-events.md#operations');
                }

                $map[$command] = $method;
            }
        }

        $this->handlers = $map;
    }

    /**
     * @template T
     *
     * @param Command<T> $command
     *
     * @return T
     *
     * @throws NotPermitted When the decision isn't Permit; the handler's own exceptions otherwise
     */
    public function run(Command $command, Actor $actor, Origin $origin): mixed
    {
        $operation = $this->catalogue->forCommand($command::class);
        $handler = $this->handlers[$command::class] ?? throw new IntegratorError(\sprintf('Nothing runs the operation "%s".', $operation->name), 'Register the service that handles it as an OperationHandler.', 'concepts/operations-and-events.md#operations');
        $decision = $this->authorization->decide($operation, $command, $actor, $origin);

        if (!$decision->permits() || null === $decision->basis) {
            $this->log->record($command->realm, $operation->name, Outcome::Denied, $origin, $actor->principalId, details: [
                'verdict' => $decision->verdict->value,
                'reason' => $decision->reason,
            ]);

            throw new NotPermitted($operation->name, $decision);
        }

        // Only here: Permit's constructor is private to everything else.
        $issue = \Closure::bind(static fn (string $name, Actor $actor, AuthorizedBy $basis, string $id, Command $command): Permit => new Permit($name, $actor, $basis, $id, $command), null, Permit::class);

        return $handler($command, $issue($operation->name, $actor, $decision->basis, (string) $this->ids->next(), $command), $origin);
    }
}
