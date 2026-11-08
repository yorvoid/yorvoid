<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\AbilityPolicy;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Authorization\Fixtures\StubOfflineDecider;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Core\Authorization\Fixtures\UpdateSite;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * An ability asked about outside YorvoID's operations, e.g. an application's "edit this page": the same
 * evaluator, where only permits by an ability speak.
 */
final class AbilityRequestTest extends TestCase
{
    public function testOnlyPermitsByAnAbilitySpeakForIt(): void
    {
        $ability = new StubPermit('ability', Truth::holds('has it'));
        $operator = new StubPermit('operator', Truth::holds('the host'), AuthorizedBy::Operator);
        $self = new StubPermit('self', Truth::holds('own'), AuthorizedBy::Self);
        $authentication = new StubPermit('authentication', Truth::holds('a secret'), AuthorizedBy::Authentication);
        $evaluator = new Evaluator([$operator, $self, $authentication], new StubAuthority(Truth::holds('valid')));

        $decision = $evaluator->decideAbility(new Operator(), 'acme.pages.edit', new Realm('acme'), null, new Origin(OriginKind::Console));

        self::assertSame(Verdict::Deny, $decision->verdict);
        self::assertSame('No permit applies to "acme.pages.edit".', $decision->reason);
        self::assertSame(0, $operator->evaluated + $self->evaluated + $authentication->evaluated);

        $permitted = new Evaluator([$ability, $operator], new StubAuthority(Truth::holds('valid')))->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), null, new Origin(OriginKind::Web));

        self::assertSame(Verdict::Permit, $permitted->verdict);
        self::assertSame(AuthorizedBy::Ability, $permitted->basis);
        self::assertSame('has it', $permitted->reason);
    }

    public function testAnAbilityPolicyDecidesOperationsOnly(): void
    {
        $policy = new class implements AbilityPolicy {
            public function holds(Actor $actor, string $ability, Command $command): bool
            {
                return true;
            }
        };
        $evaluator = Evaluator::builtIn(new StubAuthority(Truth::holds('valid')), $policy, commandLine: false);

        $decision = $evaluator->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), null, new Origin(OriginKind::Web));

        self::assertSame(Verdict::Deny, $decision->verdict, 'It\'s asked with a command, which an ability alone hasn\'t.');
        self::assertSame('No permit applies to "acme.pages.edit".', $decision->reason);
    }

    public function testTheRequestCarriesTheAbilityTheResourceAndOneMoment(): void
    {
        $seen = new class implements PermitElement {
            public ?Request $request = null;

            public function name(): string
            {
                return 'seen';
            }

            public function basis(): AuthorizedBy
            {
                return AuthorizedBy::Ability;
            }

            public function matches(Request $request): bool
            {
                return true;
            }

            public function evaluate(Request $request): Truth
            {
                $this->request = $request;

                return Truth::holds('seen');
            }
        };
        $clock = new MockClock('2026-11-07 20:00:00');
        $page = new ResourceRef('page', '42');
        $actor = self::member();

        new Evaluator([$seen], new StubAuthority(Truth::holds('valid')), $clock)->decideAbility($actor, 'acme.pages.edit', new Realm('acme'), $page, new Origin(OriginKind::Web));

        $request = $seen->request;
        self::assertNotNull($request);
        self::assertSame('acme.pages.edit', $request->ability);
        self::assertSame('acme.pages.edit', $request->name());
        self::assertSame($page, $request->resource);
        self::assertSame('acme', $request->realm->name);
        self::assertNull($request->operation);
        self::assertNull($request->command);
        self::assertSame([AuthorizedBy::Ability], $request->authorizedBy());
        self::assertEquals($clock->now(), $request->at);
        self::assertTrue($request->actorInRealm());
        self::assertFalse(Request::forAbility('acme.pages.edit', new Realm('other'), null, $actor, new Origin(OriginKind::Web), $clock->now())->actorInRealm());
    }

    public function testAnOperationsRequestHasItsAbilityAndTheResourceItsCommandConcerns(): void
    {
        $operation = new OperationDescription('acme.site.update', 'Update a site', [AuthorizedBy::Ability, AuthorizedBy::Operator], 'acme.sites.update', null, UpdateSite::class, []);
        $request = Request::forOperation($operation, new UpdateSite(new Realm('acme'), '7'), self::member(), new Origin(OriginKind::Web), new \DateTimeImmutable());

        self::assertSame('acme.sites.update', $request->ability);
        self::assertSame('acme.site.update', $request->name());
        self::assertSame('site:7', (string) $request->resource);
        self::assertSame([AuthorizedBy::Ability, AuthorizedBy::Operator], $request->authorizedBy());

        $badge = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Ability], 'acme.badges.issue', null, IssueBadge::class, []);
        self::assertNull(Request::forOperation($badge, new IssueBadge(new Realm('acme'), new PrincipalId(new UuidV7())), self::member(), new Origin(OriginKind::Web), new \DateTimeImmutable())->resource);
    }

    public function testAnAbilityIsNeverDecidedOfflineAndItsIndeterminateIsLoggedByName(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<array-key, mixed>}> */
            public array $logged = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->logged[] = [(string) $message, $context];
            }
        };
        $offline = new StubOfflineDecider(Truth::holds('the door may open'));
        $evaluator = new Evaluator([new StubPermit('ability', Truth::holds('has it'))], new StubAuthority(Truth::unknown(IndeterminateReason::StoreUnreachable, 'down')), logger: $logger, offline: $offline);

        $decision = $evaluator->decideAbility(self::member(), 'acme.doors.open', new Realm('acme'), null, new Origin(OriginKind::Web));

        self::assertSame(Verdict::Indeterminate, $decision->verdict);
        self::assertSame(0, $offline->asked);
        self::assertCount(1, $logger->logged);
        self::assertSame('acme.doors.open', $logger->logged[0][1]['operation']);
    }

    public function testAResourceIsATypeAndAnId(): void
    {
        $site = new ResourceRef('site', '42');

        self::assertSame('site:42', (string) $site);
        self::assertTrue($site->equals(ResourceRef::parse('site:42')));
        self::assertFalse($site->equals(new ResourceRef('site', '43')));
        self::assertFalse($site->equals(new ResourceRef('server', '42')));
        self::assertSame('a:b:c', ResourceRef::parse('event_series:a:b:c')->id, 'Only the first colon separates.');
        self::assertSame('event_series', ResourceRef::parse('event_series:x')->type);
        new ResourceRef(str_repeat('a', 64), str_repeat('x', 255));

        foreach (['Site', '1site', 'site-x', 'site_', '_site', str_repeat('a', 65)] as $type) {
            try {
                new ResourceRef($type, '1');
                self::fail(\sprintf('"%s" was taken as a type.', $type));
            } catch (IntegratorError $error) {
                self::assertStringContainsString(\sprintf('"%s"', $type), $error->getMessage());
            }
        }

        foreach (['', str_repeat('x', 256)] as $id) {
            try {
                new ResourceRef('site', $id);
                self::fail('The ID was taken.');
            } catch (IntegratorError $error) {
                self::assertStringStartsWith('The ID of a "site" must be 1 to 255 bytes.', $error->getMessage());
            }
        }

        try {
            ResourceRef::parse('site');
            self::fail('A reference without an ID was parsed.');
        } catch (IntegratorError $error) {
            self::assertStringStartsWith('"site" isn\'t a resource reference.', $error->getMessage());
        }
    }

    private static function member(): Member
    {
        return new Member(new Realm('acme'), new PrincipalId(new UuidV7()));
    }
}
