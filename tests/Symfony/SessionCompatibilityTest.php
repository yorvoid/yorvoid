<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\Storage\InMemoryIdentifierStore;
use YorvoID\Core\Principal\Storage\InMemoryPrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Symfony\Security\DefaultPrincipalUsers;
use YorvoID\Symfony\Security\PrincipalUser;
use YorvoID\Symfony\Security\PrincipalUserProvider;

/**
 * Symfony keeps the security token in the session with serialize(): what it keeps of a PrincipalUser is
 * its identifier alone, and the firewall loads the principal again by it, so a session doesn't depend on
 * the shape of YorvoID's value objects.
 */
final class SessionCompatibilityTest extends TestCase
{
    private const string PRINCIPAL = '019aa0c4-6f3e-7c2a-9b1d-3e5f7a9c0b2d';

    /**
     * What a session keeps now is the identifier only, not the shape of YorvoID's value objects.
     */
    public function testASessionKeepsOnlyTheIdentifier(): void
    {
        $at = new \DateTimeImmutable('2026-11-20 12:00:00 UTC');
        $serialized = serialize(new PostAuthenticationToken(new PrincipalUser(new Principal(PrincipalId::fromString(self::PRINCIPAL), new Realm('acme'), PrincipalKind::Human, PrincipalStatus::Active, $at, $at, $at)), 'main', ['ROLE_USER']));

        self::assertStringNotContainsString(Principal::class, $serialized);
        self::assertStringContainsString(self::PRINCIPAL, $serialized);
        $token = unserialize($serialized);
        self::assertInstanceOf(PostAuthenticationToken::class, $token);
        $user = $token->getUser();
        self::assertInstanceOf(PrincipalUser::class, $user);
        $this->assertRefreshed($user);
    }

    private function assertRefreshed(PrincipalUser $user): void
    {
        $at = new \DateTimeImmutable('2026-11-20 12:00:00 UTC');
        $store = new InMemoryPrincipalStore();
        $store->add(new Principal(PrincipalId::fromString(self::PRINCIPAL), new Realm('acme'), PrincipalKind::Human, PrincipalStatus::Active, $at, $at, $at));
        $transactions = new InMemoryTransactions();
        $provider = new PrincipalUserProvider(new Principals($store, new InMemoryIdentifierStore(), new SecurityLog(new InMemorySecurityLogStore(), $transactions), $transactions), new Realm('acme'), new DefaultPrincipalUsers());

        $refreshed = $provider->refreshUser($user);

        self::assertInstanceOf(PrincipalUser::class, $refreshed);
        self::assertSame(self::PRINCIPAL, (string) $refreshed->principal->id);
    }
}
