<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Session;

use PHPUnit\Framework\TestCase;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\SessionPolicy;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;

final class SessionTypesTest extends TestCase
{
    public function testAnAuthenticationContextKnowsItsMethodsAndWhenTheyWereUsed(): void
    {
        $context = new AuthenticationContext(
            new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable('2026-10-27 18:40:00.8 Europe/Skopje')),
            new AuthenticationFactor(AuthenticationMethod::RecoveryCode, new \DateTimeImmutable('2026-10-27 18:50:00 UTC')),
            new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable('2026-10-27 19:05:00 UTC')),
            new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable('2026-10-27 19:00:00 UTC')),
        );

        self::assertSame([AuthenticationMethod::Passkey, AuthenticationMethod::RecoveryCode], $context->methods());
        self::assertEquals(new \DateTimeImmutable('2026-10-27 19:05:00 UTC'), $context->lastUsed(AuthenticationMethod::Passkey));
        self::assertEquals(new \DateTimeImmutable('2026-10-27 18:50:00 UTC'), $context->lastUsed(AuthenticationMethod::RecoveryCode));
        self::assertNull($context->lastUsed(AuthenticationMethod::Password));
        self::assertSame(['method' => 'passkey', 'at' => '2026-10-27T17:40:00+00:00', 'multi_factor' => false, 'phishing_resistant' => false, 'operation' => null], $context->toArray()[0], 'Times are stored in UTC, to the second.');
        self::assertEquals(new \DateTimeImmutable('2026-10-27 19:05:00 UTC'), $context->lastAuthenticatedAt());
        self::assertEquals($context, AuthenticationContext::fromArray($context->toArray()));
    }

    public function testAStepUpAddsAProofAndDropsTheOnesItMakesRedundant(): void
    {
        $at = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-10-27 '.$time.' UTC');
        $signIn = new AuthenticationFactor(AuthenticationMethod::Passkey, $at('18:40:00'), true, true);
        $forRemoving = new AuthenticationFactor(AuthenticationMethod::Passkey, $at('18:50:00'), true, true, 'yorvoid.credential.remove');
        $weaker = new AuthenticationFactor(AuthenticationMethod::Passkey, $at('18:55:00'), false, true, 'yorvoid.credential.remove');
        $notResistant = new AuthenticationFactor(AuthenticationMethod::Passkey, $at('18:56:00'), true, false, 'yorvoid.credential.remove');
        $code = new AuthenticationFactor(AuthenticationMethod::RecoveryCode, $at('18:57:00'), true, true, 'yorvoid.credential.remove');
        $context = new AuthenticationContext($signIn, $forRemoving, $weaker, $notResistant, $code);

        $again = new AuthenticationFactor(AuthenticationMethod::Passkey, $at('19:00:00'), true, true, 'yorvoid.credential.remove');
        self::assertSame([$signIn, $weaker, $notResistant, $code, $again], $context->with($again)->factors, 'Only the same proof for the same operation is redundant.');

        $forAdding = new AuthenticationFactor(AuthenticationMethod::Passkey, $at('19:00:00'), true, true, 'yorvoid.credential.add');
        self::assertSame([...$context->factors, $forAdding], $context->with($forAdding)->factors);

        $older = new AuthenticationFactor(AuthenticationMethod::Passkey, $at('18:45:00'), true, true, 'yorvoid.credential.remove');
        self::assertSame([$signIn, $forRemoving, $weaker, $notResistant, $code, $older], $context->with($older)->factors, 'An older proof replaces nothing.');

        $sameTime = new AuthenticationFactor(AuthenticationMethod::Passkey, $at('18:50:00'), true, true, 'yorvoid.credential.remove');
        self::assertSame([$signIn, $weaker, $notResistant, $code, $sameTime], $context->with($sameTime)->factors);
        self::assertEquals($context->with($again), AuthenticationContext::fromArray($context->with($again)->toArray()));
    }

    public function testAnAuthenticationContextsFactorsAreAList(): void
    {
        $factor = new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable('2026-10-27 18:40:00 UTC'));

        self::assertSame([$factor], new AuthenticationContext(...['signIn' => $factor])->factors);
    }

    public function testAnAuthenticationContextIsNeverEmpty(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('A session needs at least one authentication factor.');

        new AuthenticationContext();
    }

    public function testThePresetsAreNistsLimits(): void
    {
        self::assertEquals([Duration::hours(24), Duration::hours(1)], [SessionPolicy::aal2()->absolute, SessionPolicy::aal2()->idle]);
        self::assertEquals([Duration::hours(12), Duration::minutes(15)], [SessionPolicy::aal3()->absolute, SessionPolicy::aal3()->idle]);
        self::assertEquals(Duration::hours(8), new SessionPolicy(Duration::hours(8), Duration::hours(8))->idle, 'An idle timeout as long as the session is allowed.');
    }

    public function testRefusesAnIdleTimeoutLongerThanTheSession(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The idle timeout (9h) is longer than the session\'s whole lifetime (8h).');

        new SessionPolicy(Duration::hours(8), Duration::hours(9));
    }

    public function testSessionIds(): void
    {
        $id = SessionId::fromString('01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c');

        self::assertSame('01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c', (string) $id);
        self::assertTrue($id->equals(SessionId::fromString('01970D9C-8A2E-7B1C-9F3A-2D4E5F6A7B8C')));
        self::assertFalse($id->equals(SessionId::fromString('01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8d')));

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('"a-token" is not a session ID.');

        SessionId::fromString('a-token');
    }
}
