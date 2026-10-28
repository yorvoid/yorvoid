<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security\Authenticator;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\Json;
use YorvoID\Symfony\Security\PrincipalUserProvider;
use YorvoID\Throttle\Throttled;

/**
 * What YorvoID's authenticators share (PLT-010): each handles one route of YorvoID's, starts a
 * YorvoID session, keeps only its token in the PHP session (SES-008), sets the device cookie, and
 * answers in JSON. Put them in your own firewall; YorvoID defines none.
 *
 * @internal
 */
abstract class YorvoIDAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly CurrentSession $current,
        private readonly PrincipalUserProvider $users,
        protected readonly Realm $realm,
        private readonly string $deviceCookie,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $this->route() === $request->attributes->get('_route') && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $body = Json::body($request) ?? throw new SignInRefused('Send the request as JSON.', 415);
        $cookie = $request->cookies->get($this->deviceCookie);

        try {
            $started = $this->signIn($body, new Origin(OriginKind::Web, $this->route()), Json::device($request), $request->getClientIp(), \is_string($cookie) && '' !== $cookie ? Secret::fromString($cookie) : null);
        } catch (Throttled $exception) {
            throw new SignInRefused($exception->getMessage(), 429, $exception->seconds, $exception);
        } catch (InactivePrincipal $exception) {
            throw new SignInRefused('This account can\'t sign in.', 401, null, $exception);
        }

        $request->attributes->set('_yorvoid_started', $started);

        return new SelfValidatingPassport(new UserBadge((string) $started->session->principalId, $this->users->loadUserByIdentifier(...)));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $started = $request->attributes->get('_yorvoid_started');
        \assert($started instanceof StartedSession);
        $this->current->hold($started->token);
        $response = new JsonResponse(['signed_in' => true]);

        if (null !== $started->deviceCookie) {
            $response->headers->setCookie(Cookie::create($this->deviceCookie, $started->deviceCookie->reveal(), new \DateTimeImmutable('+400 days'), '/', secure: true, httpOnly: true, sameSite: Cookie::SAMESITE_LAX));
        }

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $refused = $exception instanceof SignInRefused ? $exception : null;
        $response = new JsonResponse(['error' => $exception->getMessageKey()] + ($refused->details ?? []), $refused->status ?? 401);

        if (null !== $refused?->retryAfter) {
            $response->headers->set('Retry-After', (string) $refused->retryAfter);
        }

        return $response;
    }

    /**
     * The route this authenticator handles, e.g. "yorvoid_sign_in".
     */
    abstract protected function route(): string;

    /**
     * Checks what the person sent, and starts their session.
     *
     * @param array<mixed> $body
     *
     * @throws SignInRefused     With the message to show
     * @throws Throttled
     * @throws InactivePrincipal
     */
    abstract protected function signIn(array $body, Origin $origin, ?string $device, ?string $address, ?Secret $deviceCookie): StartedSession;
}
