<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Controller;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Realm;
use YorvoID\Flows\Flows;
use YorvoID\Flows\FlowStatus;
use YorvoID\Flows\Input;
use YorvoID\Flows\Problem;
use YorvoID\Flows\Progress;
use YorvoID\Passkeys\Flow\InvitationStep;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Passkeys\Flow\RecoveryLinkStep;
use YorvoID\Symfony\Flow\FlowContexts;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Templates\FlowPages;

/**
 * Flows over HTTP: the browser client's JSON, or the reference templates' pages. A step's page is
 * shown at its own address, and forms post to the step they're for; after an answer the browser is
 * sent on to the next step's page (303), so reloading doesn't post again.
 *
 * Only these flows start from a request, with only these inputs; the others, like confirming a signed
 * operation, are started by the application, which then sends the person to the step's page.
 *
 * @internal
 */
final readonly class FlowController
{
    /** The flows a request may start, and the inputs it may give them */
    private const array ENTRANCES = [
        PasskeyFlows::SIGN_IN => [],
        PasskeyFlows::SETUP => [],
        PasskeyFlows::RECOVER_WITH_CODE => [],
        PasskeyFlows::ADD_PASSKEY => [],
        PasskeyFlows::INVITATION => ['token' => InvitationStep::TOKEN],
        PasskeyFlows::RECOVER_WITH_LINK => ['token' => RecoveryLinkStep::TOKEN],
        PasskeyFlows::STEP_UP => ['operation' => 'step_up.operation', 'multi_factor' => 'step_up.multi_factor'],
    ];

    /**
     * @param array<string, string> $completed Where to go when a flow completes, by flow; "default" for the others
     */
    public function __construct(
        private Flows $flows,
        private FlowContexts $contexts,
        private CurrentSession $current,
        private TokenStorageInterface $tokens,
        private Realm $realm,
        private FlowPages $pages,
        private UrlGeneratorInterface $urls,
        private array $completed,
        private string $deviceCookie,
    ) {
    }

    public function start(Request $request, string $flow): Response
    {
        $entrance = self::ENTRANCES[$flow] ?? throw new NotFoundHttpException(\sprintf('The flow "%s" doesn\'t start from a request.', $flow));
        $given = $request->isMethod('POST') ? $this->json($request) : $request->query->all();
        $data = [];

        foreach ($entrance as $field => $key) {
            $value = $given[$field] ?? null;

            if ('step_up.multi_factor' === $key) {
                $data[$key] = true === $value || '1' === $value;
            } elseif (\is_string($value)) {
                $data[$key] = $value;
            }
        }

        if (\is_string($data['step_up.operation'] ?? null) && (\strlen($data['step_up.operation']) > 191 || 1 !== preg_match(Operation::NAME, $data['step_up.operation']))) {
            throw new BadRequestHttpException('That isn\'t an operation\'s name.');
        }

        return $this->answer($request, $this->flows->start($flow, $this->realm, $this->contexts->of($request), $data), true);
    }

    public function show(Request $request, string $flow, string $id): Response
    {
        $progress = $this->flows->resume($id, $this->contexts->of($request));

        return null === $progress || $flow !== $progress->state->flow ? $this->expired($request, $flow) : $this->answer($request, $progress, false);
    }

    public function submit(Request $request, string $flow, string $id, string $step): Response
    {
        $context = $this->contexts->of($request);
        $current = $this->flows->resume($id, $context);

        if (null === $current || $flow !== $current->state->flow) {
            return $this->expired($request, $flow);
        }

        $input = $this->wantsJson($request) ? $this->json($request) : $request->request->all();
        $progress = $this->flows->submit($id, $step, new Input($input), $context);
        \assert(null !== $progress);

        return $this->answer($request, $progress, true);
    }

    /**
     * Holds what the flow signed in, then answers: JSON, a page, or a redirect.
     *
     * @param bool $moved Whether the request moved the flow on, so the browser goes to where it is now
     */
    private function answer(Request $request, Progress $progress, bool $moved): Response
    {
        if (null !== $progress->signedIn) {
            // The firewall signs in from the new session, in the next request; not as whoever it had before.
            $this->tokens->setToken(null);
        }

        $token = $progress->signedIn->token ?? $progress->renewedToken;

        if (null !== $token) {
            $this->current->hold($token);
        }

        $flow = $progress->state->flow;
        $completed = $this->completed[$flow] ?? $this->completed['default'] ?? '/';
        $status = match (true) {
            FlowStatus::Failed === $progress->status => 410,
            null !== $progress->retryAfter => 429,
            [] !== $progress->problems => 422,
            default => 200,
        };

        $response = match (true) {
            $this->wantsJson($request) => new JsonResponse([
                'flow' => $flow,
                'id' => $progress->state->id,
                'status' => $progress->status->value,
                'step' => $progress->step,
                'view' => $progress->view,
                'problems' => $this->pages->problems(null === $progress->failure ? $progress->problems : [$progress->failure], $request->getLocale()),
                'redirect' => FlowStatus::Completed === $progress->status ? $completed : null,
            ], $status),
            FlowStatus::Completed === $progress->status => new RedirectResponse($completed, 303),
            FlowStatus::Failed === $progress->status => new Response($this->pages->failed($flow, $progress->failure ?? new Problem('yorvoid.flow.out_of_date'), $this->startUrl($flow), $request->getLocale()), $status),
            $moved && 200 === $status => new RedirectResponse($this->urls->generate('yorvoid_flow_show', ['flow' => $flow, 'id' => $progress->state->id]), 303),
            default => $this->step($request, $progress, $status),
        };

        if (null !== $progress->retryAfter) {
            $response->headers->set('Retry-After', (string) $progress->retryAfter);
        }

        $cookie = $progress->signedIn?->deviceCookie;

        if (null !== $cookie) {
            $response->headers->setCookie(Cookie::create($this->deviceCookie, $cookie->reveal(), new \DateTimeImmutable('+400 days'), '/', secure: true, httpOnly: true, sameSite: Cookie::SAMESITE_LAX));
        }

        return $response;
    }

    private function step(Request $request, Progress $progress, int $status): Response
    {
        $flow = $progress->state->flow;
        // A step in progress has both.
        \assert(null !== $progress->step && null !== $progress->view);

        return new Response($this->pages->step($flow, $progress->step, $progress->view, $progress->problems, $this->urls->generate('yorvoid_flow_submit', ['flow' => $flow, 'id' => $progress->state->id, 'step' => $progress->step]), $request->getLocale()), $status);
    }

    private function expired(Request $request, string $flow): Response
    {
        return $this->wantsJson($request)
            ? new JsonResponse(['flow' => $flow, 'id' => null, 'status' => 'expired', 'step' => null, 'view' => null, 'problems' => $this->pages->problems([new Problem('yorvoid.flow.expired')], $request->getLocale()), 'redirect' => null], 404)
            : new Response($this->pages->expired($flow, $this->startUrl($flow), $request->getLocale()), 404);
    }

    private function startUrl(string $flow): ?string
    {
        return \array_key_exists($flow, self::ENTRANCES) ? $this->urls->generate('yorvoid_flow_start', ['flow' => $flow]) : null;
    }

    private function wantsJson(Request $request): bool
    {
        return 'json' === $request->getContentTypeFormat() || \in_array('application/json', $request->getAcceptableContentTypes(), true);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(Request $request): array
    {
        $body = json_decode($request->getContent(), true);

        if ('json' !== $request->getContentTypeFormat() || !\is_array($body) || array_is_list($body) && [] !== $body) {
            throw new BadRequestHttpException('Send a JSON object.');
        }

        return $body;
    }
}
