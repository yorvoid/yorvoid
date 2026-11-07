<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PrincipalAuthority;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\Storage\InMemoryPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * An actor's own authority (06 §4): a principal on record, active, acting in the command's realm.
 */
final class PrincipalAuthorityTest extends TestCase
{
    public function testAnActivePrincipalOfTheRealmIsValid(): void
    {
        [$authority, $id] = self::with(PrincipalStatus::Active);
        $truth = $authority->check(self::request(new Member(new Realm('acme'), $id)));

        self::assertSame([TruthValue::Holds, 'The acting principal is active in the realm.'], [$truth->value, $truth->detail]);
    }

    public function testAnyOtherStatusIsInvalid(): void
    {
        foreach ([PrincipalStatus::Invited, PrincipalStatus::Suspended, PrincipalStatus::Deleted] as $status) {
            [$authority, $id] = self::with($status);
            $truth = $authority->check(self::request(new Member(new Realm('acme'), $id)));

            self::assertSame([TruthValue::Fails, \sprintf('The acting principal is %s.', $status->value)], [$truth->value, $truth->detail]);
        }
    }

    public function testActingInAnotherRealmIsInvalid(): void
    {
        [$authority, $id] = self::with(PrincipalStatus::Active);

        foreach ([new Realm('globex'), null] as $realm) {
            $truth = $authority->check(self::request(new Member($realm, $id)));

            self::assertSame([TruthValue::Fails, 'The actor acts in another realm than the request\'s.'], [$truth->value, $truth->detail]);
        }
    }

    public function testAPrincipalNotOnRecordIsUnknown(): void
    {
        [$authority] = self::with(PrincipalStatus::Active);
        $truth = $authority->check(self::request(new Member(new Realm('acme'), $missing = new PrincipalId(new UuidV7()))));

        self::assertSame([TruthValue::Unknown, IndeterminateReason::MissingData, \sprintf('The acting principal %s isn\'t on record.', $missing)], [$truth->value, $truth->reason, $truth->detail]);
    }

    public function testWhoeverClaimsNoPrincipalHasNothingToInvalidate(): void
    {
        [$authority] = self::with(PrincipalStatus::Active);

        foreach ([new Operator(), new Anonymous()] as $actor) {
            self::assertSame(TruthValue::Holds, $authority->check(self::request($actor))->value);
        }
    }

    public function testAPrincipalLoadedAlreadyIsntReadAgain(): void
    {
        $now = new \DateTimeImmutable('2026-11-07 18:00:00 UTC');
        $id = new PrincipalId(new UuidV7());
        $suspended = new Principal($id, new Realm('acme'), PrincipalKind::Human, PrincipalStatus::Suspended, $now, $now, $now);
        $store = new InMemoryPrincipalStore();
        $store->add(new Principal($id, new Realm('acme'), PrincipalKind::Human, PrincipalStatus::Active, $now, $now, $now));
        $member = new Member(new Realm('acme'), $id);

        // The loaded one says suspended, the store active: the loaded one is what counts.
        $loaded = new PrincipalAuthority($store, static fn (Actor $actor): ?Principal => $actor === $member ? $suspended : null);
        self::assertSame('The acting principal is suspended.', $loaded->check(self::request($member))->detail);
        self::assertSame(TruthValue::Holds, $loaded->check(self::request(new Member(new Realm('acme'), $id)))->value, 'Another actor\'s isn\'t loaded.');

        foreach ([new Principal(new PrincipalId(new UuidV7()), new Realm('acme'), PrincipalKind::Human, PrincipalStatus::Suspended, $now, $now, $now), new Principal($id, new Realm('globex'), PrincipalKind::Human, PrincipalStatus::Suspended, $now, $now, $now)] as $other) {
            $mismatched = new PrincipalAuthority($store, static fn (Actor $actor): Principal => $other);
            self::assertSame(TruthValue::Holds, $mismatched->check(self::request($member))->value, 'A loaded principal that isn\'t the actor\'s is ignored.');
        }
    }

    /**
     * @return array{PrincipalAuthority, PrincipalId}
     */
    private static function with(PrincipalStatus $status): array
    {
        $store = new InMemoryPrincipalStore();
        $id = new PrincipalId(new UuidV7());
        $now = new \DateTimeImmutable('2026-11-07 18:00:00 UTC');
        $store->add(new Principal($id, new Realm('acme'), PrincipalKind::Human, $status, $now, $now, $now));
        // The same ID elsewhere would be another principal: it's looked up in the actor's realm.
        $store->add(new Principal(new PrincipalId(new UuidV7()), new Realm('globex'), PrincipalKind::Human, PrincipalStatus::Active, $now, $now, $now));

        return [new PrincipalAuthority($store), $id];
    }

    private static function request(Actor $actor): Request
    {
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Ability], 'acme.badges.issue', null, IssueBadge::class, []);

        return Request::forOperation($operation, new IssueBadge(new Realm('acme'), new PrincipalId(new UuidV7())), $actor, new Origin(OriginKind::Web), new \DateTimeImmutable());
    }
}
