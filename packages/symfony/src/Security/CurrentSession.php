<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\Sessions;
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
    /** @var \WeakMap<Request, Session|null> */
    private \WeakMap $checked;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly Sessions $sessions,
        private readonly Realm $realm,
        private readonly string $key = 'yorvoid_session',
    ) {
        $this->checked = new \WeakMap();
    }

    /**
     * The YorvoID session of the current request, if it's still valid.
     */
    public function get(): ?Session
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        if (!$this->checked->offsetExists($request)) {
            $token = $this->token($request);
            $this->checked[$request] = null === $token ? null : $this->sessions->validate($this->realm, $token);
        }

        return $this->checked[$request];
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
     * Keeps a session's token in the PHP session, under a new PHP session ID (SES-002).
     */
    public function hold(#[\SensitiveParameter] Secret $token): void
    {
        $request = $this->requests->getMainRequest() ?? throw new \LogicException('There is no request to hold a session in.');
        $request->getSession()->migrate(true);
        $request->getSession()->set($this->key, $token->reveal());
        $this->checked = new \WeakMap();
    }

    /**
     * Forgets the session's token, e.g. after signing out.
     */
    public function forget(): void
    {
        $request = $this->requests->getMainRequest();

        if (null !== $request && $request->hasSession()) {
            $request->getSession()->remove($this->key);
        }

        $this->checked = new \WeakMap();
    }

    public function reset(): void
    {
        $this->checked = new \WeakMap();
    }

    private function token(Request $request): ?Secret
    {
        // Only a PHP session the browser already has: checking mustn't start one for every visitor.
        $token = $request->hasPreviousSession() ? $request->getSession()->get($this->key) : null;

        return \is_string($token) ? Secret::fromString($token) : null;
    }
}
