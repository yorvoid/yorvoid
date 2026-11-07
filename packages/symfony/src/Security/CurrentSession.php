<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Service\ResetInterface;
use YorvoID\Authn\Session\EndSession;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\ValidSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * The request's YorvoID session (SES-008): the application's PHP session holds only its token, and
 * each request checks it against YorvoID's (SES-005), once.
 *
 * @api
 */
final class CurrentSession implements ResetInterface
{
    /**
     * Where the PHP session keeps the YorvoID session's token.
     */
    public const string KEY = 'yorvoid_session';

    /** @var \WeakMap<Request, ValidSession|null> */
    private \WeakMap $checked;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly Sessions $sessions,
        private readonly Operations $operations,
        private readonly Realm $realm,
        private readonly string $key = self::KEY,
        private readonly ?TokenStorageInterface $tokens = null,
    ) {
        $this->checked = new \WeakMap();
    }

    /**
     * The YorvoID session of the current request, if it's still valid.
     */
    public function get(): ?Session
    {
        return $this->valid()?->session;
    }

    /**
     * The principal of the request's YorvoID session, as the session's check loaded it: the firewall's
     * user provider needn't load it again (PERF-07).
     */
    public function principal(): ?Principal
    {
        return $this->valid()?->principal;
    }

    /**
     * Whether the request's PHP session holds a YorvoID session's token, valid or not.
     */
    public function isHeld(): bool
    {
        $request = $this->requests->getCurrentRequest();

        return null !== $request && null !== $this->token($request);
    }

    /**
     * Keeps a session's token in the PHP session, under a new PHP session ID (SES-002). A session the PHP
     * session held before ends, unless it's the same one with a new token, after a step-up (V7.2.4).
     */
    public function hold(#[\SensitiveParameter] Secret $token, Origin $origin = new Origin(OriginKind::Web)): void
    {
        $request = $this->requests->getMainRequest() ?? throw new \LogicException('There is no request to hold a session in.');
        $previous = $this->get();
        $held = null === $previous ? null : $this->sessions->validate($this->realm, $token);
        $same = null !== $previous && null !== $held && $previous->id->equals($held->id);

        if (null !== $previous && !$same) {
            $this->operations->run(new EndSession($previous->realm, $previous->id, SessionEndReason::Replaced), $previous, $origin);
        }

        // After a step-up the old PHP session stays, holding the old token, which no longer works: a request
        // of another tab already waiting on it then doesn't delete the new session's cookie (SES-08).
        $request->getSession()->migrate(!$same);
        $request->getSession()->set($this->key, $token->reveal());
        $this->checked = new \WeakMap();
    }

    /**
     * Signs the person out, when you do it yourself rather than through the firewall's logout: ends the
     * YorvoID session, empties the PHP session under a new ID, and clears the firewall's token, so no
     * firewall stays signed in, and a copy of the old session cookie is no use.
     */
    public function signOut(Origin $origin = new Origin(OriginKind::Web)): void
    {
        $this->end($origin);
        $request = $this->requests->getMainRequest();

        if (null !== $request && $request->hasSession()) {
            $request->getSession()->invalidate();
        }

        $this->tokens?->setToken(null);
        $this->checked = new \WeakMap();
    }

    /**
     * For the firewall's logout, which empties the PHP session itself unless the application keeps its
     * data in it (invalidate_session: false): ends the YorvoID session, and takes its token and every
     * firewall's token out of the PHP session, under a new ID.
     *
     * @internal
     */
    public function signOutKeepingData(Origin $origin): void
    {
        $this->end($origin);
        $request = $this->requests->getMainRequest();

        if (null !== $request && $request->hasSession()) {
            self::signOutOf($request->getSession(), $this->key);
        }

        $this->checked = new \WeakMap();
    }

    public function reset(): void
    {
        $this->checked = new \WeakMap();
    }

    private function end(Origin $origin): void
    {
        $session = $this->get();

        if (null !== $session) {
            $this->operations->run(new EndSession($session->realm, $session->id, SessionEndReason::SignedOut), $session, $origin);
        }
    }

    private static function signOutOf(SessionInterface $session, string $key): void
    {
        foreach (array_keys($session->all()) as $name) {
            // Every firewall keeps its token under "_security_" and its context's name.
            if ($key === $name || str_starts_with($name, '_security_')) {
                $session->remove($name);
            }
        }

        $session->migrate(true);
    }

    /**
     * The request's session and principal, checked once a request.
     */
    private function valid(): ?ValidSession
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        if (!$this->checked->offsetExists($request)) {
            $token = $this->token($request);
            $this->checked[$request] = null === $token ? null : $this->sessions->check($this->realm, $token);
        }

        return $this->checked[$request];
    }

    private function token(Request $request): ?Secret
    {
        // Only a PHP session the browser already has: checking mustn't start one for every visitor.
        $token = $request->hasPreviousSession() ? $request->getSession()->get($this->key) : null;

        return \is_string($token) ? Secret::fromString($token) : null;
    }
}
