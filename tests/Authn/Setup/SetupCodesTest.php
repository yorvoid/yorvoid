<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Setup;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Attempt\AttemptLimits;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Authn\Setup\InvalidSetupCode;
use YorvoID\Authn\Setup\RealmAlreadyClaimed;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Stack;
use YorvoID\Throttle\Limit;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;

/**
 * Setup codes on every implementation (ATN-034).
 */
final class SetupCodesTest extends DatabaseTestCase
{
    private Stack $stack;

    private MockClock $clock;

    private Principals $principals;

    #[DataProvider('implementations')]
    public function testTheFirstToEnterTheCodeClaimsTheRealm(string $implementation): void
    {
        $setup = $this->setupCodes($implementation);
        $code = $setup->create(new CreateSetupCode(new Realm('acme'), Duration::days(1)), new Origin(OriginKind::Console, 'app:install'));

        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{5}(-[0-9A-HJKMNP-TV-Z]{5}){4}-[0-9A-HJKMNP-TV-Z]$/D', $code->reveal(), '26 characters of Crockford\'s base 32: 130 bits.');
        self::assertFalse($setup->isClaimed(new Realm('acme')));

        $owner = $setup->claim(new Realm('acme'), $code, new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: 'ana@example.com'), new Origin(OriginKind::Web));

        self::assertTrue($setup->isClaimed(new Realm('acme')));
        self::assertSame(PrincipalStatus::Active, $owner->status);
        self::assertSame('ana@example.com', $this->principals->identifiers(new Realm('acme'), $owner->id)['email']->value);
        self::assertEquals($owner->id, $this->stack->setup->get(new Realm('acme'))?->claimedBy);
        self::assertSame(hash('sha256', str_replace('-', '', $code->reveal())), $this->stack->setup->get(new Realm('acme'))?->codeHash, 'Only the code\'s hash is kept.');

        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        self::assertSame(['yorvoid.setup.create_code', 'yorvoid.principal.create', 'yorvoid.setup.claim'], array_map(static fn ($entry): string => $entry->action, $entries));
        self::assertSame(['expires_at' => '2026-10-28T18:45:30+00:00'], $entries[0]->details);
        self::assertEquals($owner->id, $entries[2]->actor);

        try {
            $setup->claim(new Realm('acme'), $code, new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Web));
            self::fail('A realm was claimed twice.');
        } catch (InvalidSetupCode) {
        }

        $this->expectException(RealmAlreadyClaimed::class);
        $this->expectExceptionMessage('The realm "acme" was claimed already; use a recovery link to regain access.');

        $setup->create(new CreateSetupCode(new Realm('acme'), Duration::days(1)), new Origin(OriginKind::Console));
    }

    #[DataProvider('implementations')]
    public function testACodeCanBeTypedLoosely(string $implementation): void
    {
        $setup = $this->setupCodes($implementation);
        $code = $setup->create(new CreateSetupCode(new Realm('acme'), Duration::days(1)), new Origin(OriginKind::Console));
        $typed = strtr(strtolower(str_replace('-', ' ', $code->reveal())), ['0' => 'o', '1' => 'l']);

        $setup->claim(new Realm('acme'), Secret::fromString($typed), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Web));

        self::assertTrue($setup->isClaimed(new Realm('acme')));
    }

    #[DataProvider('implementations')]
    public function testRefusesWrongExpiredReplacedAndForeignCodes(string $implementation): void
    {
        $setup = $this->setupCodes($implementation);
        $replaced = $setup->create(new CreateSetupCode(new Realm('acme'), Duration::hours(1)), new Origin(OriginKind::Console));
        $code = $setup->create(new CreateSetupCode(new Realm('acme'), Duration::hours(1)), new Origin(OriginKind::Console));
        $other = $setup->create(new CreateSetupCode(new Realm('globex'), Duration::hours(1)), new Origin(OriginKind::Console));
        $wrong = substr($code->reveal(), 0, -1).('0' === substr($code->reveal(), -1) ? '1' : '0');

        foreach (['replaced' => $replaced, 'another realm\'s' => $other, 'wrong' => Secret::fromString($wrong)] as $what => $attempt) {
            try {
                $setup->claim(new Realm('acme'), $attempt, new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Web));
                self::fail(\sprintf('A %s code claimed the realm.', $what));
            } catch (InvalidSetupCode $exception) {
                self::assertSame('The setup code doesn\'t work. Ask whoever installed the application for a new one.', $exception->getMessage());
            }
        }

        try {
            $setup->claim(new Realm('initech'), $code, new CreatePrincipal(new Realm('initech'), PrincipalKind::Human), new Origin(OriginKind::Web));
            self::fail('A realm without a code was claimed.');
        } catch (InvalidSetupCode) {
        }

        self::assertFalse($setup->isClaimed(new Realm('initech')));
        $this->clock->sleep(3600);

        try {
            $setup->claim(new Realm('acme'), $code, new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Web));
            self::fail('An expired code claimed the realm.');
        } catch (InvalidSetupCode) {
        }

        self::assertFalse($setup->isClaimed(new Realm('acme')));
        self::assertCount(0, array_filter(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), static fn ($entry): bool => 'yorvoid.principal.create' === $entry->action), 'A principal was created by a code that didn\'t work.');
    }

    #[DataProvider('implementations')]
    public function testAFailedClaimLeavesTheRealmUnclaimed(string $implementation): void
    {
        $setup = $this->setupCodes($implementation);
        $code = $setup->create(new CreateSetupCode(new Realm('acme'), Duration::days(1)), new Origin(OriginKind::Console));
        $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: 'ana@example.com'), new Origin(OriginKind::Console));

        try {
            $setup->claim(new Realm('acme'), $code, new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: 'ana@example.com'), new Origin(OriginKind::Web));
            self::fail('The claim went ahead.');
        } catch (\YorvoID\Core\Principal\IdentifierTaken) {
        }

        self::assertFalse($setup->isClaimed(new Realm('acme')));

        // So does the application's own failure, after the claim, in its transaction.
        try {
            $this->stack->transactions->run(static function () use ($setup, $code): void {
                $setup->claim(new Realm('acme'), $code, new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Web));

                throw new \RuntimeException('Granting the owner\'s role failed.');
            });
        } catch (\RuntimeException) {
        }

        self::assertFalse($setup->isClaimed(new Realm('acme')));
        $setup->claim(new Realm('acme'), $code, new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Web));
        self::assertTrue($setup->isClaimed(new Realm('acme')), 'The code stopped working after a failed claim.');
    }

    #[DataProvider('implementations')]
    public function testClaimsFromOneAddressAreLimited(string $implementation): void
    {
        $setup = $this->setupCodes($implementation, new AttemptLimits(perAddress: new Limit(2, Duration::hours(1))));
        $code = $setup->create(new CreateSetupCode(new Realm('acme'), Duration::days(1)), new Origin(OriginKind::Console));

        for ($i = 0; $i < 2; ++$i) {
            try {
                $setup->claim(new Realm('acme'), Secret::fromString('wrong'), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Web), '192.0.2.1');
                self::fail('A wrong code claimed the realm.');
            } catch (InvalidSetupCode) {
            }
        }

        try {
            $setup->claim(new Realm('acme'), $code, new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Web), '192.0.2.1');
            self::fail('A third claim from the address in an hour was checked.');
        } catch (Throttled $exception) {
            self::assertSame(1800, $exception->seconds);
        }

        self::assertFalse($setup->isClaimed(new Realm('acme')));
        $setup->claim(new Realm('acme'), $code, new CreatePrincipal(new Realm('acme'), PrincipalKind::Human), new Origin(OriginKind::Web), '192.0.2.2');
        self::assertTrue($setup->isClaimed(new Realm('acme')));
    }

    public function testTheOwnerIsCreatedActiveInTheRealmClaimed(): void
    {
        $setup = $this->setupCodes('memory');
        $code = $setup->create(new CreateSetupCode(new Realm('acme'), Duration::days(1)), new Origin(OriginKind::Console));

        foreach ([new CreatePrincipal(new Realm('globex'), PrincipalKind::Human), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, PrincipalStatus::Invited)] as $owner) {
            try {
                $setup->claim(new Realm('acme'), $code, $owner, new Origin(OriginKind::Web));
                self::fail('The claim went ahead.');
            } catch (IntegratorError $error) {
                self::assertStringStartsWith('The setup code\'s principal must be created active, in the realm being claimed.', $error->getMessage());
            }
        }

        self::assertFalse($setup->isClaimed(new Realm('acme')));
    }

    public function testCodesAreUniformlyRandom(): void
    {
        $setup = $this->setupCodes('memory');
        $counts = [];

        for ($i = 0; $i < 400; ++$i) {
            foreach (str_split(str_replace('-', '', $setup->create(new CreateSetupCode(new Realm('r'.$i), Duration::days(1)), new Origin(OriginKind::Console))->reveal())) as $character) {
                $counts[$character] = ($counts[$character] ?? 0) + 1;
            }
        }

        // 10 400 characters over 32: about 325 each; a skewed alphabet would show far outside this.
        self::assertCount(32, $counts);
        self::assertGreaterThan(220, min($counts));
        self::assertLessThan(430, max($counts));
    }

    public function testCreatingACodeIsAnOperation(): void
    {
        self::assertNull(new OperationCatalogue([CreateSetupCode::class])->get('yorvoid.setup.create_code')->ability);
    }

    private function setupCodes(string $implementation, AttemptLimits $limits = new AttemptLimits()): SetupCodes
    {
        $this->stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-27 18:45:30 UTC');
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, clock: $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, clock: $this->clock);

        return new SetupCodes($this->stack->setup, $this->principals, $log, $this->stack->transactions, new Attempts(new Throttle($this->stack->throttle, $this->clock), $limits), $this->clock);
    }
}
