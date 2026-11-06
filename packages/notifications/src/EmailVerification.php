<?php

declare(strict_types=1);

namespace YorvoID\Notifications;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\Event\IdentifierChanged;
use YorvoID\Core\Principal\Event\PrincipalCreated;
use YorvoID\Core\Principal\Identifier;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\VerifyIdentifier;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Mail\Block\Action;
use YorvoID\Mail\Block\Footnote;
use YorvoID\Mail\Block\Paragraph;
use YorvoID\Mail\Email;
use YorvoID\Mail\Mailer;

/**
 * Verifies an email address by a link sent to it. Security notifications go only to a verified address,
 * so an owner who claimed the installation, or someone who changed their address, hears nothing until
 * they open it. The link's token works once, for a day, and only while the address is still theirs;
 * a newer link replaces an older one.
 *
 * Register identifierChanged() and principalCreated() as listeners of IdentifierChanged and
 * PrincipalCreated: a new address that isn't verified then gets its link at once, as a consequence of the
 * change.
 *
 * @api
 */
final readonly class EmailVerification implements OperationHandler
{
    /** What the link's token is for */
    public const string PURPOSE = 'yorvoid.email_verification';

    /**
     * @param \Closure(string): string $link The page that confirms the address, given the token: e.g.
     *                                       fn (string $token): string => 'https://example.com/verify-email?token='.rawurlencode($token)
     */
    public function __construct(
        private Principals $principals,
        private OneTimeTokens $tokens,
        private Mailer $mailer,
        private TranslatorInterface $translator,
        private NotificationSettings $settings,
        private Transactions $transactions,
        private \Closure $link,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function operations(): array
    {
        return [VerifyEmail::class => $this->confirm(...)];
    }

    /**
     * Sends a link to the principal's email address, unless there's none or it's verified, as a
     * consequence of the operation that set it.
     *
     * @return bool Whether one was sent
     */
    public function send(Realm $realm, PrincipalId $principalId, Permit $because, Origin $origin): bool
    {
        $email = $this->principals->identifiers($realm, $principalId)[IdentifierKind::Email->value] ?? null;

        if (null === $email || $email->isVerified()) {
            return false;
        }

        $issued = $because->cause($this->tokens->issue(...), new IssueToken($realm, $principalId, self::PURPOSE, Duration::days(1), $email->value), $origin);
        $locale = $this->settings->localeOf($realm, $principalId);
        $application = ['application' => $this->settings->applicationName];

        $this->mailer->send(new Email($email->value, $this->text('subject', $locale, $application), [
            new Paragraph($this->text('body', $locale, [...$application, 'email' => $email->value])),
            new Action($this->text('action', $locale), ($this->link)($issued->secret->reveal())),
            new Footnote($this->text('footnote', $locale, $application)),
        ], $locale));

        return true;
    }

    /**
     * What the link is for, without using it: for the page that asks the person to confirm.
     */
    public function peek(Realm $realm, #[\SensitiveParameter] Secret $token): ?Identifier
    {
        $working = $this->tokens->peek($realm, self::PURPOSE, $token);

        return null === $working ? null : $this->principals->identifiers($realm, $working->principalId)[IdentifierKind::Email->value] ?? null;
    }

    /**
     * Uses the link: the address it was sent to is verified, if it's still the principal's, acting as them.
     *
     * @return Identifier|null The address, verified; null if the link doesn't work (used, expired, replaced, or the address changed)
     */
    public function confirm(VerifyEmail $command, Permit $permit, Origin $origin): ?Identifier
    {
        $permit->use($command);

        return $this->transactions->run(function () use ($command, $permit, $origin): ?Identifier {
            $realm = $command->realm;
            $used = $this->tokens->consume($realm, self::PURPOSE, $command->token, $origin);

            if (null === $used || null === $used->email) {
                return null;
            }

            return $permit->cause($this->principals->verifyIdentifier(...), new VerifyIdentifier($realm, $used->principalId, IdentifierKind::Email, $used->email), $origin, new ActingPrincipal($realm, $used->principalId));
        });
    }

    /**
     * A new email address that isn't verified gets its link.
     */
    public function identifierChanged(IdentifierChanged $event): void
    {
        $new = $event->after;

        // send() passes over a verified address.
        if (null === $new || IdentifierKind::Email !== $new->kind) {
            return;
        }

        $this->sendAfter($new->realm, $new->principalId, $event->permit, $event->origin);
    }

    /**
     * A principal created active with an email address that isn't verified, e.g. the owner claiming the
     * installation, gets its link. An invited one doesn't: its invitation verifies the address.
     */
    public function principalCreated(PrincipalCreated $event): void
    {
        if ($event->principal->isActive()) {
            $this->sendAfter($event->principal->realm, $event->principal->id, $event->permit, $event->origin);
        }
    }

    private function sendAfter(Realm $realm, PrincipalId $principalId, Permit $because, Origin $origin): void
    {
        try {
            $this->send($realm, $principalId, $because, $origin);
        } catch (\Exception $exception) {
            // The change happened: a mail outage doesn't undo that. The person asks for the link again.
            $this->logger->error('YorvoID couldn\'t send the link to verify an email address: {message}', ['realm' => $realm->name, 'principal' => (string) $principalId, 'message' => $exception->getMessage(), 'exception' => $exception]);
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function text(string $id, string $locale, array $parameters = []): string
    {
        return $this->translator->trans('yorvoid.notification.verify_email.'.$id, $parameters, Translations::DOMAIN, $locale);
    }
}
