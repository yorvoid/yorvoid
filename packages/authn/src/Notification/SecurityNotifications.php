<?php

declare(strict_types=1);

namespace YorvoID\Authn\Notification;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\Translation\TranslatorInterface;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\Event\CredentialAdded;
use YorvoID\Authn\Credential\Event\CredentialRemoved;
use YorvoID\Authn\Recovery\Event\RecoveryCodesGenerated;
use YorvoID\Authn\Recovery\Event\RecoveryCodeUsed;
use YorvoID\Authn\Recovery\Event\RecoveryLinkUsed;
use YorvoID\Authn\Session\Event\NewDeviceSignedIn;
use YorvoID\Authn\Session\UserAgent;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Translations;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Principal\Event\IdentifierChanged;
use YorvoID\Core\Principal\Identifier;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Text;
use YorvoID\Mail\Block\Action;
use YorvoID\Mail\Block\Facts;
use YorvoID\Mail\Block\Footnote;
use YorvoID\Mail\Block\Paragraph;
use YorvoID\Mail\Email;
use YorvoID\Mail\Mailer;

/**
 * Security notifications people can act on (UX-016, ATN-025, ATN-027, ATN-080): an email for every
 * change to how someone signs in, and for every sign-in from a new device, and to the
 * old address when the email address changes or goes. Each says what happened, when
 * and from where, and offers "this wasn't me", a single-use link that signs everyone out and leads to
 * recovery (NotMe).
 *
 * They go to the principal's email address if it's verified, in the principal's language. Register the
 * listeners for the events in events(), which come after the commit. An email that can't be sent is
 * logged as an error, and doesn't fail what it's about.
 *
 * @api
 */
