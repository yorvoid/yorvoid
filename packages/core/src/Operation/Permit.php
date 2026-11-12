<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Proof that one actor may run one operation on one command, once. Only {@see Operations::run()} issues
 * permits, after a Permit decision; a handler takes one and calls use() with its command before it
 * changes anything, so a handler can't be called around the decision point (D-38).
 *
 * What an operation causes, e.g. a recovery ending every session, runs on a consequence() of the
 * handler's permit: decided as part of the operation, not again. Only what the operation declares it
 * causes (#[Operation(causes: [...])]), so a listener handed the permit can't run anything else with it,
 * and each as many times as it's declared: once, unless it's named twice.
 *
 * A permit is about one principal at most: its command's (ConcernsPrincipal), or the one its handler
 * names with about() once it knows it, e.g. the principal it created or whose token it took. A
 * consequence that concerns a principal must be about that one, so a permit handed to a listener runs
 * nothing about anyone else, nor about anyone before its handler said who (M2-13).
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

    /** Whom it's about, once known */
    private ?PrincipalId $principal;

    /** @var array<string, int> How many times each consequence ran, by operation */
    private array $caused = [];

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
        $this->principal = $command instanceof ConcernsPrincipal ? $command->principal() : null;
    }

    /**
     * Says whom the operation is about, once its handler knows: before it causes anything about them, and
     * before it hands the permit to anyone, e.g. in an event.
     *
     * @throws IntegratorError When the permit is about another principal already
     */
    public function about(PrincipalId $principal): void
    {
        if (null !== $this->principal && !$this->principal->equals($principal)) {
            throw new IntegratorError(\sprintf('The permit for "%s" is about another principal already.', $this->operation), 'An operation is about one principal: its consequences are about that one too.', 'concepts/operations-and-events.md#permits');
        }

        $this->principal = $principal;
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
        $declared = \count(array_keys(Operation::of($this->command::class)->causes, $consequence, true));

        if (0 === $declared) {
            throw new IntegratorError(\sprintf('"%s" doesn\'t say it causes "%s".', $this->operation, $consequence), 'Name what an operation runs as its consequences in #[Operation(causes: [...])]; run anything else through Operations::run().', 'concepts/operations-and-events.md#permits');
        }

        $caused = $this->caused[$consequence] ?? 0;

        if ($caused >= $declared) {
            throw new IntegratorError(\sprintf('This permit for "%s" ran its consequence "%s" already: as many times as it says it causes it.', $this->operation, $consequence), 'Each consequence runs as many times as #[Operation(causes: [...])] names it; whatever ran it first used the permit for it.', 'concepts/operations-and-events.md#permits');
        }

        if ($command instanceof ConcernsPrincipal) {
            if (null === $this->principal) {
                throw new IntegratorError(\sprintf('A consequence of "%s" is about a principal, but "%s" isn\'t about a principal yet.', $this->operation, $this->operation), 'Its handler says whom it\'s about with about(), before it causes anything about them.', 'concepts/operations-and-events.md#permits');
            }

            if (!$command->principal()->equals($this->principal)) {
                throw new IntegratorError(\sprintf('A consequence of "%s" is about another principal.', $this->operation), 'An operation\'s consequences are about its own principal only: run anything about another through Operations::run().', 'concepts/operations-and-events.md#permits');
            }
        }

        $this->caused[$consequence] = $caused + 1;

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
