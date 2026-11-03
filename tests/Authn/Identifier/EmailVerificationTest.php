<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Identifier;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Identifier\EmailVerification;
use YorvoID\Authn\Notification\NotificationSettings;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Translations;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Event\IdentifierChanged;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Mail\Block\Action;
use YorvoID\Mail\Block\Paragraph;
use YorvoID\Mail\InMemoryMailer;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Translation\Translator;

final class EmailVerificationTest extends DatabaseTestCase
{
    private MockClock $clock;
    private Principals $principals;
    private InMemoryMailer $mailer;

    #[DataProvider('implementations')]
    public function testALinkVerifiesTheAddressItWasSentTo(string $implementation): void
    {
        $verification = $this->verification($implementation);
        $ana = $this->principal('ana@example.com');

        self::assertTrue($verification->send(new Realm('acme'), $ana->id, new Origin(OriginKind::Web)));
        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertSame(['ana@example.com', 'Confirm your email address for Acme', 'en'], [$email->to, $email->subject, $email->locale]);
        $body = $email->body[0];
        \assert($body instanceof Paragraph);
        self::assertStringStartsWith('Confirm that ana@example.com is your address, so that Acme can tell you', $body->text);
        $token = self::token($email->body[1]);

        self::assertSame('ana@example.com', $verification->peek(new Realm('acme'), $token)?->value);
        self::assertNull($verification->peek(new Realm('globex'), $token));
        self::assertFalse($this->verified($ana), 'Looking at the link changed something.');

        $verified = $verification->confirm(new Realm('acme'), $token, new Origin(OriginKind::Web));
        self::assertSame('ana@example.com', $verified?->value);
        self::assertTrue($this->verified($ana));
        self::assertNull($verification->confirm(new Realm('acme'), $token, new Origin(OriginKind::Web)), 'A link worked twice.');
        self::assertNull($verification->peek(new Realm('acme'), $token));

        // Verified now: nothing more to send.
        self::assertFalse($verification->send(new Realm('acme'), $ana->id, new Origin(OriginKind::Web)));
        self::assertFalse($verification->send(new Realm('acme'), $this->principal(null)->id, new Origin(OriginKind::Web)));
        self::assertSame(['ana@example.com'], array_map(static fn ($sent): string => $sent->to, $this->mailer->sent), 'Only the first.');
    }

    #[DataProvider('implementations')]
    public function testALinkWorksOnlyForTheAddressItWentToAndOnlyTheNewest(string $implementation): void
    {
        $verification = $this->verification($implementation);
        $ana = $this->principal('ana@example.com');
        $verification->send(new Realm('acme'), $ana->id, new Origin(OriginKind::Web));
        $older = self::token($this->mailer->sent[0]->body[1]);
        $verification->send(new Realm('acme'), $ana->id, new Origin(OriginKind::Web));
        $newer = self::token($this->mailer->sent[1]->body[1]);

        self::assertNull($verification->confirm(new Realm('acme'), $older, new Origin(OriginKind::Web)), 'A replaced link worked.');

        $this->principals->setIdentifier(new SetIdentifier(new Realm('acme'), $ana->id, IdentifierKind::Email, 'ana@example.org'), new Origin(OriginKind::Web));
        self::assertNull($verification->confirm(new Realm('acme'), $newer, new Origin(OriginKind::Web)), 'A link verified an address that isn\'t the one it went to.');
        self::assertFalse($this->verified($ana));
    }

    #[DataProvider('implementations')]
    public function testANewAddressThatIsntVerifiedGetsItsLink(string $implementation): void
    {
        $verification = $this->verification($implementation);
        $ana = $this->principal('ana@example.com', verified: true);
        $before = $this->principals->identifiers(new Realm('acme'), $ana->id)['email'];
        $origin = new Origin(OriginKind::Web);

        $after = $this->principals->setIdentifier(new SetIdentifier(new Realm('acme'), $ana->id, IdentifierKind::Email, 'ana@example.org'), $origin);
        $verification->identifierChanged(new IdentifierChanged($ana->id, $before, $after, $origin, $ana->id));
        self::assertSame(['ana@example.org'], array_map(static fn ($email): string => $email->to, $this->mailer->sent));

        // A verified address, a username, or no address at all: no link.
        $verified = $this->principals->setIdentifier(new SetIdentifier(new Realm('acme'), $ana->id, IdentifierKind::Email, 'ana@example.net', verified: true), $origin);
        $username = $this->principals->setIdentifier(new SetIdentifier(new Realm('acme'), $ana->id, IdentifierKind::Username, 'ana'), $origin);

        foreach ([$verified, $username, null] as $identifier) {
            $verification->identifierChanged(new IdentifierChanged($ana->id, $after, $identifier, $origin, $ana->id));
        }

        self::assertCount(1, $this->mailer->sent);
    }

    #[DataProvider('implementations')]
    public function testTheLinkIsInThePrincipalsLanguage(string $implementation): void
    {
        $verification = $this->verification($implementation, 'de');
        $verification->send(new Realm('acme'), $this->principal('ana@example.com')->id, new Origin(OriginKind::Web));

        self::assertSame(['Bestätigen Sie Ihre E-Mail-Adresse für Acme', 'de'], [$this->mailer->sent[0]->subject, $this->mailer->sent[0]->locale]);
        $action = $this->mailer->sent[0]->body[1];
        \assert($action instanceof Action);
        self::assertSame('E-Mail-Adresse bestätigen', $action->label);
    }

    private function verification(string $implementation, string $locale = 'en'): EmailVerification
    {
        $stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-28 18:45:30 UTC');
        $events = new RecordingDispatcher();
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($stack->log, $stack->transactions, $events, $ids, $this->clock);
        $this->principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions, ids: $ids, clock: $this->clock);
        $tokens = new OneTimeTokens($stack->tokens, $this->principals, $log, $stack->transactions, clock: $this->clock);
        $this->mailer = new InMemoryMailer();

        return new EmailVerification($this->principals, $tokens, $this->mailer, new Translator([Translations::DIRECTORY]), new NotificationSettings('Acme', 'https://acme.example/not-me?token={token}', static fn (): string => $locale), $stack->transactions, static fn (string $token): string => 'https://acme.example/verify?token='.rawurlencode($token));
    }

    private function principal(?string $email, bool $verified = false): Principal
    {
        return $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: $email, emailVerified: $verified), new Origin(OriginKind::Console));
    }

    private function verified(Principal $principal): bool
    {
        return true === ($this->principals->identifiers(new Realm('acme'), $principal->id)['email'] ?? null)?->isVerified();
    }

    private static function token(mixed $action): Secret
    {
        \assert($action instanceof Action);
        \assert(1 === preg_match('#^https://acme\.example/verify\?token=(.+)$#', $action->url, $match));

        return Secret::fromString(rawurldecode($match[1]));
    }
}
