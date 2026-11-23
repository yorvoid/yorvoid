<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Event\CancellableEvent;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Id\IdGenerator;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationCancelled;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\Event\IdentifierChanged;
use YorvoID\Core\Principal\Event\IdentifierChanging;
use YorvoID\Core\Principal\Event\PrincipalCreated;
use YorvoID\Core\Principal\Event\PrincipalCreating;
use YorvoID\Core\Principal\Event\PrincipalStatusChanged;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Core\Time;

/**
 * The principal registry (IDN-001…004, IDN-016): creates principals, changes their status and
 * identifiers, and finds them. Each change is one transaction with its security log entry and its
 * "-ing" event; the "-ed" event follows the commit. Changes run through Operations::run(), which gives
 * each a permit.
 *
 * The security log records which kind of identifier changed, never its value: the log can't forget, and
 * an email address is personal data that must go when its owner does.
 *
 * @api
 */
final readonly class Principals implements OperationHandler
{
    /** @var list<GuardRule> */
    private array $guardRules;

    /**
     * @param iterable<GuardRule> $guardRules
     */
    public function __construct(
        private PrincipalStore $store,
        private IdentifierStore $identifiers,
        private SecurityLog $log,
        private Transactions $transactions,
        private EventDispatcherInterface $events = new NoEventListeners(),
        private IdGenerator $ids = new UuidV7Generator(),
        private ClockInterface $clock = new NativeClock(),
        iterable $guardRules = [],
    ) {
        // Keys don't matter, and two rules may come with the same one (e.g. from two generators).
        $this->guardRules = iterator_to_array($guardRules, false);
    }

    public function operations(): array
    {
        return [
            CreatePrincipal::class => $this->create(...),
            ActivatePrincipal::class => $this->activate(...),
            SuspendPrincipal::class => $this->suspend(...),
            DeletePrincipal::class => $this->delete(...),
            ChangeAgentOwner::class => $this->changeOwner(...),
            SetIdentifier::class => $this->setIdentifier(...),
            RemoveIdentifier::class => $this->removeIdentifier(...),
            VerifyIdentifier::class => $this->verifyIdentifier(...),
        ];
    }

    /**
     * @throws InvalidIdentifier  When the email address or username isn't valid
     * @throws IdentifierTaken    When another principal in the realm has it
     * @throws OperationCancelled When a listener of PrincipalCreating refuses it
     * @throws InvalidOwner       When an agent has no owner, or an owner that isn't one, or another kind has one
     */
    public function create(CreatePrincipal $command, Permit $permit, Origin $origin): Principal
    {
        $permit->use($command);
        $actor = $permit->actor->principalId;

        return $this->transactions->run(function () use ($command, $permit, $origin, $actor): Principal {
            // An active principal ends a setup that waits for the realm's first (SetupCodes::claimWith()); an
            // agent's owner can't be deleted meanwhile, which takes the realm's lock too.
            if (PrincipalStatus::Active === $command->status || PrincipalKind::Agent === $command->kind) {
                $this->transactions->lockRealm($command->realm);
            }

            $owner = $this->owner($command);
            $now = Time::stored($this->clock->now());
            $principal = new Principal(new PrincipalId($this->ids->next()), $command->realm, $command->kind, $command->status, $now, $now, $now, owner: $owner, profile: $command->profile);
            // Before the events that carry the permit: what follows from it is about this principal only.
            $permit->about($principal->id);
            $identifiers = [];

            if (null !== $command->email) {
                $identifiers[] = new Identifier($principal->realm, $principal->id, IdentifierKind::Email, $command->email, $now, $command->emailVerified ? $now : null);
            }

            if (null !== $command->username) {
                $identifiers[] = new Identifier($principal->realm, $principal->id, IdentifierKind::Username, $command->username, $now);
            }

            $this->refuseIfCancelled('yorvoid.principal.create', new PrincipalCreating($principal, $identifiers, $origin, $actor, $permit));
            $this->store->add($principal);

            foreach ($identifiers as $identifier) {
                $this->add($identifier);
            }

            $this->log->record($principal->realm, 'yorvoid.principal.create', Outcome::Success, $origin, $actor, 'principal', (string) $principal->id, [
                'kind' => $principal->kind->value,
                'status' => $principal->status->value,
                ...(null === $owner ? [] : ['owner' => (string) $owner]),
                'identifiers' => array_map(static fn (Identifier $identifier): string => $identifier->kind->value, $identifiers),
            ]);

            $this->transactions->afterCommit(function () use ($principal, $identifiers, $origin, $actor, $permit): void {
                $this->events->dispatch(new PrincipalCreated($principal, $identifiers, $origin, $actor, $permit));
            });

            return $principal;
        });
    }

    /**
     * Activates an invited principal, or a suspended one again.
     *
     * @throws UnknownPrincipal|InvalidStatusChange|ConcurrentChange|OperationCancelled|GuardRuleViolated
     */
    public function activate(ActivatePrincipal $command, Permit $permit, Origin $origin): Principal
    {
        $permit->use($command);

        return $this->changeStatus('yorvoid.principal.activate', $command->realm, $command->principalId, PrincipalStatus::Active, $origin, $permit);
    }

    /**
     * Suspends an active principal: it can't sign in or act until it's activated again.
     *
     * @throws UnknownPrincipal|InvalidStatusChange|ConcurrentChange|OperationCancelled|GuardRuleViolated
     */
    public function suspend(SuspendPrincipal $command, Permit $permit, Origin $origin): Principal
    {
        $permit->use($command);

        return $this->changeStatus('yorvoid.principal.suspend', $command->realm, $command->principalId, PrincipalStatus::Suspended, $origin, $permit);
    }

    /**
     * Deletes a principal: what remains is a tombstone with its ID, kind and times, so that the security
     * log's references stay valid. Its identifiers are removed, so its email address can be used again.
     *
     * @throws UnknownPrincipal|InvalidStatusChange|ConcurrentChange|OperationCancelled|GuardRuleViolated
     */
    public function delete(DeletePrincipal $command, Permit $permit, Origin $origin): Principal
    {
        $permit->use($command);

        return $this->changeStatus('yorvoid.principal.delete', $command->realm, $command->principalId, PrincipalStatus::Deleted, $origin, $permit);
    }

    /**
     * Moves an agent to another owner.
     *
     * @throws UnknownPrincipal|InvalidOwner|ConcurrentChange
     */
    public function changeOwner(ChangeAgentOwner $command, Permit $permit, Origin $origin): Principal
    {
        $permit->use($command);
        $actor = $permit->actor->principalId;

        return $this->transactions->run(function () use ($command, $origin, $actor): Principal {
            // Under the realm's lock, as deleting an owner is: neither reads what the other is changing.
            $this->transactions->lockRealm($command->realm);
            $this->store->lock($command->realm, $command->agent);
            $before = $this->living($command->realm, $command->agent);

            if (null === $before->owner) {
                throw new InvalidOwner(\sprintf('The principal %s isn\'t an agent: only an agent has an owner.', $before->id));
            }

            $this->ownerFor($command->realm, $command->owner, $before->id);
            $after = $before->withOwner($command->owner, $this->clock->now());

            if (!$this->store->changeOwner($before, $after)) {
                throw new ConcurrentChange($before);
            }

            $this->log->record($command->realm, 'yorvoid.principal.change_owner', Outcome::Success, $origin, $actor, 'principal', (string) $before->id, ['from' => (string) $before->owner, 'to' => (string) $command->owner]);

            return $after;
        });
    }

    /**
     * @throws UnknownPrincipal   When there's no such principal, or it's deleted
     * @throws InvalidIdentifier  When the value isn't valid
     * @throws IdentifierTaken    When another principal in the realm has it
     * @throws OperationCancelled When a listener of IdentifierChanging refuses it
     */
    public function setIdentifier(SetIdentifier $command, Permit $permit, Origin $origin): Identifier
    {
        $permit->use($command);

        return $this->transactions->run(function () use ($command, $permit, $origin): Identifier {
            $principal = $this->living($command->realm, $command->principalId);
            $before = $this->identifiers->of($principal->realm, $principal->id)[$command->kind->value] ?? null;
            $now = Time::stored($this->clock->now());
            $verifiedAt = $command->verified ? $now : ($before?->normalized === $command->kind->normalize($command->value) ? $before->verifiedAt : null);
            $after = new Identifier($principal->realm, $principal->id, $command->kind, $command->value, $before->createdAt ?? $now, $verifiedAt);

            $this->changeIdentifier('yorvoid.identifier.set', $principal, $before, $after, $origin, $permit, ['kind' => $command->kind->value, 'verified' => $after->isVerified()]);

            return $after;
        });
    }

    /**
     * @throws UnknownPrincipal   When there's no such principal, or it's deleted
     * @throws OperationCancelled When a listener of IdentifierChanging refuses it
     */
    public function removeIdentifier(RemoveIdentifier $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);

        $this->transactions->run(function () use ($command, $permit, $origin): void {
            $principal = $this->living($command->realm, $command->principalId);
            $before = $this->identifiers->of($principal->realm, $principal->id)[$command->kind->value] ?? null;

            if (null !== $before) {
                $this->changeIdentifier('yorvoid.identifier.remove', $principal, $before, null, $origin, $permit, ['kind' => $command->kind->value]);
            }
        });
    }

    /**
     * Marks the identifier verified, if it still has the value that was verified.
     *
     * @return Identifier|null The identifier, verified; null if it's gone or has another value now
     *
     * @throws UnknownPrincipal   When there's no such principal, or it's deleted
     * @throws OperationCancelled When a listener of IdentifierChanging refuses it
     */
    public function verifyIdentifier(VerifyIdentifier $command, Permit $permit, Origin $origin): ?Identifier
    {
        $permit->use($command);

        return $this->transactions->run(function () use ($command, $permit, $origin): ?Identifier {
            $principal = $this->living($command->realm, $command->principalId);
            $before = $this->identifiers->of($principal->realm, $principal->id)[$command->kind->value] ?? null;

            try {
                $verified = $command->kind->normalize($command->value);
            } catch (InvalidIdentifier) {
                return null;
            }

            if (null === $before || $before->normalized !== $verified) {
                return null;
            }

            if ($before->isVerified()) {
                return $before;
            }

            $after = new Identifier($principal->realm, $principal->id, $before->kind, $before->value, $before->createdAt, Time::stored($this->clock->now()));
            $this->changeIdentifier('yorvoid.identifier.verify', $principal, $before, $after, $origin, $permit, ['kind' => $before->kind->value]);

            return $after;
        });
    }

    /**
     * Locks the principal until the transaction ends, before what a change of theirs reads: their
     * changes happen one after the other (D-52). Take it at the top of the unit of work, after the
     * realm's lock if the unit takes that.
     *
     * @internal For YorvoID's packages, whose changes of one principal depend on what they read
     */
    public function lock(Realm $realm, PrincipalId $id): void
    {
        $this->store->lock($realm, $id);
    }

    public function get(Realm $realm, PrincipalId $id): ?Principal
    {
        return $this->store->get($realm, $id);
    }

    /**
     * The principal with this email address or username, e.g. to sign in. Deleted principals have no
     * identifiers, so they're never found.
     */
    /**
     * Whether the realm has an active principal.
     */
    public function hasActive(Realm $realm): bool
    {
        return $this->store->hasActive($realm);
    }

    public function findByIdentifier(Realm $realm, IdentifierKind $kind, string $value): ?Principal
    {
        try {
            $identifier = $this->identifiers->find($realm, $kind, $kind->normalize($value));
        } catch (InvalidIdentifier) {
            return null;
        }

        return null === $identifier ? null : $this->store->get($realm, $identifier->principalId);
    }

    /**
     * @return array<value-of<IdentifierKind>, Identifier> By kind
     */
    public function identifiers(Realm $realm, PrincipalId $id): array
    {
        return $this->identifiers->of($realm, $id);
    }

    private function changeStatus(string $operation, Realm $realm, PrincipalId $id, PrincipalStatus $status, Origin $origin, Permit $permit): Principal
    {
        $actor = $permit->actor->principalId;

        return $this->transactions->run(function () use ($operation, $realm, $id, $status, $origin, $actor, $permit): Principal {
            // The guard rules check the realm as a whole, and an active principal ends a setup: changes of
            // status take turns over the realm, before anything they read.
            $this->transactions->lockRealm($realm);
            $this->store->lock($realm, $id);
            $before = $this->store->get($realm, $id) ?? throw new UnknownPrincipal($realm, $id);
            $after = $before->withStatus($status, $this->clock->now());

            // The decision refused it already (owns_agents); this is an agent added in between.
            if (PrincipalStatus::Deleted === $status && $this->store->ownsAgents($realm, $id)) {
                throw new OwnsAgents($id);
            }

            $this->refuseIfCancelled($operation, new PrincipalStatusChanging($before, $after, $origin, $actor, $permit));

            if (!$this->store->changeStatus($before, $after)) {
                throw new ConcurrentChange($before);
            }

            if (PrincipalStatus::Deleted === $after->status) {
                $this->identifiers->removeAll($realm, $id);
            }

            $this->log->record($realm, $operation, Outcome::Success, $origin, $actor, 'principal', (string) $id, [
                'from' => $before->status->value,
                'to' => $after->status->value,
            ]);

            $change = new PrincipalChange($operation, $before, $after);

            foreach ($this->guardRules as $rule) {
                $reason = $rule->check($change);

                if (null !== $reason) {
                    throw new GuardRuleViolated($operation, $reason);
                }
            }

            $this->transactions->afterCommit(function () use ($before, $after, $origin, $actor, $permit): void {
                $this->events->dispatch(new PrincipalStatusChanged($before, $after, $origin, $actor, $permit));
            });

            return $after;
        });
    }

    /**
     * @param array<string, mixed> $details
     */
    private function changeIdentifier(string $operation, Principal $principal, ?Identifier $before, ?Identifier $after, Origin $origin, Permit $permit, array $details): void
    {
        $actor = $permit->actor->principalId;

        $this->refuseIfCancelled($operation, new IdentifierChanging($principal->id, $before, $after, $origin, $actor, $permit));

        if (null !== $before) {
            $this->identifiers->remove($principal->realm, $principal->id, $before->kind);
        }

        if (null !== $after) {
            $this->add($after);
        }

        $this->log->record($principal->realm, $operation, Outcome::Success, $origin, $actor, 'principal', (string) $principal->id, $details);

        $this->transactions->afterCommit(function () use ($principal, $before, $after, $origin, $actor, $permit): void {
            $this->events->dispatch(new IdentifierChanged($principal->id, $before, $after, $origin, $actor, $permit));
        });
    }

    private function add(Identifier $identifier): void
    {
        // Checked first for the common case; the store's uniqueness decides when two try at once.
        $taken = $this->identifiers->find($identifier->realm, $identifier->kind, $identifier->normalized);

        if (null !== $taken || !$this->identifiers->add($identifier)) {
            throw new IdentifierTaken($identifier->kind);
        }
    }

    /**
     * The new principal's owner: an agent's, checked; none for any other kind.
     *
     * @throws InvalidOwner
     */
    private function owner(CreatePrincipal $command): ?PrincipalId
    {
        if (PrincipalKind::Agent !== $command->kind) {
            if (null !== $command->owner || null !== $command->profile) {
                throw new InvalidOwner(\sprintf('Only an agent has an owner and a profile, not a principal of the kind %s.', $command->kind->value));
            }

            return null;
        }

        if (null === $command->owner) {
            throw new InvalidOwner('An agent is registered by its owner: give CreatePrincipal the owner\'s ID.');
        }

        $this->ownerFor($command->realm, $command->owner, null);

        return $command->owner;
    }

    /**
     * @throws InvalidOwner When it isn't an active principal of the realm that isn't an agent, or is the agent itself
     */
    private function ownerFor(Realm $realm, PrincipalId $owner, ?PrincipalId $agent): void
    {
        $principal = $this->store->get($realm, $owner);

        if (null === $principal || !$principal->isActive() || PrincipalKind::Agent === $principal->kind || (null !== $agent && $agent->equals($owner))) {
            throw new InvalidOwner(\sprintf('An agent\'s owner is an active principal of the realm "%s" that isn\'t an agent: %s isn\'t.', $realm->name, $owner));
        }
    }

    private function living(Realm $realm, PrincipalId $id): Principal
    {
        $principal = $this->store->get($realm, $id);

        return null === $principal || PrincipalStatus::Deleted === $principal->status ? throw new UnknownPrincipal($realm, $id) : $principal;
    }

    private function refuseIfCancelled(string $operation, CancellableEvent $event): void
    {
        $this->events->dispatch($event);

        if ($event->isCancelled()) {
            throw new OperationCancelled($operation, (string) $event->reason());
        }
    }
}
