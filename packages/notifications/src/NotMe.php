<?php

declare(strict_types=1);

namespace YorvoID\Notifications;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Session\EndSessions;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Notifications\Event\NotMeReported;

/**
 * "This wasn't me" from a security notification (UX-016): the link works once, signs everyone out of
 * the principal's account, and disables the sign-in method the notification was about, if any, so
 * whoever added it can't sign in with it. Then lead the person to recovery: their recovery codes, or a
 * recovery link.
 *
 * @api
 */
final readonly class NotMe implements OperationHandler
{
    public function __construct(
        private OneTimeTokens $tokens,
        private Sessions $sessions,
        private Credentials $credentials,
        private SecurityLog $log,
        private Transactions $transactions,
        private EventDispatcherInterface $events = new NoEventListeners(),
    ) {
    }

    public function operations(): array
    {
        return [ReportNotMe::class => $this->confirm(...)];
    }

    /**
     * @return PrincipalId|null Whose account it is; null if the link doesn't work
     */
    public function confirm(ReportNotMe $command, Permit $permit, Origin $origin): ?PrincipalId
    {
        $permit->use($command);
        [$realm, $token, $credential] = [$command->realm, $command->token, $command->credential];
        $suspect = null === $credential || '' === $credential ? null : (Uuid::isValid($credential) ? CredentialId::fromString($credential) : false);

        if (false === $suspect) {
            return null;
        }

        return $this->transactions->run(function () use ($realm, $token, $suspect, $permit, $origin): ?PrincipalId {
            $used = $this->tokens->consume($realm, SecurityNotifications::notMePurpose($suspect), $token, $origin);

            if (null === $used) {
                return null;
            }

            $disabled = null === $suspect ? null : $this->credentials->get($realm, $suspect);

            if (null !== $disabled && $disabled->principalId->equals($used->principalId)) {
                $this->credentials->disable($disabled, 'its principal said they didn\'t add it', $origin);
            }

            $permit->cause($this->sessions->endAll(...), new EndSessions($realm, $used->principalId, SessionEndReason::Reported), $origin, new ActingPrincipal($realm, $used->principalId));
            $this->log->record($realm, 'yorvoid.notification.not_me', Outcome::Success, $origin, $used->principalId, 'principal', (string) $used->principalId, null === $suspect ? [] : ['credential' => (string) $suspect]);

            $this->transactions->afterCommit(function () use ($realm, $used, $suspect, $origin): void {
                $this->events->dispatch(new NotMeReported($realm, $used->principalId, $suspect, $origin));
            });

            return $used->principalId;
        });
    }
}
