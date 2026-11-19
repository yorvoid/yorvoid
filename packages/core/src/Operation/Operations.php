<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Authorization\RequiresForbids;
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

    /** @var \WeakMap<Decision, true> The decisions it issued a permit on: each once */
    private \WeakMap $issued;

    /**
     * @param iterable<OperationHandler> $handlers
     *
     * @throws IntegratorError When two handlers run one command, an OfflineDecider and the operations that
     *                         accept Permit-offline don't go together, a policy element refuses an operation,
     *                         e.g. one whose ability isn't declared, or a handler's forbids aren't in the policy
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
            // The changes that only the policy's forbids keep safe, e.g. giving roles, need them whatever permits
            // them (M2-13).
            if ($handler instanceof RequiresForbids) {
                $authorization->requireForbids(\sprintf('The handler %s', $handler::class), $handler);
            }

            foreach ($handler->operations() as $command => $method) {
                if (isset($map[$command])) {
                    throw new IntegratorError(\sprintf('Two handlers run %s.', $command), 'Give each command one handler.', 'concepts/operations-and-events.md#operations');
                }

                $map[$command] = $method;
            }
        }

        $this->handlers = $map;
        $this->issued = new \WeakMap();

        $authorization->checkCatalogue($catalogue);

        $accepting = array_keys(array_filter($catalogue->all(), static fn (OperationDescription $operation): bool => $operation->acceptsOffline));
        $decidesOffline = $authorization->decidesOffline();

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
        // A principal YorvoID verified acts only in the consequences of what verified it (D-45, L-4).
        if ($actor instanceof ActingPrincipal) {
            throw new IntegratorError(\sprintf('"%s" was run as an ActingPrincipal.', $command::class), 'An ActingPrincipal acts only in a permit\'s consequences: run the operation with the signed-in Session, or as the Operator.', 'concepts/operations-and-events.md#actors');
        }

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
            // A refusal changes nothing: its decision record is its trace, written outside the caller's
            // transaction and without the realm's lock (D-42). Viewing as someone else, it names the viewer.
            throw new NotPermitted($operation->name, $decision);
        }

        if ($actor instanceof ViewingAs) {
            throw new IntegratorError(\sprintf('The authorization permitted "%s" to a view as someone else.', $operation->name), 'A view-as is read-only: decide with the Evaluator, or refuse every operation to a ViewingAs actor.', 'concepts/authorization.md#view-as');
        }

        $this->accept($decision, $command, $actor, $operation->name);
        $basis = $decision->basis;
        // Only here: Permit's constructor is private to everything else.
        $issue = \Closure::bind(static fn (string $name, Actor $actor, AuthorizedBy $basis, Decision $decision, string $id, Command $command, bool $offline, \Closure $consequences): Permit => new Permit($name, $actor, $basis, $id, $command, offline: $offline, decision: $decision, consequences: $consequences), null, Permit::class);
        // Its consequences are decided by the same path, from the same origin (D-50).
        $consequences = function (Decision $parent, Command $caused, Actor $by) use ($origin): Decision {
            $operation = $this->describe($caused);
            $decision = $this->authorization->decideConsequence($parent, $operation, $caused, $by, $origin);

            $permits = match ($decision->verdict) {
                Verdict::Permit, Verdict::PermitOffline => true,
                Verdict::Deny, Verdict::Indeterminate => false,
            };

            if ($permits) {
                $this->accept($decision, $caused, $by, $operation->name);
            }

            return $decision;
        };

        return $this->log->citing($decision, function () use ($command, $actor, $origin, $operation, $handler, $issue, $basis, $decision, $offline, $consequences): mixed {
            if ($offline) {
                $this->log->record($command->realm, 'yorvoid.decision.offline', Outcome::Success, $origin, $actor->principalId, 'operation', $operation->name, ['reason' => $decision->reason]);
            }

            return $handler($command, $issue($operation->name, $actor, $basis, $decision, $decision->id ?? (string) $this->ids->next(), $command, $offline, $consequences), $origin);
        });
    }

    /**
     * A decision that permits is issued on once, and only for the command and the actor it was made
     * for (D-50): an Authorization that gives back a permit it was given for another request, e.g. one
     * that caches decisions, would run one actor's operation on another's permit.
     *
     * @param Command<mixed> $command
     *
     * @throws IntegratorError When it was made for another request, or issued on already
     */
    private function accept(Decision $decision, Command $command, Actor $actor, string $name): void
    {
        if (!$decision->isFor($command, $actor)) {
            throw new IntegratorError(\sprintf('The authorization permitted "%s" with a decision made for another command or actor.', $name), 'Decide every request: never give back a decision made for another one, e.g. from a cache.', 'concepts/operations-and-events.md#the-decision-point');
        }

        if (true === ($this->issued[$decision] ?? null)) {
            throw new IntegratorError(\sprintf('The authorization permitted "%s" with a decision a permit was issued on already.', $name), 'Decide every request: a decision permits once.', 'concepts/operations-and-events.md#the-decision-point');
        }

        $this->issued[$decision] = true;
    }

    /**
     * A consequence's operation: from the catalogue, or from its command, since a consequence-only
     * operation may have no handler of its own here.
     *
     * @param Command<mixed> $command
     */
    private function describe(Command $command): OperationDescription
    {
        return $this->catalogue->has(Operation::of($command::class)->name) ? $this->catalogue->forCommand($command::class) : OperationCatalogue::describe($command::class);
    }
}
