<?php

declare(strict_types=1);

namespace YorvoID\E2e\Acme;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use YorvoID\Authn\Session\Session;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Symfony\Flow\FlowContexts;
use YorvoID\Symfony\Security\CurrentSession;

/**
 * Acme Team, the small application around YorvoID's flows for the usability test (M2-10): a home page,
 * projects to remove, and an account whose email address changes. Its refusals are YorvoID's refusal
 * pages: nothing here catches NotPermitted.
 */
final readonly class AcmeController
{
    public function __construct(
        private CurrentSession $current,
        private Principals $principals,
        private Operations $operations,
        private Projects $projects,
        private FlowContexts $contexts,
        private CsrfTokenManagerInterface $csrf,
        private Environment $twig,
    ) {
    }

    public function home(): Response
    {
        $session = $this->current->get();

        return $this->page('home', ['email' => null === $session ? null : $this->email($session)]);
    }

    public function projects(Request $request): Response
    {
        $session = $this->current->get();

        if (null === $session) {
            return self::signIn();
        }

        return $this->page('projects', ['email' => $this->email($session), 'projects' => $this->projects->all(), 'removed' => $request->query->getString('removed')]);
    }

    public function remove(Request $request, string $project): Response
    {
        $session = $this->signedIn($request);
        $name = $this->projects->all()[$project] ?? throw new NotFoundHttpException('There\'s no such project.');
        $this->operations->run(new RemoveProject($session->realm, $project), $session, $this->origin($request));

        return new RedirectResponse('/projects?'.http_build_query(['removed' => $name]), 303);
    }

    public function account(Request $request): Response
    {
        $session = $this->current->get();

        if (null === $session) {
            return self::signIn();
        }

        return $this->page('account', ['email' => $this->email($session), 'changed' => $request->query->has('changed')]);
    }

    public function changeEmail(Request $request): Response
    {
        $session = $this->signedIn($request);
        $this->operations->run(new SetIdentifier($session->realm, $session->principalId, IdentifierKind::Email, $request->getPayload()->getString('email')), $session, $this->origin($request));

        return new RedirectResponse('/account?changed=1', 303);
    }

    /**
     * The session of a form's request, with the form's token checked.
     */
    private function signedIn(Request $request): Session
    {
        if (!$this->csrf->isTokenValid(new CsrfToken('acme', $request->getPayload()->getString('_token')))) {
            throw new AccessDeniedHttpException('The form\'s token isn\'t valid: reload the page and try again.');
        }

        return $this->current->get() ?? throw new AccessDeniedHttpException('Sign in first.');
    }

    private function origin(Request $request): Origin
    {
        return $this->contexts->of($request)->origin;
    }

    private function email(Session $session): ?string
    {
        return $this->principals->identifiers($session->realm, $session->principalId)[IdentifierKind::Email->value]->value ?? null;
    }

    private static function signIn(): Response
    {
        return new RedirectResponse('/yorvoid/flows/yorvoid.sign_in', 303);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function page(string $name, array $parameters): Response
    {
        return new Response($this->twig->render('acme/'.$name.'.html.twig', $parameters));
    }
}
