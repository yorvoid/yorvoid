<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Principal;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\Event\SecurityLogEntryRecorded;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationCancelled;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\ActivatePrincipal;
use YorvoID\Core\Principal\ConcurrentChange;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\Event\IdentifierChanged;
use YorvoID\Core\Principal\Event\IdentifierChanging;
use YorvoID\Core\Principal\Event\PrincipalCreated;
use YorvoID\Core\Principal\Event\PrincipalCreating;
use YorvoID\Core\Principal\Event\PrincipalStatusChanged;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\GuardRule;
use YorvoID\Core\Principal\GuardRuleViolated;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\IdentifierTaken;
use YorvoID\Core\Principal\InvalidIdentifier;
use YorvoID\Core\Principal\InvalidStatusChange;
use YorvoID\Core\Principal\PrincipalChange;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\RemoveIdentifier;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Principal\VerifyIdentifier;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\Stack;

/**
 * The principal registry on every implementation: creating (M0's exit criterion: recorded in the
 * hash-chained log, which verifies), the lifecycle, guard rules and identifiers.
 */
final class PrincipalsTest extends DatabaseTestCase
{
    private RecordingDispatcher $events;

    #[DataProvider('implementations')]
    public function testCreatesAPrincipalAndRecordsIt(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack, new MockClock('2026-10-27 18:45:30.5 UTC'));
        $actor = new PrincipalId(new UuidV7Generator()->next());
        $origin = new Origin(OriginKind::Console, 'yorvoid:principal:create', 'req-1');

