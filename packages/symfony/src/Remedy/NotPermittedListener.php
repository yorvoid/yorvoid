<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Remedy;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Symfony\Controller\FlowController;
use YorvoID\Symfony\LocalPath;
use YorvoID\Symfony\Templates\FlowPages;

/**
 * An operation the application didn't catch the refusal of, answered for the person (M2-10): what
 * would satisfy it, or that it isn't permitted (403), or that it couldn't be checked (503). JSON for
 * requests that ask for it. Turn it off with yorvoid.remedies.pages: false.
 *
 * It runs at PRIORITY, after the application's own listeners at the default priority and before
 * Symfony's error page, and leaves alone a refusal another listener answered.
 *
 * @internal
 */
final readonly class NotPermittedListener
{
    /** Below the default (0), so the application's listeners come first; above Symfony's ErrorListener (-128) */
    public const int PRIORITY = -64;

    public function __construct(
        private RemedyPrompts $prompts,
        private FlowPages $pages,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $refused = $event->getThrowable();

        if (!$event->isMainRequest() || $event->hasResponse() || !$refused instanceof NotPermitted) {
            return;
        }

        $request = $event->getRequest();
        $locale = $request->getLocale();
        $decision = $refused->decision;
        $remedies = $this->prompts->of($decision, self::returnTo($request), $locale);
        $summary = $this->prompts->summary($decision, $locale);
        $status = RemedyPrompts::unavailable($decision->verdict) ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_FORBIDDEN;

        $response = self::wantsJson($request)
            ? new JsonResponse(['error' => $summary['text'] ?? $summary['title'], 'not_permitted' => ['verdict' => $decision->verdict->value, 'decision' => $decision->id, 'remedies' => $remedies]], $status)
            : new Response($this->pages->refused($summary, $remedies, $this->prompts->reference($decision, $locale), $locale), $status);

        $event->setResponse(FlowController::secured($response));
    }

    /**
     * Where to come back to after a step-up: this page, if it was a GET; otherwise the page the request
     * came from, if it's on this site. A POST isn't replayed: the person does it again. Only the path is
     * kept, never an address of another site.
     */
    private static function returnTo(Request $request): ?string
    {
        if ($request->isMethodSafe()) {
            $path = $request->getRequestUri();

            return LocalPath::is($path) ? $path : null;
        }

        $parts = parse_url((string) $request->headers->get('Referer'));

        // The host is the request's own, as trusted_hosts and trusted_proxies let Symfony read it.
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || \sprintf('%s://%s', strtolower($parts['scheme']), strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '')) !== $request->getSchemeAndHttpHost()) {
            return null;
        }

        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return LocalPath::is($path) ? $path : null;
    }

    private static function wantsJson(Request $request): bool
    {
        return 'json' === $request->getContentTypeFormat() || \in_array('application/json', $request->getAcceptableContentTypes(), true);
    }
}
