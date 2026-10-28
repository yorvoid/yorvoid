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
 * "-ing" event; the "-ed" event follows the commit.
 *
 * The security log records which kind of identifier changed, never its value: the log can't forget, and
 * an email address is personal data that must go when its owner does.
 *
 * @api
 */
final readonly class Principals
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

    /**
     * @param PrincipalId|null $actor Who asked, if a principal did (not, e.g., an installer on the command line)
     *
     * @throws InvalidIdentifier  When the email address or username isn't valid
     * @throws IdentifierTaken    When another principal in the realm has it
     * @throws OperationCancelled When a listener of PrincipalCreating refuses it
     */
    public function create(CreatePrincipal $command, Origin $origin, ?PrincipalId $actor = null): Principal
    {
        return $this->transactions->run(function () use ($command, $origin, $actor): Principal {
            $now = Time::stored($this->clock->now());
            $principal = new Principal(new PrincipalId($this->ids->next()), $command->realm, $command->kind, $command->status, $now, $now, $now);
            $identifiers = [];

            if (null !== $command->email) {
                $identifiers[] = new Identifier($principal->realm, $principal->id, IdentifierKind::Email, $command->email, $now, $command->emailVerified ? $now : null);
            }

            if (null !== $command->username) {
                $identifiers[] = new Identifier($principal->realm, $principal->id, IdentifierKind::Username, $command->username, $now);
            }

            $this->refuseIfCancelled('yorvoid.principal.create', new PrincipalCreating($principal, $identifiers, $origin, $actor));
            $this->store->add($principal);

            foreach ($identifiers as $identifier) {
                $this->add($identifier);
            }

            $this->log->record($principal->realm, 'yorvoid.principal.create', Outcome::Success, $origin, $actor, 'principal', (string) $principal->id, [
                'kind' => $principal->kind->value,
                'status' => $principal->status->value,
                'identifiers' => array_map(static fn (Identifier $identifier): string => $identifier->kind->value, $identifiers),
            ]);

            $this->transactions->afterCommit(function () use ($principal, $identifiers, $origin, $actor): void {
                $this->events->dispatch(new PrincipalCreated($principal, $identifiers, $origin, $actor));
            });

            return $principal;
        });
    }

    /**
     * Activates an invited principal, or a suspended one again.
     *
     * @throws UnknownPrincipal|InvalidStatusChange|ConcurrentChange|OperationCancelled|GuardRuleViolated
     */
    public function activate(ActivatePrincipal $command, Origin $origin, ?PrincipalId $actor = null): Principal
    {
        return $this->changeStatus('yorvoid.principal.activate', $command->realm, $command->principalId, PrincipalStatus::Active, $origin, $actor);
    }

    /**
     * Suspends an active principal: it can't sign in or act until it's activated again.
     *
     * @throws UnknownPrincipal|InvalidStatusChange|ConcurrentChange|OperationCancelled|GuardRuleViolated
     */
    public function suspend(SuspendPrincipal $command, Origin $origin, ?PrincipalId $actor = null): Principal
    {
        return $this->changeStatus('yorvoid.principal.suspend', $command->realm, $command->principalId, PrincipalStatus::Suspended, $origin, $actor);
    }

    /**
     * Deletes a principal: what remains is a tombstone with its ID, kind and times, so that the security
     * log's references stay valid. Its identifiers are removed, so its email address can be used again.
     *
     * @throws UnknownPrincipal|InvalidStatusChange|ConcurrentChange|OperationCancelled|GuardRuleViolated
     */
    public function delete(DeletePrincipal $command, Origin $origin, ?PrincipalId $actor = null): Principal
    {
        return $this->changeStatus('yorvoid.principal.delete', $command->realm, $command->principalId, PrincipalStatus::Deleted, $origin, $actor);
    }

    /**
     * @throws UnknownPrincipal   When there's no such principal, or it's deleted
     * @throws InvalidIdentifier  When the value isn't valid
     * @throws IdentifierTaken    When another principal in the realm has it
     * @throws OperationCancelled When a listener of IdentifierChanging refuses it
     */
    public function setIdentifier(SetIdentifier $command, Origin $origin, ?PrincipalId $actor = null): Identifier
    {
        return $this->transactions->run(function () use ($command, $origin, $actor): Identifier {
            $principal = $this->living($command->realm, $command->principalId);
            $before = $this->identifiers->of($principal->realm, $principal->id)[$command->kind->value] ?? null;
            $now = Time::stored($this->clock->now());
            $verifiedAt = $command->verified ? $now : ($before?->normalized === $command->kind->normalize($command->value) ? $before->verifiedAt : null);
            $after = new Identifier($principal->realm, $principal->id, $command->kind, $command->value, $before->createdAt ?? $now, $verifiedAt);

            $this->changeIdentifier('yorvoid.identifier.set', $principal, $before, $after, $origin, $actor, ['kind' => $command->kind->value, 'verified' => $after->isVerified()]);

            return $after;
        });
    }

    /**
     * @throws UnknownPrincipal   When there's no such principal, or it's deleted
     * @throws OperationCancelled When a listener of IdentifierChanging refuses it
     */
    public function removeIdentifier(RemoveIdentifier $command, Origin $origin, ?PrincipalId $actor = null): void
    {
        $this->transactions->run(function () use ($command, $origin, $actor): void {
            $principal = $this->living($command->realm, $command->principalId);
            $before = $this->identifiers->of($principal->realm, $principal->id)[$command->kind->value] ?? null;

            if (null !== $before) {
                $this->changeIdentifier('yorvoid.identifier.remove', $principal, $before, null, $origin, $actor, ['kind' => $command->kind->value]);
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
    public function verifyIdentifier(VerifyIdentifier $command, Origin $origin, ?PrincipalId $actor = null): ?Identifier
    {
        return $this->transactions->run(function () use ($command, $origin, $actor): ?Identifier {
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
            $this->changeIdentifier('yorvoid.identifier.verify', $principal, $before, $after, $origin, $actor, ['kind' => $before->kind->value]);

            return $after;
        });
    }

    public function get(Realm $realm, PrincipalId $id): ?Principal
    {
        return $this->store->get($realm, $id);
    }

    /**
     * The principal with this email address or username, e.g. to sign in. Deleted principals have no
     * identifiers, so they're never found.
     */
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

    private function changeStatus(string $operation, Realm $realm, PrincipalId $id, PrincipalStatus $status, Origin $origin, ?PrincipalId $actor): Principal
    {
        return $this->transactions->run(function () use ($operation, $realm, $id, $status, $origin, $actor): Principal {
            $before = $this->store->get($realm, $id) ?? throw new UnknownPrincipal($realm, $id);
            $after = $before->withStatus($status, $this->clock->now());

            $this->refuseIfCancelled($operation, new PrincipalStatusChanging($before, $after, $origin, $actor));

            if (!$this->store->changeStatus($before, $after)) {
                throw new ConcurrentChange($before);
            }

            if (PrincipalStatus::Deleted === $after->status) {
                $this->identifiers->removeAll($realm, $id);
            }

            // Recording takes the realm's log lock, which every change waits for until this transaction
            // ends; the guard rules run after it, so they see what concurrent changes committed.
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

            $this->transactions->afterCommit(function () use ($before, $after, $origin, $actor): void {
                $this->events->dispatch(new PrincipalStatusChanged($before, $after, $origin, $actor));
            });

            return $after;
        });
    }

    /**
     * @param array<string, mixed> $details
     */
    private function changeIdentifier(string $operation, Principal $principal, ?Identifier $before, ?Identifier $after, Origin $origin, ?PrincipalId $actor, array $details): void
    {
        $this->refuseIfCancelled($operation, new IdentifierChanging($principal->id, $before, $after, $origin, $actor));

        if (null !== $before) {
            $this->identifiers->remove($principal->realm, $principal->id, $before->kind);
        }

        if (null !== $after) {
            $this->add($after);
        }

        $this->log->record($principal->realm, $operation, Outcome::Success, $origin, $actor, 'principal', (string) $principal->id, $details);

        $this->transactions->afterCommit(function () use ($principal, $before, $after, $origin, $actor): void {
            $this->events->dispatch(new IdentifierChanged($principal->id, $before, $after, $origin, $actor));
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
