<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;

/**
 * Proof that one actor may run one operation on one command, once. Only {@see Operations::run()} issues
 * permits, after a Permit decision; a handler takes one and calls use() with its command before it
 * changes anything, so a handler can't be called around the decision point (D-38).
 *
 * What an operation causes, e.g. a recovery ending every session, runs on a consequence() of the
 * handler's permit: decided as part of the operation, not again. Only what the operation declares it
 * causes (#[Operation(causes: [...])]), so a listener handed the permit can't run anything else with it.
 *
 * A permit issued on Permit-offline, while a store was unreachable, says so ($offline), and so do its
 * consequences: only operations that declare acceptsOffline get one (P1′).
 *
 * @api
 */
final class Permit
{
    public Realm $realm {
        get => $this->command->realm;
    }

    private bool $used = false;

    /**
     * @param Command<mixed> $command
     */
    private function __construct(
        public readonly string $operation,
        public readonly Actor $actor,
        public readonly AuthorizedBy $basis,
        public readonly string $decisionId,
        private readonly Command $command,
        public readonly ?self $parent = null,
        public readonly bool $offline = false,
    ) {
    }

    /**
     * Takes the permit for its command, before the handler changes anything.
     *
     * @param Command<mixed> $command
     *
     * @throws IntegratorError When it's for another command, or was used already
     */
    public function use(Command $command): void
    {
        if ($command !== $this->command) {
            throw new IntegratorError(\sprintf('The permit for "%s" was given with another command.', $this->operation), 'Run the operation through Operations::run(), which gives its handler a permit for that command.', 'concepts/operations-and-events.md#permits');
        }

        if ($this->used) {
            throw new IntegratorError(\sprintf('The permit for "%s" was used already.', $this->operation), 'Each permit runs one operation once: run each operation through Operations::run().', 'concepts/operations-and-events.md#permits');
        }

        $this->used = true;
    }

    /**
     * A permit for an operation this one causes, in the same realm, recorded under this permit's decision.
     *
     * @template T
     *
     * @param Command<T> $command
     * @param Actor|null $actingAs Who the consequence acts as, for authentication only: the principal it
     *                             authenticated, e.g. the invitee accepting; by default this permit's actor
     *
     * @throws IntegratorError When this permit wasn't used yet, its operation doesn't say it causes the
     *                         command's, the command is in another realm, or another actor is named outside
     *                         authentication
     */
    public function consequence(Command $command, ?Actor $actingAs = null): self
    {
        if (!$this->used) {
            throw new IntegratorError(\sprintf('A consequence was asked of the permit for "%s" before it was used.', $this->operation), 'Call use() with the command first: only an operation that runs has consequences.', 'concepts/operations-and-events.md#permits');
        }

        if (!$command->realm->equals($this->realm)) {
            throw new IntegratorError(\sprintf('A consequence of "%s" is in another realm.', $this->operation), 'An operation\'s consequences stay in its realm.', 'concepts/operations-and-events.md#permits');
        }

        if (null !== $actingAs && AuthorizedBy::Authentication !== $this->basis) {
            throw new IntegratorError(\sprintf('A consequence of "%s" names who it acts as, but "%s" isn\'t authentication.', $this->operation, $this->operation), 'Only authentication says who someone is: other operations\' consequences act as their actor.', 'concepts/operations-and-events.md#permits');
        }

        $consequence = Operation::of($command::class)->name;

        if (!\in_array($consequence, Operation::of($this->command::class)->causes, true)) {
            throw new IntegratorError(\sprintf('"%s" doesn\'t say it causes "%s".', $this->operation, $consequence), 'Name what an operation runs as its consequences in #[Operation(causes: [...])]; run anything else through Operations::run().', 'concepts/operations-and-events.md#permits');
        }

        return new self($consequence, $actingAs ?? $this->actor, AuthorizedBy::Consequence, $this->decisionId, $command, $this, $this->offline);
    }

    /**
     * Runs an operation this one causes: its handler, with a consequence() of this permit.
     *
     * ```php
     * $permit->cause($this->sessions->endAll(...), new EndSessions($realm, $principalId, $reason), $origin);
     * ```
     *
     * @template T
     * @template C of Command<T>
     *
     * @param \Closure(C, self, Origin): T $handler
     * @param C                            $command
     *
     * @return T
     */
    public function cause(\Closure $handler, Command $command, Origin $origin, ?Actor $actingAs = null): mixed
    {
        return $handler($command, $this->consequence($command, $actingAs), $origin);
    }
}