        $principal = $principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Agent, PrincipalStatus::Invited), $origin, $actor);

        self::assertSame(PrincipalKind::Agent, $principal->kind);
        self::assertSame(PrincipalStatus::Invited, $principal->status);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 18:45:30 UTC'), $principal->createdAt);
        self::assertEquals($principal->createdAt, $principal->updatedAt);
        self::assertEquals($principal->createdAt, $principal->statusChangedAt);
        self::assertSame(1, $principal->statusVersion);
        self::assertEquals($principal, $principals->get(new Realm('acme'), $principal->id));
        self::assertNull($principals->get(Realm::default(), $principal->id));

        $entries = iterator_to_array($stack->log->entries(new Realm('acme'), 1), false);
        self::assertCount(1, $entries);
        self::assertSame('yorvoid.principal.create', $entries[0]->action);
        self::assertSame(Outcome::Success, $entries[0]->outcome);
        self::assertEquals($actor, $entries[0]->actor);
        self::assertSame('principal', $entries[0]->subjectType);
        self::assertSame((string) $principal->id, $entries[0]->subjectId);
        self::assertSame(['identifiers' => [], 'kind' => 'agent', 'status' => 'invited'], $entries[0]->details);
        self::assertSame('req-1', $entries[0]->origin->correlationId);

        self::assertTrue(new SecurityLog($stack->log, $stack->transactions)->verify(new Realm('acme'))->isIntact());
        self::assertSame([PrincipalCreating::class, SecurityLogEntryRecorded::class, PrincipalCreated::class], $this->events->classes());
    }

    #[DataProvider('implementations')]
    public function testCreatingHappensBeforeAndCreatedAfterTheCommit(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $seen = [];

        $this->events->listen(PrincipalCreating::class, static function (PrincipalCreating $event) use ($stack, &$seen): void {
            $seen['creating'] = null !== $stack->principals->get($event->principal->realm, $event->principal->id);
        });
        $this->events->listen(PrincipalCreated::class, static function (PrincipalCreated $event) use ($stack, &$seen): void {
            $seen['created'] = null !== $stack->principals->get($event->principal->realm, $event->principal->id);
        });

        $stack->transactions->run(static function () use ($principals, &$seen): void {
            $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Web));
            $seen['committed'] = isset($seen['created']);
        });

        self::assertSame(['creating' => false, 'committed' => false, 'created' => true], $seen);
    }

    #[DataProvider('implementations')]
    public function testAListenerCanRefuse(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $this->events->listen(PrincipalCreating::class, static function (PrincipalCreating $event): void {
            $event->cancel('Only humans may be created here.');
        });

        try {
            $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Agent), new Origin(OriginKind::Api));
            self::fail('The creation went ahead.');
        } catch (OperationCancelled $exception) {
            self::assertSame('yorvoid.principal.create', $exception->operation);
            self::assertSame('Only humans may be created here.', $exception->reason);
            self::assertSame('The operation "yorvoid.principal.create" was cancelled: Only humans may be created here.', $exception->getMessage());
        }

        self::assertSame([], iterator_to_array($stack->log->entries(Realm::default(), 1), false));
        self::assertSame([PrincipalCreating::class], $this->events->classes());
    }

    #[DataProvider('implementations')]
    public function testNothingIsKeptWhenTheApplicationsTransactionRollsBack(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $created = null;

        try {
            $stack->transactions->run(static function () use ($principals, &$created): void {
                $created = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Web));

                throw new \RuntimeException('The application failed afterwards.');
            });
        } catch (\RuntimeException) {
        }

        \assert(null !== $created);
        self::assertNull($principals->get(Realm::default(), $created->id));
        self::assertSame([], iterator_to_array($stack->log->entries(Realm::default(), 1), false));
        self::assertSame([PrincipalCreating::class], $this->events->classes());
    }

    #[DataProvider('implementations')]
    public function testCreatesAPrincipalWithIdentifiersAtOnce(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack, new MockClock('2026-10-27 18:45:30 UTC'));

        $principal = $principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: 'Ana@Example.com', username: 'Ana', emailVerified: true), new Origin(OriginKind::Web));

        $identifiers = $principals->identifiers(new Realm('acme'), $principal->id);
        self::assertSame(['email', 'username'], array_keys($identifiers));
        self::assertSame('Ana@Example.com', $identifiers['email']->value);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 18:45:30 UTC'), $identifiers['email']->verifiedAt);
        self::assertFalse($identifiers['username']->isVerified(), 'A username has nothing to verify.');
        self::assertEquals($principal, $principals->findByIdentifier(new Realm('acme'), IdentifierKind::Email, 'ANA@example.COM'));
        self::assertEquals($principal, $principals->findByIdentifier(new Realm('acme'), IdentifierKind::Username, 'ana'));
        self::assertNull($principals->findByIdentifier(Realm::default(), IdentifierKind::Email, 'ana@example.com'));
        self::assertNull($principals->findByIdentifier(new Realm('acme'), IdentifierKind::Email, 'not an address'));

        $entry = iterator_to_array($stack->log->entries(new Realm('acme'), 1), false)[0];
        self::assertSame(['identifiers' => ['email', 'username'], 'kind' => 'human', 'status' => 'active'], $entry->details, 'The log records which identifiers, never their values.');

        $created = $this->events->dispatched[0];
        self::assertInstanceOf(PrincipalCreating::class, $created);
        self::assertSame(['email', 'username'], array_map(static fn ($identifier): string => $identifier->kind->value, $created->identifiers));
    }

    #[DataProvider('implementations')]
    public function testCreatesNothingWhenAnIdentifierIsTaken(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $ana = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com'), new Origin(OriginKind::Web));
        self::assertFalse($principals->identifiers(Realm::default(), $ana->id)['email']->isVerified(), 'An email address was verified without saying so.');

        try {
            $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, username: 'bob', email: 'ANA@example.com'), new Origin(OriginKind::Web));
            self::fail('Two principals have the same email address.');
        } catch (IdentifierTaken $exception) {
            self::assertSame(IdentifierKind::Email, $exception->kind);
            self::assertSame('Another principal has this email.', $exception->getMessage());
        }

        self::assertNull($principals->findByIdentifier(Realm::default(), IdentifierKind::Username, 'bob'), 'Half of the principal was kept.');
        self::assertCount(1, iterator_to_array($stack->log->entries(Realm::default(), 1), false));
    }

    #[DataProvider('implementations')]
    public function testCreatesNothingWithAnInvalidIdentifier(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);

        try {
            $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, username: 'not valid'), new Origin(OriginKind::Web));
            self::fail('An invalid username was accepted.');
        } catch (InvalidIdentifier) {
        }

        self::assertSame([], iterator_to_array($stack->log->entries(Realm::default(), 1), false));
        self::assertSame([], $this->events->dispatched);
    }

    #[DataProvider('implementations')]
    public function testGoesThroughTheLifecycle(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $clock = new MockClock('2026-10-27 18:45:30 UTC');
        $principals = $this->principals($stack, $clock);
        $realm = new Realm('acme');
        $actor = new PrincipalId(new UuidV7Generator()->next());
        $invited = $principals->create(new CreatePrincipal($realm, PrincipalKind::Human, PrincipalStatus::Invited), new Origin(OriginKind::Web));

        $clock->sleep(60);
        $active = $principals->activate(new ActivatePrincipal($realm, $invited->id), new Origin(OriginKind::Web, 'invitation.accept'));
        $suspended = $principals->suspend(new SuspendPrincipal($realm, $invited->id), new Origin(OriginKind::Console), $actor);
        $clock->sleep(60);
        $again = $principals->activate(new ActivatePrincipal($realm, $invited->id), new Origin(OriginKind::Console), $actor);

        self::assertSame([PrincipalStatus::Active, PrincipalStatus::Suspended, PrincipalStatus::Active], [$active->status, $suspended->status, $again->status]);
        self::assertSame([2, 3, 4], [$active->statusVersion, $suspended->statusVersion, $again->statusVersion]);
        self::assertEquals(new \DateTimeImmutable('2026-10-27 18:47:30 UTC'), $again->statusChangedAt);
        self::assertEquals($invited->createdAt, $again->createdAt);
        self::assertEquals($again, $principals->get($realm, $invited->id));

        $entries = iterator_to_array($stack->log->entries($realm, 1), false);
        self::assertSame(['yorvoid.principal.create', 'yorvoid.principal.activate', 'yorvoid.principal.suspend', 'yorvoid.principal.activate'], array_map(static fn ($entry): string => $entry->action, $entries));
        self::assertSame(['from' => 'active', 'to' => 'suspended'], $entries[2]->details);
        self::assertEquals($actor, $entries[2]->actor);
        self::assertSame((string) $invited->id, $entries[2]->subjectId);
        self::assertTrue(new SecurityLog($stack->log, $stack->transactions)->verify($realm)->isIntact());
    }

    #[DataProvider('implementations')]
    public function testStatusChangingHappensBeforeAndChangedAfterTheCommit(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $principal = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Web));
        $this->events->dispatched = [];
        $seen = [];

        $this->events->listen(PrincipalStatusChanging::class, static function (PrincipalStatusChanging $event) use ($stack, &$seen): void {
            $seen['changing'] = [$event->before->status, $event->after->status, $stack->principals->get($event->before->realm, $event->before->id)?->status];
        });
        $this->events->listen(PrincipalStatusChanged::class, static function (PrincipalStatusChanged $event) use ($stack, &$seen): void {
            $seen['changed'] = [$event->before->status, $event->after->status, $stack->principals->get($event->before->realm, $event->before->id)?->status];
        });

        $stack->transactions->run(static function () use ($principals, $principal, &$seen): void {
            $principals->suspend(new SuspendPrincipal(Realm::default(), $principal->id), new Origin(OriginKind::Web));
            $seen['committed'] = isset($seen['changed']);
        });

        self::assertSame([
            'changing' => [PrincipalStatus::Active, PrincipalStatus::Suspended, PrincipalStatus::Active],
            'committed' => false,
            'changed' => [PrincipalStatus::Active, PrincipalStatus::Suspended, PrincipalStatus::Suspended],
        ], $seen);
        self::assertSame([PrincipalStatusChanging::class, SecurityLogEntryRecorded::class, PrincipalStatusChanged::class], $this->events->classes());
    }

    #[DataProvider('implementations')]
    public function testAListenerCanRefuseAStatusChange(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $principal = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Web));
        $this->events->listen(PrincipalStatusChanging::class, static function (PrincipalStatusChanging $event): void {
            $event->cancel('Accounts with open orders can\'t be deleted.');
        });

        try {
            $principals->delete(new DeletePrincipal(Realm::default(), $principal->id), new Origin(OriginKind::Web));
            self::fail('The deletion went ahead.');
        } catch (OperationCancelled $exception) {
            self::assertSame('yorvoid.principal.delete', $exception->operation);
        }

        self::assertEquals($principal, $principals->get(Realm::default(), $principal->id));
        self::assertCount(1, iterator_to_array($stack->log->entries(Realm::default(), 1), false));
    }

    #[DataProvider('implementations')]
    public function testRefusesChangesTheLifecycleDoesntAllow(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $invited = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, PrincipalStatus::Invited), new Origin(OriginKind::Web));
        $deleted = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Web));
        $principals->delete(new DeletePrincipal(Realm::default(), $deleted->id), new Origin(OriginKind::Web));

        foreach ([
            static fn () => $principals->suspend(new SuspendPrincipal(Realm::default(), $invited->id), new Origin(OriginKind::Web)),
            static fn () => $principals->activate(new ActivatePrincipal(Realm::default(), $deleted->id), new Origin(OriginKind::Web)),
            static fn () => $principals->delete(new DeletePrincipal(Realm::default(), $deleted->id), new Origin(OriginKind::Web)),
        ] as $change) {
            try {
                $change();
                self::fail('A change the lifecycle doesn\'t allow went ahead.');
            } catch (InvalidStatusChange) {
            }
        }

        self::assertSame(PrincipalStatus::Invited, $principals->get(Realm::default(), $invited->id)?->status);
        self::assertCount(3, iterator_to_array($stack->log->entries(Realm::default(), 1), false));
    }

    #[DataProvider('implementations')]
    public function testRefusesUnknownPrincipalsAndOnesInAnotherRealm(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $principal = $principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Web));

        $this->expectException(UnknownPrincipal::class);
        $this->expectExceptionMessage(\sprintf('There is no principal %s in the realm "globex".', $principal->id));

        $principals->suspend(new SuspendPrincipal(new Realm('globex'), $principal->id), new Origin(OriginKind::Web));
    }

    #[DataProvider('implementations')]
    public function testRefusesAChangeMadeFromAnOutdatedRead(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $principal = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Web));

        // Someone else suspends it after this request read it, and before it writes.
        $this->events->listen(PrincipalStatusChanging::class, static function (PrincipalStatusChanging $event) use ($stack): void {
            if (PrincipalStatus::Deleted === $event->after->status) {
                $stack->principals->changeStatus($event->before, $event->before->withStatus(PrincipalStatus::Suspended, new \DateTimeImmutable()));
            }
        });

        $this->expectException(ConcurrentChange::class);
        $this->expectExceptionMessage(\sprintf('The principal %s was changed by someone else meanwhile; try again.', $principal->id));

        $principals->delete(new DeletePrincipal(Realm::default(), $principal->id), new Origin(OriginKind::Web));
    }

    #[DataProvider('implementations')]
    public function testDeletingKeepsATombstoneAndFreesTheIdentifiers(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $principal = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', username: 'ana'), new Origin(OriginKind::Web));

        $deleted = $principals->delete(new DeletePrincipal(Realm::default(), $principal->id), new Origin(OriginKind::Web));

        self::assertSame(PrincipalStatus::Deleted, $principals->get(Realm::default(), $principal->id)?->status);
        self::assertSame(PrincipalStatus::Deleted, $deleted->status);
        self::assertSame([], $principals->identifiers(Realm::default(), $principal->id));
        self::assertNull($principals->findByIdentifier(Realm::default(), IdentifierKind::Email, 'ana@example.com'));
        $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', username: 'ana'), new Origin(OriginKind::Web));

        foreach ([
            static fn () => $principals->setIdentifier(new SetIdentifier(Realm::default(), $principal->id, IdentifierKind::Username, 'ghost'), new Origin(OriginKind::Web)),
            static fn () => $principals->removeIdentifier(new RemoveIdentifier(Realm::default(), $principal->id, IdentifierKind::Username), new Origin(OriginKind::Web)),
            static fn () => $principals->verifyIdentifier(new VerifyIdentifier(Realm::default(), $principal->id, IdentifierKind::Email, 'ana@example.com'), new Origin(OriginKind::Web)),
        ] as $change) {
            try {
                $change();
                self::fail('A deleted principal\'s identifiers changed.');
            } catch (UnknownPrincipal) {
            }
        }
    }

    #[DataProvider('implementations')]
    public function testAGuardRuleSeesTheChangeAndCanRefuseIt(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $rule = new class($stack) implements GuardRule {
            /** @var list<array{string, string, string, ?string}> */
            public array $seen = [];

            public function __construct(
                private readonly Stack $stack,
            ) {
            }

            public function check(PrincipalChange $change): ?string
            {
                $this->seen[] = [$change->operation, $change->before->status->value, $change->after->status->value, $this->stack->principals->get($change->after->realm, $change->after->id)?->status->value];

                return PrincipalStatus::Deleted === $change->after->status ? 'Owners can\'t be deleted.' : null;
            }
        };
        $principals = $this->principals($stack, guardRules: [$rule]);
        $principal = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com'), new Origin(OriginKind::Web));
        $principals->suspend(new SuspendPrincipal(Realm::default(), $principal->id), new Origin(OriginKind::Web));
        $this->events->dispatched = [];

        try {
            $principals->delete(new DeletePrincipal(Realm::default(), $principal->id), new Origin(OriginKind::Web));
            self::fail('The guard rule didn\'t stop the deletion.');
        } catch (GuardRuleViolated $exception) {
            self::assertSame('yorvoid.principal.delete', $exception->operation);
            self::assertSame('Owners can\'t be deleted.', $exception->reason);
            self::assertSame('The operation "yorvoid.principal.delete" was refused: Owners can\'t be deleted.', $exception->getMessage());
        }

        self::assertSame([['yorvoid.principal.suspend', 'active', 'suspended', 'suspended'], ['yorvoid.principal.delete', 'suspended', 'deleted', 'deleted']], $rule->seen, 'A rule sees the state as it will be.');
        self::assertSame(PrincipalStatus::Suspended, $principals->get(Realm::default(), $principal->id)?->status);
        self::assertCount(1, $principals->identifiers(Realm::default(), $principal->id), 'The deletion was half kept.');
        self::assertCount(2, iterator_to_array($stack->log->entries(Realm::default(), 1), false));
        self::assertSame([PrincipalStatusChanging::class], $this->events->classes(), 'An event announced a change that didn\'t happen.');
    }

    public function testEveryGuardRuleRunsWhateverItsKey(): void
    {
        $stack = $this->stack('memory');
        /** @var \ArrayObject<int, string> $checked */
        $checked = new \ArrayObject();
        $rule = static fn (string $name): GuardRule => new readonly class($name, $checked) implements GuardRule {
            /**
             * @param \ArrayObject<int, string> $checked
             */
            public function __construct(
                private string $name,
                private \ArrayObject $checked,
            ) {
            }

            public function check(PrincipalChange $change): ?string
            {
                $this->checked->append($this->name);

                return null;
            }
        };
        $rules = (static function () use ($rule): \Generator {
            yield 'rule' => $rule('first');
            yield 'rule' => $rule('second');
        })();
        $principals = new Principals($stack->principals, $stack->identifiers, new SecurityLog($stack->log, $stack->transactions), $stack->transactions, guardRules: $rules);
        $principal = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Web));

        $principals->suspend(new SuspendPrincipal(Realm::default(), $principal->id), new Origin(OriginKind::Web));
        $principals->activate(new ActivatePrincipal(Realm::default(), $principal->id), new Origin(OriginKind::Web));

        self::assertSame(['first', 'second', 'first', 'second'], $checked->getArrayCopy());
    }

    #[DataProvider('implementations')]
    public function testSetsRemovesAndVerifiesIdentifiers(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $clock = new MockClock('2026-10-27 18:45:30 UTC');
        $principals = $this->principals($stack, $clock);
        $realm = new Realm('acme');
        $id = $principals->create(new CreatePrincipal($realm, PrincipalKind::Human), new Origin(OriginKind::Web))->id;
        $changes = [];
        $this->events->listen(IdentifierChanged::class, static function (IdentifierChanged $event) use (&$changes): void {
            $changes[] = $event;
        });

        $set = $principals->setIdentifier(new SetIdentifier($realm, $id, IdentifierKind::Email, 'Ana@Example.com'), new Origin(OriginKind::Web));
        self::assertFalse($set->isVerified());
        self::assertEquals(['email' => $set], $principals->identifiers($realm, $id));

        $clock->sleep(60);
        self::assertNull($principals->verifyIdentifier(new VerifyIdentifier($realm, $id, IdentifierKind::Email, 'bob@example.com'), new Origin(OriginKind::Web)), 'Another address was verified for this one.');
        self::assertNull($principals->verifyIdentifier(new VerifyIdentifier($realm, $id, IdentifierKind::Email, 'not an address'), new Origin(OriginKind::Web)));
        self::assertNull($principals->verifyIdentifier(new VerifyIdentifier($realm, $id, IdentifierKind::Username, 'ana'), new Origin(OriginKind::Web)));
        $verified = $principals->verifyIdentifier(new VerifyIdentifier($realm, $id, IdentifierKind::Email, 'ana@example.com'), new Origin(OriginKind::Web));
        self::assertEquals(new \DateTimeImmutable('2026-10-27 18:46:30 UTC'), $verified?->verifiedAt);
        self::assertEquals($set->createdAt, $verified?->createdAt);
        self::assertEquals($verified, $principals->verifyIdentifier(new VerifyIdentifier($realm, $id, IdentifierKind::Email, 'ana@example.com'), new Origin(OriginKind::Web)), 'Verifying again changed it.');

        $recased = $principals->setIdentifier(new SetIdentifier($realm, $id, IdentifierKind::Email, 'ana@example.com'), new Origin(OriginKind::Web));
        self::assertEquals($verified?->verifiedAt, $recased->verifiedAt, 'The same address in other letters is still verified.');
        self::assertEquals($set->createdAt, $recased->createdAt, 'Changing an identifier changed when the principal first had one of its kind.');
        $changed = $principals->setIdentifier(new SetIdentifier($realm, $id, IdentifierKind::Email, 'ana@example.org'), new Origin(OriginKind::Web));
        self::assertFalse($changed->isVerified(), 'A new address was verified without its owner showing it\'s theirs.');
        $trusted = $principals->setIdentifier(new SetIdentifier($realm, $id, IdentifierKind::Email, 'ana@example.net', verified: true), new Origin(OriginKind::Web));
        self::assertTrue($trusted->isVerified());

        $principals->removeIdentifier(new RemoveIdentifier($realm, $id, IdentifierKind::Email), new Origin(OriginKind::Web));
        $principals->removeIdentifier(new RemoveIdentifier($realm, $id, IdentifierKind::Email), new Origin(OriginKind::Web));
        self::assertSame([], $principals->identifiers($realm, $id));

        $entries = \array_slice(iterator_to_array($stack->log->entries($realm, 1), false), 1);
        self::assertSame([
            ['yorvoid.identifier.set', ['kind' => 'email', 'verified' => false]],
            ['yorvoid.identifier.verify', ['kind' => 'email']],
            ['yorvoid.identifier.set', ['kind' => 'email', 'verified' => true]],
            ['yorvoid.identifier.set', ['kind' => 'email', 'verified' => false]],
            ['yorvoid.identifier.set', ['kind' => 'email', 'verified' => true]],
            ['yorvoid.identifier.remove', ['kind' => 'email']],
        ], array_map(static fn ($entry): array => [$entry->action, $entry->details], $entries), 'Removing what isn\'t there isn\'t a change.');

        self::assertCount(6, $changes);
        self::assertNull($changes[0]->before);
        self::assertSame('Ana@Example.com', $changes[0]->after?->value);
        self::assertSame('ana@example.net', $changes[5]->before?->value);
        self::assertNull($changes[5]->after);
    }

    #[DataProvider('implementations')]
    public function testRefusesAnIdentifierAnotherPrincipalHas(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, username: 'ana'), new Origin(OriginKind::Web));
        $bob = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, username: 'bob'), new Origin(OriginKind::Web));

        try {
            $principals->setIdentifier(new SetIdentifier(Realm::default(), $bob->id, IdentifierKind::Username, 'ANA'), new Origin(OriginKind::Web));
            self::fail('Two principals have the same username.');
        } catch (IdentifierTaken) {
        }

        self::assertSame('bob', $principals->identifiers(Realm::default(), $bob->id)['username']->value, 'The old username was removed anyway.');
    }

    #[DataProvider('implementations')]
    public function testAListenerCanRefuseAnIdentifierChange(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $principals = $this->principals($stack);
        $ana = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, username: 'ana'), new Origin(OriginKind::Web));
        $this->events->listen(IdentifierChanging::class, static function (IdentifierChanging $event): void {
            $event->cancel('Usernames can\'t change.');
        });

        try {
            $principals->setIdentifier(new SetIdentifier(Realm::default(), $ana->id, IdentifierKind::Username, 'anna'), new Origin(OriginKind::Web));
            self::fail('The change went ahead.');
        } catch (OperationCancelled $exception) {
            self::assertSame('yorvoid.identifier.set', $exception->operation);
        }

        self::assertSame('ana', $principals->identifiers(Realm::default(), $ana->id)['username']->value);
    }

    public function testCreatingIsAnOperation(): void
    {
        $catalogue = new OperationCatalogue([CreatePrincipal::class, ActivatePrincipal::class, SuspendPrincipal::class, DeletePrincipal::class, SetIdentifier::class, RemoveIdentifier::class, VerifyIdentifier::class]);
        $operation = $catalogue->get('yorvoid.principal.create');

        self::assertSame('yorvoid.principals.manage', $operation->ability);
        self::assertSame('yorvoid.principal.delete', $operation->inverse);
        self::assertSame(['realm', 'kind', 'status', 'email', 'username', 'emailVerified'], array_map(static fn ($parameter): string => $parameter->name, $operation->parameters));
        self::assertSame('yorvoid.principal.activate', $catalogue->get('yorvoid.principal.suspend')->inverse);
        self::assertSame('yorvoid.principal.suspend', $catalogue->get('yorvoid.principal.activate')->inverse);
        self::assertNull($catalogue->get('yorvoid.principal.delete')->inverse);
        self::assertSame('yorvoid.identifier.remove', $catalogue->get('yorvoid.identifier.set')->inverse);
        self::assertNull($catalogue->get('yorvoid.identifier.verify')->ability, 'Verifying is done by the flow that saw it, not granted to anyone.');
    }

    /**
     * @param list<GuardRule> $guardRules
     */
    private function principals(Stack $stack, ?MockClock $clock = null, array $guardRules = []): Principals
    {
        $this->events = new RecordingDispatcher();
        $clock ??= new MockClock();
        $ids = new UuidV7Generator($clock);

        return new Principals($stack->principals, $stack->identifiers, new SecurityLog($stack->log, $stack->transactions, $this->events, $ids, $clock), $stack->transactions, $this->events, $ids, $clock, $guardRules);
    }
}
