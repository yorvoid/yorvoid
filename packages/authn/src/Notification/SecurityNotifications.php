<?php

declare(strict_types=1);

namespace YorvoID\Authn\Notification;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\Translation\TranslatorInterface;
use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\Event\CredentialAdded;
use YorvoID\Authn\Credential\Event\CredentialRemoved;
use YorvoID\Authn\Recovery\Event\RecoveryCodesGenerated;
use YorvoID\Authn\Recovery\Event\RecoveryCodeUsed;
use YorvoID\Authn\Session\Event\NewDeviceSignedIn;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Translations;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Mail\Block\Action;
use YorvoID\Mail\Block\Facts;
use YorvoID\Mail\Block\Footnote;
use YorvoID\Mail\Block\Paragraph;
use YorvoID\Mail\Email;
use YorvoID\Mail\Mailer;

/**
 * Security notifications people can act on (UX-016, ATN-025, ATN-027, ATN-080): an email for every
 * change to how someone signs in, and for every sign-in from a new device. Each says what happened, when
 * and from where, and offers "this wasn't me", a single-use link that signs everyone out and leads to
 * recovery (NotMe).
 *
 * They go to the principal's email address if it's verified, in the principal's language. Register the
 * listeners for the events in events(), which come after the commit.
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
        ];
    }

    public function newDevice(NewDeviceSignedIn $event): void
    {
        $session = $event->session;
        $this->send($session->realm, $session->principalId, 'new_device', [], fn (string $locale): array => [
            'when' => $this->time($session->startedAt, $locale),
            'device' => $session->device ?? $this->text('unknown', $locale),
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

    public function recoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->send($event->realm, $event->principalId, 'recovery_codes_generated', ['count' => $event->count], fn (string $locale): array => [
            'when' => $this->time($this->clock->now(), $locale),
        ]);
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
    private function credentialFacts(Credential $credential): \Closure
    {
        return fn (string $locale): array => [
            'method' => $this->text('kind.'.$credential->kind->value, $locale),
            'name' => $credential->label,
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

        if (null === $email || null === $email->verifiedAt) {
            return;
        }

        $locale = $this->settings->localeOf($realm, $principalId);
        $application = ['application' => $this->settings->applicationName];
        $issued = $this->tokens->issue(new IssueToken($realm, $principalId, self::notMePurpose($suspect?->id), $this->settings->notMeLifetime, $email->value, newestOnly: false), new Origin(OriginKind::System, 'security notification'));
        $url = strtr($this->settings->notMeUrl, ['{token}' => rawurlencode($issued->secret->reveal()), '{credential}' => null === $suspect ? '' : $suspect->id->uuid->toRfc4122()]);
        $labelled = [];

        foreach ($facts($locale) as $name => $value) {
            $labelled[$this->text('fact.'.$name, $locale)] = $value;
        }

        $this->mailer->send(new Email($email->value, $this->text($notice.'.subject', $locale, $application), [
            new Paragraph($this->text($notice.'.body', $locale, [...$application, ...$parameters])),
            new Facts($labelled),
            new Paragraph($this->text(null === $suspect ? 'not_me.explanation' : 'not_me.explanation_method', $locale)),
            new Action($this->text('not_me', $locale), $url),
            new Footnote($this->text('footnote', $locale, $application)),
        ], $locale));
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
