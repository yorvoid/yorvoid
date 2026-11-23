<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Principal;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\AgentProfile;
use YorvoID\Core\Principal\ChangeAgentOwner;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\InvalidOwner;
use YorvoID\Core\Principal\OwnsAgents;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\Stack;
use YorvoID\Tests\Support\TestOperations;
use YorvoID\Tests\Support\TestPrincipal;

/**
 * Agents (AGT-001): registered by an owner, an active principal of the realm that isn't an agent, with a
 * profile for people; moved to another owner; and an owner of agents isn't deleted, a refusal decided
 * before anything is written.
 */
final class AgentsTest extends DatabaseTestCase
{
    private Realm $realm;

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
    }

    #[DataProvider('implementations')]
    public function testAnAgentIsRegisteredByItsOwner(string $implementation): void
    {
        $stack = $this->stack($implementation);
        [$operations] = $this->operations($stack);
        $owner = $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Human));
        $profile = new AgentProfile('Release notes assistant', 'acme-agent', '2.4.1', 'Docs team');

        $agent = $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Agent, owner: $owner->id, profile: $profile));

        self::assertEquals([$owner->id, $profile], [$agent->owner, $agent->profile]);
        self::assertEquals($agent, $stack->principals->get($this->realm, $agent->id), 'Read back as it was written.');
        self::assertSame((string) $owner->id, $this->entries($stack, 'yorvoid.principal.create')[1]->details['owner'] ?? null);
        self::assertTrue($stack->principals->ownsAgents($this->realm, $owner->id));
        self::assertFalse($stack->principals->ownsAgents($this->realm, $agent->id));
        self::assertFalse($stack->principals->ownsAgents(new Realm('globex'), $owner->id), 'Only in its realm.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ownersThatArent(): iterable
    {
        foreach (self::implementations() as $name => [$implementation]) {
            foreach (['none', 'unknown', 'suspended', 'an agent', 'in another realm'] as $case) {
                yield $name.': '.$case => [$implementation, $case];
            }
        }
    }

    #[DataProvider('ownersThatArent')]
    public function testAnAgentsOwnerIsAnActivePrincipalOfItsRealmThatIsntAnAgent(string $implementation, string $case): void
    {
        $stack = $this->stack($implementation);
        [$operations] = $this->operations($stack);
        $human = $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Human));
        $owner = match ($case) {
            'none' => null,
            'unknown' => new PrincipalId(new UuidV7Generator()->next()),
            'suspended' => $this->runAs($operations, new SuspendPrincipal($this->realm, $human->id))->id,
            'an agent' => $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Agent, owner: $human->id))->id,
            default => $this->create($operations, new CreatePrincipal(new Realm('globex'), PrincipalKind::Human))->id,
        };

        $this->expectException(InvalidOwner::class);
        $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Agent, owner: $owner));
    }

    #[DataProvider('implementations')]
    public function testOnlyAnAgentHasAnOwnerOrAProfile(string $implementation): void
    {
        [$operations] = $this->operations($this->stack($implementation));
        $owner = $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Human));

        foreach ([new CreatePrincipal($this->realm, PrincipalKind::Service, owner: $owner->id), new CreatePrincipal($this->realm, PrincipalKind::Human, profile: new AgentProfile('x'))] as $command) {
            try {
                $this->create($operations, $command);
                self::fail('Created a '.$command->kind->value.' with an owner or a profile.');
            } catch (InvalidOwner $e) {
                self::assertStringContainsString('Only an agent has an owner and a profile', $e->getMessage());
            }
        }
    }

    /**
     * An owner of agents isn't deleted: a Deny, owns_agents, before anything is written, whoever asks.
     */
    #[DataProvider('implementations')]
    public function testAnOwnerOfAgentsIsntDeleted(string $implementation): void
    {
        $stack = $this->stack($implementation);
        [$operations] = $this->operations($stack);
        $owner = $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Human));
        $agent = $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Agent, owner: $owner->id));
        $entries = \count($this->entries($stack));

        foreach (['the operator' => new Operator(), 'the owner, of their own account' => new TestPrincipal($this->realm, $owner->id)] as $who => $actor) {
            try {
                $this->runAs($operations, new DeletePrincipal($this->realm, $owner->id), $actor);
                self::fail('Deleted by '.$who.'.');
            } catch (NotPermitted $refused) {
                self::assertSame(Verdict::Deny, $refused->decision->verdict, $who);
                self::assertSame('yorvoid.principal.owns_agents', $refused->decision->reasons[array_key_first(array_filter($refused->decision->reasons, static fn ($reason): bool => 'forbid' === $reason->effect && 'holds' === $reason->truth->value->value))]->source ?? null, $who);
            }
        }

        self::assertSame(PrincipalStatus::Active, $stack->principals->get($this->realm, $owner->id)?->status, 'Nothing written.');
        self::assertCount($entries, $this->entries($stack));

        // Moved to another owner, the first one can go.
        $other = $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Human));
        $moved = $this->runAs($operations, new ChangeAgentOwner($this->realm, $agent->id, $other->id));
        self::assertEquals($other->id, $moved->owner);
        self::assertEquals($other->id, $stack->principals->get($this->realm, $agent->id)?->owner);
        $details = $this->entries($stack, 'yorvoid.principal.change_owner')[0]->details;
        self::assertSame([(string) $owner->id, (string) $other->id], [$details['from'] ?? null, $details['to'] ?? null]);
        self::assertSame(PrincipalStatus::Deleted, $this->runAs($operations, new DeletePrincipal($this->realm, $owner->id))->status);

        // A deleted agent leaves its owner free to go.
        $this->runAs($operations, new DeletePrincipal($this->realm, $agent->id));
        self::assertSame(PrincipalStatus::Deleted, $this->runAs($operations, new DeletePrincipal($this->realm, $other->id))->status);
    }

    /**
     * An agent added after the decision, before the write: the handler refuses it under the realm's lock.
     */
    #[DataProvider('implementations')]
    public function testTheHandlerRefusesWhatTheDecisionCouldntSee(string $implementation): void
    {
        $stack = $this->stack($implementation);
        [$operations, $principals] = $this->operations($stack);
        $owner = $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Human));
        $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Agent, owner: $owner->id));

        $this->expectException(OwnsAgents::class);
        Permits::call($principals->delete(...), new DeletePrincipal($this->realm, $owner->id), new Origin(OriginKind::Console));
    }

    #[DataProvider('implementations')]
    public function testOnlyAnAgentChangesOwnerAndOnlyToOneThatCanOwnIt(string $implementation): void
    {
        [$operations] = $this->operations($this->stack($implementation));
        $owner = $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Human));
        $agent = $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Agent, owner: $owner->id));
        $human = $this->create($operations, new CreatePrincipal($this->realm, PrincipalKind::Human));

        foreach ([[$human->id, $owner->id], [$agent->id, $agent->id], [$agent->id, $this->runAs($operations, new SuspendPrincipal($this->realm, $human->id))->id]] as [$moving, $to]) {
            try {
                $this->runAs($operations, new ChangeAgentOwner($this->realm, $moving, $to));
                self::fail('Moved.');
            } catch (InvalidOwner) {
                // Refused.
            }
        }

        self::assertEquals($owner->id, $this->runAs($operations, new ChangeAgentOwner($this->realm, $agent->id, $owner->id))->owner, 'To its own owner again: nothing changes.');
    }

    /**
     * @return array{Operations, Principals}
     */
    private function operations(Stack $stack): array
    {
        $clock = new MockClock('2026-11-23 18:00:00');
        $ids = new UuidV7Generator($clock);
        $log = new SecurityLog($stack->log, $stack->transactions, clock: $clock, ids: $ids);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions, ids: $ids, clock: $clock);
        // Anyone may manage principals here, so an owner may ask to delete their own account.
        $everyone = new class implements AbilityPolicy {
            public function holds(Actor $actor, string $ability, Command $command): ?bool
            {
                return true;
            }
        };

        return [TestOperations::with($log, $stack->principals, $everyone, null, $principals), $principals];
    }

    private function create(Operations $operations, CreatePrincipal $command): Principal
    {
        return $this->runAs($operations, $command);
    }

    /**
     * @template T
     *
     * @param Command<T> $command
     *
     * @return T
     */
    private function runAs(Operations $operations, Command $command, ?Actor $actor = null): mixed
    {
        return $operations->run($command, $actor ?? new Operator(), new Origin(OriginKind::Console));
    }

    /**
     * @return list<LogEntry>
     */
    private function entries(Stack $stack, ?string $action = null): array
    {
        $all = [];

        foreach ([$this->realm, new Realm('globex')] as $realm) {
            array_push($all, ...iterator_to_array($stack->log->entries($realm, 1), false));
        }

        return array_values(array_filter($all, static fn (LogEntry $entry): bool => null === $action || $action === $entry->action));
    }
}
