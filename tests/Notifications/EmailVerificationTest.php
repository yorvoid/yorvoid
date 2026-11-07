<?php

declare(strict_types=1);

namespace YorvoID\Tests\Notifications;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Event\IdentifierChanged;
use YorvoID\Core\Principal\Event\PrincipalCreated;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Mail\Block\Action;
use YorvoID\Mail\Block\Paragraph;
use YorvoID\Mail\Email;
use YorvoID\Mail\InMemoryMailer;
use YorvoID\Mail\Mailer;
use YorvoID\Notifications\EmailVerification;
use YorvoID\Notifications\NotificationSettings;
use YorvoID\Notifications\Translations;
use YorvoID\Notifications\VerifyEmail;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;
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

        self::assertTrue($verification->send(new Realm('acme'), $ana->id, Permits::because(new Realm('acme')), new Origin(OriginKind::Web)));
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

        $verified = Permits::call($verification->confirm(...), new VerifyEmail(new Realm('acme'), $token), new Origin(OriginKind::Web));
        self::assertSame('ana@example.com', $verified?->value);
        self::assertTrue($this->verified($ana));
        self::assertNull(Permits::call($verification->confirm(...), new VerifyEmail(new Realm('acme'), $token), new Origin(OriginKind::Web)), 'A link worked twice.');
        self::assertNull($verification->peek(new Realm('acme'), $token));

        // Verified now: nothing more to send.
        self::assertFalse($verification->send(new Realm('acme'), $ana->id, Permits::because(new Realm('acme')), new Origin(OriginKind::Web)));
        self::assertFalse($verification->send(new Realm('acme'), $this->principal(null)->id, Permits::because(new Realm('acme')), new Origin(OriginKind::Web)));
        self::assertSame(['ana@example.com'], array_map(static fn ($sent): string => $sent->to, $this->mailer->sent), 'Only the first.');
    }

    #[DataProvider('implementations')]
    public function testALinkLastsADay(string $implementation): void
    {
        $verification = $this->verification($implementation);
        $ana = $this->principal('ana@example.com');
        $verification->send(new Realm('acme'), $ana->id, Permits::because(new Realm('acme')), new Origin(OriginKind::Web));
        $token = self::token($this->mailer->sent[0]->body[1]);

        $this->clock->sleep(86_399);
        self::assertSame('ana@example.com', $verification->peek(new Realm('acme'), $token)?->value);
        $this->clock->sleep(1);
        self::assertNull($verification->peek(new Realm('acme'), $token));
    }

    #[DataProvider('implementations')]
    public function testALinkWorksOnlyForTheAddressItWentToAndOnlyTheNewest(string $implementation): void
    {
        $verification = $this->verification($implementation);
        $ana = $this->principal('ana@example.com');
        $verification->send(new Realm('acme'), $ana->id, Permits::because(new Realm('acme')), new Origin(OriginKind::Web));
        $older = self::token($this->mailer->sent[0]->body[1]);
        $verification->send(new Realm('acme'), $ana->id, Permits::because(new Realm('acme')), new Origin(OriginKind::Web));
        $newer = self::token($this->mailer->sent[1]->body[1]);

        self::assertNull(Permits::call($verification->confirm(...), new VerifyEmail(new Realm('acme'), $older), new Origin(OriginKind::Web)), 'A replaced link worked.');

        Permits::call($this->principals->setIdentifier(...), new SetIdentifier(new Realm('acme'), $ana->id, IdentifierKind::Email, 'ana@example.org'), new Origin(OriginKind::Web));
        self::assertNull(Permits::call($verification->confirm(...), new VerifyEmail(new Realm('acme'), $newer), new Origin(OriginKind::Web)), 'A link verified an address that isn\'t the one it went to.');
        self::assertFalse($this->verified($ana));
    }

    #[DataProvider('implementations')]
    public function testANewAddressThatIsntVerifiedGetsItsLink(string $implementation): void
    {
        $verification = $this->verification($implementation);
        $ana = $this->principal('ana@example.com', verified: true);
        $before = $this->principals->identifiers(new Realm('acme'), $ana->id)['email'];
        $origin = new Origin(OriginKind::Web);

        $after = Permits::call($this->principals->setIdentifier(...), new SetIdentifier(new Realm('acme'), $ana->id, IdentifierKind::Email, 'ana@example.org'), $origin);
        $verification->identifierChanged(new IdentifierChanged($ana->id, $before, $after, $origin, $ana->id, Permits::because(new Realm('acme'))));
        self::assertSame(['ana@example.org'], array_map(static fn ($email): string => $email->to, $this->mailer->sent));

        // A verified address, a username, or no address at all: no link.
        $verified = Permits::call($this->principals->setIdentifier(...), new SetIdentifier(new Realm('acme'), $ana->id, IdentifierKind::Email, 'ana@example.net', verified: true), $origin);
        $username = Permits::call($this->principals->setIdentifier(...), new SetIdentifier(new Realm('acme'), $ana->id, IdentifierKind::Username, 'ana'), $origin);

        foreach ([$verified, $username, null] as $identifier) {
            $verification->identifierChanged(new IdentifierChanged($ana->id, $after, $identifier, $origin, $ana->id, Permits::because(new Realm('acme'))));
        }

        self::assertCount(1, $this->mailer->sent);
    }

    #[DataProvider('implementations')]
    public function testAPrincipalCreatedActiveGetsItsLinkAndAnInvitedOneDoesnt(string $implementation): void
    {
        $verification = $this->verification($implementation);
        $origin = new Origin(OriginKind::Web);

        foreach ([['ana@example.com', PrincipalStatus::Active], ['bo@example.com', PrincipalStatus::Invited], [null, PrincipalStatus::Active]] as [$email, $status]) {
            $created = Permits::call($this->principals->create(...), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, $status, $email), $origin);
            $verification->principalCreated(new PrincipalCreated($created, [], $origin, null, Permits::because(new Realm('acme'))));
        }

        self::assertSame(['ana@example.com'], array_map(static fn ($email): string => $email->to, $this->mailer->sent), 'An invitation verifies the address itself.');
    }

    #[DataProvider('implementations')]
    public function testAMailThatCantGoIsLoggedAndTheChangeStays(string $implementation): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $logged = [];

            /** @var array<mixed> */
            public array $context = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = (string) $message;
                $this->context = $context;
            }
        };
        $verification = $this->verification($implementation, mailer: new class implements Mailer {
            public function send(Email $email): void
            {
                throw new \RuntimeException('The mail server is down.');
            }
        }, logger: $logger);
        $ana = $this->principal('ana@example.com');

        $verification->principalCreated(new PrincipalCreated($ana, [], new Origin(OriginKind::Web), null, Permits::because(new Realm('acme'))));

        self::assertSame(['YorvoID couldn\'t send the link to verify an email address: {message}'], $logger->logged);
        self::assertSame(['realm' => 'acme', 'principal' => (string) $ana->id, 'message' => 'The mail server is down.'], array_diff_key($logger->context, ['exception' => true]));
        self::assertInstanceOf(\RuntimeException::class, $logger->context['exception'] ?? null);
    }

    #[DataProvider('implementations')]
    public function testTheLinkIsInThePrincipalsLanguage(string $implementation): void
    {
        $verification = $this->verification($implementation, 'de');
        $verification->send(new Realm('acme'), $this->principal('ana@example.com')->id, Permits::because(new Realm('acme')), new Origin(OriginKind::Web));

        self::assertSame(['Bestätigen Sie Ihre E-Mail-Adresse für Acme', 'de'], [$this->mailer->sent[0]->subject, $this->mailer->sent[0]->locale]);
        $action = $this->mailer->sent[0]->body[1];
        \assert($action instanceof Action);
        self::assertSame('E-Mail-Adresse bestätigen', $action->label);
    }

    private function verification(string $implementation, string $locale = 'en', ?Mailer $mailer = null, LoggerInterface $logger = new NullLogger()): EmailVerification
    {
        $stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-28 18:45:30 UTC');
        $events = new RecordingDispatcher();
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($stack->log, $stack->transactions, $events, $ids, $this->clock);
        $this->principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions, ids: $ids, clock: $this->clock);
        $tokens = new OneTimeTokens($stack->tokens, $this->principals, $log, $stack->transactions, clock: $this->clock);
        $this->mailer = new InMemoryMailer();

        return new EmailVerification($this->principals, $tokens, $mailer ?? $this->mailer, new Translator([Translations::DIRECTORY]), new NotificationSettings('Acme', 'https://acme.example/not-me?token={token}', static fn (): string => $locale), $stack->transactions, static fn (string $token): string => 'https://acme.example/verify?token='.rawurlencode($token), $logger);
    }

    private function principal(?string $email, bool $verified = false): Principal
    {
        return Permits::call($this->principals->create(...), new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: $email, emailVerified: $verified), new Origin(OriginKind::Console));
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
