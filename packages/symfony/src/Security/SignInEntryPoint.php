<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use YorvoID\Passkeys\Flow\PasskeyFlows;

/**
 * Where the firewall sends someone who isn't signed in, e.g. to a page with #[IsGranted('ROLE_USER')]:
 * a page goes to YorvoID's sign-in, a request for JSON gets 401. Set it as the firewall's entry_point.
 *
 * @api
 */
final readonly class SignInEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ('json' === $request->getContentTypeFormat() || \in_array('application/json', $request->getAcceptableContentTypes(), true)) {
            return new JsonResponse(['error' => 'Sign in first.'], 401);
        }

        return new RedirectResponse($this->urls->generate('yorvoid_flow_start', ['flow' => PasskeyFlows::SIGN_IN]));
    }
}
