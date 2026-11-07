<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Recovery\Event\RecoveryLinkUsed;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSessions;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\Token\IssuedToken;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Transactions;

/**
 * Recovery links issued by an operator (ATN-033): someone with access to the server (and so the final
 * authority anyway) gives a person who lost access a short-lived link. Opening it ends all of their
 * sessions, since whoever else had one may be why they lost access, starts the cool-down, and starts a
 * new session (ATN-029). Each use is announced with RecoveryLinkUsed, for a notification.
 *
 * A recovery link is a one-time token for the purpose "yorvoid.recovery_link", not bound to an email
 * address: the operator hands it over directly.
 *
 * @api
 */
final readonly class RecoveryLinks implements OperationHandler
{
    public const string PURPOSE = 'yorvoid.recovery_link';

    public function __construct(
        private OneTimeTokens $tokens,
        private Sessions $sessions,
        private Principals $principals,
        private StepUp $stepUp,
        private Transactions $transactions,
        private ClockInterface $clock = new NativeClock(),
        private EventDispatcherInterface $events = new NoEventListeners(),
    ) {
    }

    public function operations(): array
    {
        return [
            IssueRecoveryLink::class => $this->issue(...),
            UseRecoveryLink::class => $this->redeem(...),
        ];
    }

    /**
     * @throws UnknownPrincipal  When there's no such principal
     * @throws InactivePrincipal When it isn't active
     */
    public function issue(IssueRecoveryLink $command, Permit $permit, Origin $origin): IssuedToken
    {
        $permit->use($command);
        $principal = $this->principals->get($command->realm, $command->principalId) ?? throw new UnknownPrincipal($command->realm, $command->principalId);

        if (!$principal->isActive()) {
            throw new InactivePrincipal($principal, 'recover access; activate it first');
        }

        return $permit->cause($this->tokens->issue(...), new IssueToken($command->realm, $command->principalId, self::PURPOSE, $command->lifetime ?? Duration::minutes(15)), $origin);
    }

    /**
     * Whose the link is, without using it: to name the account on the page that confirms, so nobody is
     * signed in to someone else's account by a link they were sent (login CSRF).
     *
     * @return Principal|null The principal; null if the link doesn't work, or its principal isn't active
     */
    public function peek(Realm $realm, #[\SensitiveParameter] Secret $token): ?Principal
    {
        $link = $this->tokens->peek($realm, self::PURPOSE, $token);
        $principal = null === $link ? null : $this->principals->get($realm, $link->principalId);

        return true === $principal?->isActive() ? $principal : null;
    }

    /**
     * Uses the link: ends all of the principal's sessions, starts the cool-down, and starts a new session,
     * acting as the principal.
     *
     * @return StartedSession|null The new session; null if the link doesn't work
     */
    public function redeem(UseRecoveryLink $command, Permit $permit, Origin $origin): ?StartedSession
    {
        $permit->use($command);

        return $this->transactions->run(function () use ($command, $permit, $origin): ?StartedSession {
            $realm = $command->realm;
            $used = $this->tokens->consume($realm, self::PURPOSE, $command->token, $origin);

            if (null === $used) {
                return null;
            }

            $recovered = new ActingPrincipal($realm, $used->principalId);
            $permit->cause($this->sessions->endAll(...), new EndSessions($realm, $used->principalId, SessionEndReason::Recovered), $origin, $recovered);
            $this->stepUp->startCoolDown($realm, $used->principalId, $origin);

            $this->transactions->afterCommit(function () use ($realm, $used, $origin): void {
                $this->events->dispatch(new RecoveryLinkUsed($realm, $used->principalId, $origin));
            });

            return $permit->cause($this->sessions->start(...), new StartSession($realm, $used->principalId, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::RecoveryLink, $this->clock->now())), $command->device, $command->address, $command->deviceCookie), $origin, $recovered);
        });
    }
}
