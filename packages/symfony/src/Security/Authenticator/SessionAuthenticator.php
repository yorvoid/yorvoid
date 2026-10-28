<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security\Authenticator;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\PrincipalUserProvider;

/**
 * Signs the person in to the firewall from their YorvoID session: the one a flow started, in the
 * request after. The YorvoID session decides who is signed in; the firewall follows it (SES-008).
 * Put it in your firewall with YorvoID's other authenticators.
 *
 * @api
 */
final class SessionAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly CurrentSession $current,
        private readonly TokenStorageInterface $tokens,
        private readonly PrincipalUserProvider $users,
    ) {
    }

    /**
     * When the request has a valid YorvoID session and the firewall has no one signed in.
     */
    public function supports(Request $request): bool
    {
        return null === $this->tokens->getToken() && null !== $this->current->get();
    }

    public function authenticate(Request $request): Passport
    {
        $session = $this->current->get();
        \assert(null !== $session);

        return new SelfValidatingPassport(new UserBadge((string) $session->principalId, $this->users->loadUserByIdentifier(...)));
    }

    /**
     * The request goes on, signed in.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * The request goes on, signed out.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
