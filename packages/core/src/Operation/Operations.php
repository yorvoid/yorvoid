<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Remedy;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\LogEntry;
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
     *
     * @throws IntegratorError When two handlers run one command, an OfflineDecider and the operations that
     *                         accept Permit-offline don't go together, or a policy element refuses an operation,
     *                         e.g. one whose ability isn't declared
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
        if ($authorization instanceof Evaluator) {
            $authorization->checkCatalogue($catalogue);
        }

        $accepting = array_keys(array_filter($catalogue->all(), static fn (OperationDescription $operation): bool => $operation->acceptsOffline));
        $decidesOffline = $authorization instanceof Evaluator && $authorization->decidesOffline();

        // Offline is decided when YorvoID is built, never switched on by accident (PLT-004).
        if ($decidesOffline && [] === $accepting) {
            throw new IntegratorError('An OfflineDecider is registered, but no operation accepts Permit-offline.', 'Declare #[Operation(acceptsOffline: true)] on the operations that may run while a store is unreachable, or don\'t register the decider.', 'concepts/authorization.md#offline');
        }

        if (!$decidesOffline && [] !== $accepting) {
            throw new IntegratorError(\sprintf('"%s" accepts Permit-offline, but nothing decides offline.', $accepting[0]), 'Give the evaluator an OfflineDecider, or take acceptsOffline off the operation.', 'concepts/authorization.md#offline');
        }
    }

    /**
     * @template T
     *
     * @param Command<T> $command
     *
     * @return T
     *
     * @throws NotPermitted When the decision isn't Permit, or Permit-offline for an operation that accepts it; the
     *                      handler's own exceptions otherwise
     */
    public function run(Command $command, Actor $actor, Origin $origin): mixed
    {
        $operation = $this->catalogue->forCommand($command::class);
        $handler = $this->handlers[$command::class] ?? throw new IntegratorError(\sprintf('Nothing runs the operation "%s".', $operation->name), 'Register the service that handles it as an OperationHandler.', 'concepts/operations-and-events.md#operations');
        $decision = $this->authorization->decide($operation, $command, $actor, $origin);
        // Permit-offline runs only an operation that accepts it (P1′); nothing else but a Permit runs.
        $offline = match ($decision->verdict) {
            Verdict::Permit => false,
            Verdict::PermitOffline => $operation->acceptsOffline ? true : null,
            Verdict::Deny, Verdict::Indeterminate => null,
        };

        if (null === $offline || null === $decision->basis) {
            // Viewing as someone else, the viewer tried it.
            $this->log->citing($decision, fn (): LogEntry => $this->log->record($command->realm, $operation->name, Outcome::Denied, $origin, $actor instanceof ViewingAs ? $actor->viewer->principalId : $actor->principalId, details: array_filter([
                'viewing_as' => $actor instanceof ViewingAs && null !== $actor->target->principalId ? (string) $actor->target->principalId : [],
                'verdict' => $decision->verdict->value,
                'reason' => $decision->reason,
                'indeterminate' => array_map(static fn (IndeterminateReason $reason): string => $reason->value, $decision->indeterminate),
                'remedies' => array_map(static fn (Remedy $remedy): string => $remedy->name(), $decision->remedies),
            ], static fn (string|array $value): bool => [] !== $value)));

            throw new NotPermitted($operation->name, $decision);
        }

        if ($actor instanceof ViewingAs) {
            throw new IntegratorError(\sprintf('The authorization permitted "%s" to a view as someone else.', $operation->name), 'A view-as is read-only: decide with the Evaluator, or refuse every operation to a ViewingAs actor.', 'concepts/authorization.md#view-as');
        }

        $basis = $decision->basis;
        // Only here: Permit's constructor is private to everything else.
        $issue = \Closure::bind(static fn (string $name, Actor $actor, AuthorizedBy $basis, string $id, Command $command, bool $offline): Permit => new Permit($name, $actor, $basis, $id, $command, offline: $offline), null, Permit::class);

        return $this->log->citing($decision, function () use ($command, $actor, $origin, $operation, $handler, $issue, $basis, $decision, $offline): mixed {
            if ($offline) {
                $this->log->record($command->realm, 'yorvoid.decision.offline', Outcome::Success, $origin, $actor->principalId, 'operation', $operation->name, ['reason' => $decision->reason]);
            }

            return $handler($command, $issue($operation->name, $actor, $basis, $decision->id ?? (string) $this->ids->next(), $command, $offline), $origin);
        });
    }
}
