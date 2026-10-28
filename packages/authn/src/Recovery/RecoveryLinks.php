<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
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
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Transactions;

/**
 * Recovery links issued by an operator (ATN-033): someone with access to the server (and so the final
 * authority anyway) gives a person who lost access a short-lived link. Opening it ends all of their
 * sessions, since whoever else had one may be why they lost access, starts the cool-down, and starts a
 * new session (ATN-029).
 *
 * A recovery link is a one-time token for the purpose "yorvoid.recovery_link", not bound to an email
 * address: the operator hands it over directly.
 *
 * @api
 */
final readonly class RecoveryLinks
{
    public const string PURPOSE = 'yorvoid.recovery_link';

    public function __construct(
        private OneTimeTokens $tokens,
        private Sessions $sessions,
        private Principals $principals,
        private StepUp $stepUp,
        private Transactions $transactions,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @throws UnknownPrincipal  When there's no such principal
     * @throws InactivePrincipal When it isn't active
     */
    public function issue(IssueRecoveryLink $command, Origin $origin, ?PrincipalId $actor = null): IssuedToken
    {
        $principal = $this->principals->get($command->realm, $command->principalId) ?? throw new UnknownPrincipal($command->realm, $command->principalId);

        if (!$principal->isActive()) {
            throw new InactivePrincipal($principal, 'recover access; activate it first');
        }

        return $this->tokens->issue(new IssueToken($command->realm, $command->principalId, self::PURPOSE, $command->lifetime ?? Duration::minutes(15)), $origin, $actor);
    }

    /**
     * Uses the link: ends all of the principal's sessions, starts the cool-down, and starts a new session.
     *
     * @param string|null $device       As for StartSession
     * @param string|null $address      As for StartSession
     * @param Secret|null $deviceCookie The device cookie the browser sent, if any; the session's result says which to set
     *
     * @return StartedSession|null The new session; null if the link doesn't work
     */
    public function redeem(Realm $realm, #[\SensitiveParameter] Secret $token, Origin $origin, ?string $device = null, ?string $address = null, #[\SensitiveParameter] ?Secret $deviceCookie = null): ?StartedSession
    {
        return $this->transactions->run(function () use ($realm, $token, $origin, $device, $address, $deviceCookie): ?StartedSession {
            $used = $this->tokens->consume($realm, self::PURPOSE, $token, $origin);

            if (null === $used) {
                return null;
            }

            $this->sessions->endAll(new EndSessions($realm, $used->principalId, SessionEndReason::Recovered), $origin, $used->principalId);
            $this->stepUp->startCoolDown($realm, $used->principalId, $origin);

            return $this->sessions->start(new StartSession($realm, $used->principalId, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::RecoveryLink, $this->clock->now())), $device, $address, $deviceCookie), $origin);
        });
    }
}