final readonly class SecurityNotifications
{
    /** What the "this wasn't me" token is for; with a credential's ID after it, for a new sign-in method */
    public const string NOT_ME = 'yorvoid.not_me';

    /**
     * The purpose of a "this wasn't me" token, about a sign-in method or not.
     */
    public static function notMePurpose(?CredentialId $credential): string
    {
        return null === $credential ? self::NOT_ME : self::NOT_ME.'.c'.str_replace('-', '', $credential->uuid->toRfc4122());
    }

    public function __construct(
        private Principals $principals,
        private OneTimeTokens $tokens,
        private Mailer $mailer,
        private TranslatorInterface $translator,
        private NotificationSettings $settings,
        private ClockInterface $clock = new NativeClock(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Which method handles which event, for registering them as listeners.
     *
     * @return array<class-string, string>
     */
    public static function events(): array
    {
        return [
            NewDeviceSignedIn::class => 'newDevice',
            CredentialAdded::class => 'credentialAdded',
            CredentialRemoved::class => 'credentialRemoved',
            RecoveryCodeUsed::class => 'recoveryCodeUsed',
            RecoveryCodesGenerated::class => 'recoveryCodesGenerated',
            RecoveryLinkUsed::class => 'recoveryLinkUsed',
            IdentifierChanged::class => 'identifierChanged',
        ];
    }

    public function newDevice(NewDeviceSignedIn $event): void
    {
        $session = $event->session;
        $this->send($session->realm, $session->principalId, 'new_device', [], fn (string $locale): array => [
            'when' => $this->time($session->startedAt, $locale),
            'device' => $this->device($session->device, $locale),
            'address' => $session->address ?? $this->text('unknown', $locale),
        ]);
    }

    public function credentialAdded(CredentialAdded $event): void
    {
        $credential = $event->credential;
        $this->send($credential->realm, $credential->principalId, 'credential_added', [], $this->credentialFacts($credential), $credential);
    }

    public function credentialRemoved(CredentialRemoved $event): void
    {
        $credential = $event->credential;
        $this->send($credential->realm, $credential->principalId, 'credential_removed', [], $this->credentialFacts($credential));
    }

    public function recoveryCodeUsed(RecoveryCodeUsed $event): void
    {
        $this->send($event->realm, $event->principalId, 'recovery_code_used', ['remaining' => $event->remaining], fn (string $locale): array => [
            'when' => $this->time($this->clock->now(), $locale),
        ]);
    }

    public function recoveryLinkUsed(RecoveryLinkUsed $event): void
    {
        $this->send($event->realm, $event->principalId, 'recovery_link_used', [], fn (string $locale): array => [
            'when' => $this->time($this->clock->now(), $locale),
        ]);
    }

    public function recoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->send($event->realm, $event->principalId, 'recovery_codes_generated', ['count' => $event->count], fn (string $locale): array => [
            'when' => $this->time($this->clock->now(), $locale),
        ]);
    }

    /**
     * The email address changed or went: the old one hears of it, if it was verified. Whoever changed it
     * may have taken the account, and the new address, which gets the other notifications now, is theirs.
     */
    public function identifierChanged(IdentifierChanged $event): void
    {
        $old = $event->before;

        if (null === $old || IdentifierKind::Email !== $old->kind || $old->normalized === $event->after?->normalized) {
            return;
        }

        // Its "this wasn't me" link can't be bound to the address, which isn't the principal's any more.
        $this->sendTo($old, null === $event->after ? 'email_removed' : 'email_changed', [], fn (string $locale): array => [
            'when' => $this->time($this->clock->now(), $locale),
        ], current: false);
    }

    /**
     * A signed operation ran (INT-012): for the package that knows the operation, e.g. a listener of
     * yorvoid/passkeys' SignedOperationRan.
     *
     * @param \Closure(string): string $summary The operation as people read it, in the locale given
     */
    public function signedOperation(Realm $realm, PrincipalId $principalId, \Closure $summary, \DateTimeImmutable $at): void
    {
        $this->send($realm, $principalId, 'signed_operation', [], fn (string $locale): array => [
            'operation' => $summary($locale),
            'when' => $this->time($at, $locale),
        ]);
    }

    /**
     * @return \Closure(string): array<string, string>
     */
    /**
     * The browser and system, from short lists: never the User-Agent, which whoever signed in wrote.
     */
    private function device(?string $userAgent, string $locale): string
    {
        [$browser, $system] = UserAgent::describe($userAgent);

        return match (true) {
            null !== $browser && null !== $system => $this->text('device', $locale, ['browser' => $browser, 'system' => $system]),
            default => $browser ?? $system ?? $this->text('unknown', $locale),
        };
    }

    private function credentialFacts(Credential $credential): \Closure
    {
        return fn (string $locale): array => [
            'method' => $this->text('kind.'.$credential->kind->value, $locale),
            // Whoever added it chose it: on one line, and quoted, so it reads as a name, not as the email's words.
            'name' => $this->text('quoted', $locale, ['text' => Text::line($credential->label)]),
            'when' => $this->time($credential->createdAt, $locale),
        ];
    }

    /**
     * @param string                                  $notice     Which notification, e.g. "new_device"
     * @param array<string, int|string>               $parameters For its body
     * @param \Closure(string): array<string, string> $facts      By name, in the locale given
     * @param Credential|null                         $suspect    A sign-in method that "this wasn't me" disables
     */
    private function send(Realm $realm, PrincipalId $principalId, string $notice, array $parameters, \Closure $facts, ?Credential $suspect = null): void
    {
        $email = $this->principals->identifiers($realm, $principalId)[IdentifierKind::Email->value] ?? null;

        if (null !== $email) {
            $this->sendTo($email, $notice, $parameters, $facts, $suspect);
        }
    }

    /**
     * @param array<string, int|string>               $parameters
     * @param \Closure(string): array<string, string> $facts
     * @param bool                                    $current    Whether it's the principal's address now, which the link's token is then bound to
     */
    private function sendTo(Identifier $email, string $notice, array $parameters, \Closure $facts, ?Credential $suspect = null, bool $current = true): void
    {
        if (null === $email->verifiedAt) {
            return;
        }

        $realm = $email->realm;
        $principalId = $email->principalId;
        $locale = $this->settings->localeOf($realm, $principalId);
        $application = ['application' => $this->settings->applicationName];
        $issued = $this->tokens->issue(new IssueToken($realm, $principalId, self::notMePurpose($suspect?->id), $this->settings->notMeLifetime, $current ? $email->value : null, newestOnly: false), new Origin(OriginKind::System, 'security notification'));
        $url = strtr($this->settings->notMeUrl, ['{token}' => rawurlencode($issued->secret->reveal()), '{credential}' => null === $suspect ? '' : $suspect->id->uuid->toRfc4122()]);
        $labelled = [];

        foreach ($facts($locale) as $name => $value) {
            $labelled[$this->text('fact.'.$name, $locale)] = $value;
        }

        $sending = new Email($email->value, $this->text($notice.'.subject', $locale, $application), [
            new Paragraph($this->text($notice.'.body', $locale, [...$application, ...$parameters])),
            new Facts($labelled),
            new Paragraph($this->text(null === $suspect ? 'not_me.explanation' : 'not_me.explanation_method', $locale)),
            new Action($this->text('not_me', $locale), $url),
            new Footnote($this->text('footnote', $locale, $application)),
        ], $locale);

        try {
            $this->mailer->send($sending);
        } catch (\Exception $exception) {
            // What happened stays done: a mail outage doesn't fail the sign-in or the change it's about.
            $this->logger->error('YorvoID couldn\'t send the "{notice}" notification: {message}', ['notice' => $notice, 'realm' => $realm->name, 'principal' => (string) $principalId, 'message' => $exception->getMessage(), 'exception' => $exception]);
        }
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function text(string $id, string $locale, array $parameters = []): string
    {
        return $this->translator->trans('yorvoid.notification.'.$id, $parameters, Translations::DOMAIN, $locale);
    }

    private function time(\DateTimeImmutable $at, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT, 'UTC');

        return $formatter->format($at).' UTC';
    }
}
