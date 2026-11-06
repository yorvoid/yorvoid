<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSession;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Realm;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * Who may run each of YorvoID's operations (D-38), as a reviewed list: a change to it shows in review.
 */
final class WhatAuthorizesYorvoIDsOperationsTest extends TestCase
{
    private const array EXPECTED = [
        'yorvoid.credential.add' => ['consequence'],
        'yorvoid.credential.remove' => ['self'],
        'yorvoid.credential.rename' => ['self'],
        'yorvoid.email_verification.confirm' => ['authentication'],
        'yorvoid.identifier.remove' => ['self', 'ability', 'operator'],
        'yorvoid.identifier.set' => ['self', 'ability', 'operator'],
        'yorvoid.identifier.verify' => ['consequence'],
        'yorvoid.invitation.accept' => ['authentication'],
        'yorvoid.invitation.resend' => ['ability', 'operator'],
        'yorvoid.invitation.send' => ['ability', 'operator'],
        'yorvoid.invitation.withdraw' => ['ability', 'operator'],
        'yorvoid.notification.not_me' => ['authentication'],
        'yorvoid.passkey.register' => ['self'],
        'yorvoid.passkey.sign_in' => ['authentication'],
        'yorvoid.principal.activate' => ['ability', 'operator'],
        'yorvoid.principal.create' => ['ability', 'operator'],
        'yorvoid.principal.delete' => ['ability', 'operator'],
        'yorvoid.principal.suspend' => ['ability', 'operator'],
        'yorvoid.recovery_code.recover' => ['authentication'],
        'yorvoid.recovery_code.redeem' => ['authentication'],
        'yorvoid.recovery_codes.generate' => ['self'],
        'yorvoid.recovery_link.issue' => ['ability', 'operator'],
        'yorvoid.recovery_link.use' => ['authentication'],
        'yorvoid.session.end' => ['self', 'ability', 'operator'],
        'yorvoid.session.end_all' => ['self', 'ability', 'operator'],
        'yorvoid.session.start' => ['consequence'],
        'yorvoid.setup.claim' => ['authentication'],
        'yorvoid.setup.create_code' => ['operator'],
        'yorvoid.testing.sign_in' => ['authentication'],
        'yorvoid.token.issue' => ['ability', 'operator'],
        'yorvoid.token.revoke' => ['ability', 'operator'],
    ];

    public function testEachOperationSaysWhatAuthorizesIt(): void
    {
        $actual = array_map(static fn ($operation): array => array_map(static fn (AuthorizedBy $by): string => $by->value, $operation->authorizedBy), new OperationCatalogue(self::commands())->all());

        self::assertSame(self::EXPECTED, $actual);
    }

    public function testNobodyMarksTheirOwnAddressVerified(): void
    {
        $realm = new Realm('acme');
        $ana = new PrincipalId(new UuidV7());
        $actor = new Member($realm, $ana);

        self::assertTrue(new SetIdentifier($realm, $ana, IdentifierKind::Email, 'ana@example.com')->concerns($actor));
        self::assertFalse(new SetIdentifier($realm, $ana, IdentifierKind::Email, 'ana@example.com', verified: true)->concerns($actor));
        self::assertFalse(new SetIdentifier($realm, new PrincipalId(new UuidV7()), IdentifierKind::Email, 'ana@example.com')->concerns($actor));
    }

    public function testOnlyTheSessionItselfEndsItselfOnItsOwnSay(): void
    {
        $realm = new Realm('acme');
        $ana = new PrincipalId(new UuidV7());
        $now = new \DateTimeImmutable();
        $session = new Session(new SessionId(new UuidV7()), $realm, $ana, 1, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $now, true, true)), $now, $now, $now, $now);

        self::assertTrue(new EndSession($realm, $session->id, SessionEndReason::SignedOut)->concerns($session));
        self::assertFalse(new EndSession($realm, new SessionId(new UuidV7()), SessionEndReason::SignedOut)->concerns($session));
        self::assertFalse(new EndSession($realm, $session->id, SessionEndReason::SignedOut)->concerns(new Member($realm, $ana)), 'Not by the principal without the session.');
    }

    /**
     * @return list<class-string>
     */
    private static function commands(): array
    {
        $commands = [];

        foreach (new Finder()->files()->in(__DIR__.'/../../../packages/*/src')->name('*.php')->contains('#[Operation(') as $file) {
            $class = 1 === preg_match('/^namespace ([^;]+);/m', $file->getContents(), $namespace) ? $namespace[1].'\\'.$file->getBasename('.php') : '';

            if (class_exists($class) && [] !== new \ReflectionClass($class)->getAttributes(Operation::class)) {
                $commands[] = $class;
            }
        }

        self::assertCount(31, $commands, 'The operations moved.');

        return $commands;
    }
}
