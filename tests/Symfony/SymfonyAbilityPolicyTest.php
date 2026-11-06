<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Symfony\Security\SymfonyAbilityPolicy;

final class SymfonyAbilityPolicyTest extends TestCase
{
    public function testTheRequestsOwnSessionHoldsWhatSymfonySecurityGrants(): void
    {
        $session = self::session();
        $command = new SuspendPrincipal(new Realm('acme'), new PrincipalId(new UuidV7()));
        $checker = new class implements AuthorizationCheckerInterface {
            /** @var list<array{mixed, mixed}> */
            public array $asked = [];

            public bool $grants = true;

            public function isGranted(mixed $attribute, mixed $subject = null, mixed $accessDecision = null): bool
            {
                $this->asked[] = [$attribute, $subject];

                return $this->grants;
            }
        };
        $policy = new SymfonyAbilityPolicy($checker, static fn (): Session => $session);

        self::assertTrue($policy->holds($session, 'yorvoid.principals.manage', $command));
        self::assertSame([['yorvoid.principals.manage', $command]], $checker->asked, 'The voters see the command as the subject.');

        $checker->grants = false;
        self::assertFalse($policy->holds($session, 'yorvoid.principals.manage', $command));
    }

    public function testItCantTellForAnyoneElse(): void
    {
        $session = self::session();
        $command = new SuspendPrincipal(new Realm('acme'), new PrincipalId(new UuidV7()));
        $checker = new class implements AuthorizationCheckerInterface {
            public function isGranted(mixed $attribute, mixed $subject = null, mixed $accessDecision = null): bool
            {
                throw new \LogicException('Symfony was asked about someone else.');
            }
        };

        foreach ([
            [static fn (): ?Session => null, $session],
            [static fn (): Session => self::session(), $session],
            [static fn (): Session => $session, new ActingPrincipal(new Realm('acme'), $session->principalId)],
            [static fn (): Session => $session, new Operator()],
        ] as [$current, $actor]) {
            self::assertNull(new SymfonyAbilityPolicy($checker, $current)->holds($actor, 'yorvoid.principals.manage', $command));
        }
    }

    private static function session(): Session
    {
        $now = new \DateTimeImmutable();

        return new Session(new SessionId(new UuidV7()), new Realm('acme'), new PrincipalId(new UuidV7()), 1, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $now, true, true)), $now, $now, $now, $now);
    }
}
