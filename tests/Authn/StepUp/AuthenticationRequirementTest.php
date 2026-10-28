<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\StepUp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\StepUp\AuthenticationRequirement;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;

/**
 * What a sensitive operation needs (ATN-070, ATN-071).
 */
final class AuthenticationRequirementTest extends TestCase
{
    public function testAProofCountsWithinTheWindowOnly(): void
    {
        $requirement = new AuthenticationRequirement(Duration::minutes(5));
        $context = new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::RecoveryCode, self::at('18:00:00')));

        self::assertTrue($requirement->isMetBy($context, self::at('18:04:59')));
        self::assertFalse($requirement->isMetBy($context, self::at('18:05:00')));
    }

    public function testAnyRecentProofWillDo(): void
    {
        $context = new AuthenticationContext(
            new AuthenticationFactor(AuthenticationMethod::Passkey, self::at('17:00:00'), true, true),
            new AuthenticationFactor(AuthenticationMethod::RecoveryCode, self::at('18:00:00')),
        );

        self::assertTrue(new AuthenticationRequirement(Duration::minutes(5))->isMetBy($context, self::at('18:01:00')));
        self::assertFalse(new AuthenticationRequirement(Duration::minutes(5), multiFactor: true)->isMetBy($context, self::at('18:01:00')), 'The strong proof is too old.');
        self::assertTrue(new AuthenticationRequirement(Duration::hours(2), multiFactor: true)->isMetBy($context, self::at('18:01:00')));
    }

    /**
     * @return iterable<string, array{bool, bool, bool, bool, bool}>
     */
    public static function strengths(): iterable
    {
        yield 'any proof' => [false, false, false, false, true];
        yield 'two factors, from one' => [true, false, false, false, false];
        yield 'two factors, from two' => [true, false, true, false, true];
        yield 'phishing-resistant, from a code' => [false, true, true, false, false];
        yield 'phishing-resistant, from a passkey' => [false, true, false, true, true];
        yield 'both, from a passkey without user verification' => [true, true, false, true, false];
        yield 'both, from a passkey' => [true, true, true, true, true];
    }

    #[DataProvider('strengths')]
    public function testAStrongerProofIsNeededWhenAsked(bool $multiFactor, bool $phishingResistant, bool $proofMultiFactor, bool $proofPhishingResistant, bool $met): void
    {
        $context = new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, self::at('18:00:00'), $proofMultiFactor, $proofPhishingResistant));

        self::assertSame($met, new AuthenticationRequirement(Duration::minutes(5), $multiFactor, $phishingResistant)->isMetBy($context, self::at('18:01:00')));
    }

    public function testAStepUpCountsOnlyForItsOperation(): void
    {
        $signedIn = new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, self::at('18:00:00')));
        $steppedUp = new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, self::at('18:00:00'), operation: 'yorvoid.credential.remove'));
        $removing = new AuthenticationRequirement(Duration::minutes(5), operation: 'yorvoid.credential.remove');
        $adding = new AuthenticationRequirement(Duration::minutes(5), operation: 'yorvoid.credential.add');
        $anything = new AuthenticationRequirement(Duration::minutes(5));

        self::assertTrue($removing->isMetBy($signedIn, self::at('18:01:00')), 'Signing in counts for any operation.');
        self::assertTrue($anything->isMetBy($signedIn, self::at('18:01:00')));
        self::assertTrue($removing->isMetBy($steppedUp, self::at('18:01:00')));
        self::assertFalse($adding->isMetBy($steppedUp, self::at('18:01:00')), 'A step-up counted for another operation.');
        self::assertFalse($anything->isMetBy($steppedUp, self::at('18:01:00')), 'A step-up counted for no operation in particular.');
    }

    public function testAcceptsTheLongestOperationName(): void
    {
        $name = 'acme.'.str_repeat('a', 184).'.x';
        self::assertSame(191, \strlen($name));

        self::assertSame($name, new AuthenticationRequirement(Duration::minutes(5), operation: $name)->operation);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidOperations(): iterable
    {
        yield 'too long' => ['acme.'.str_repeat('a', 185).'.x'];
        yield 'two segments' => ['acme.remove'];
        yield 'capitals' => ['acme.Site.remove'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidOperations')]
    public function testRefusesAnInvalidOperationName(string $operation): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage(\sprintf('The operation "%s" is invalid.', $operation));

        new AuthenticationRequirement(Duration::minutes(5), operation: $operation);
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-28 '.$time.' UTC');
    }
}
