<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Remedy;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use YorvoID\Core\Storage\RetryableConflict;

/**
 * A request whose work conflicted with another transaction, and wasn't YorvoID's to run again (it ran
 * in the application's own transaction), or conflicted every time: 503 with Retry-After, which a client
 * may try again on. Nothing of it was committed (D-52).
 *
 * @internal
 */
final readonly class RetryableConflictListener
{
    /** Before Symfony's error page, as the refusals' */
    public const int PRIORITY = -64;

    /** Seconds */
    private const string RETRY_AFTER = '1';

    private const string TEXT = 'The request conflicted with another one. Try again.';

    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->hasResponse() || !self::conflict($event->getThrowable())) {
            return;
        }

        $headers = ['Retry-After' => self::RETRY_AFTER, 'Cache-Control' => 'no-store'];
        $event->setResponse('json' === $event->getRequest()->getPreferredFormat()
            ? new JsonResponse(['error' => self::TEXT, 'retryable' => true], Response::HTTP_SERVICE_UNAVAILABLE, $headers)
            : new Response(self::TEXT, Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=UTF-8', ...$headers]));
    }

    /**
     * Whether the conflict is what failed, also wrapped by the application or Symfony.
     */
    private static function conflict(\Throwable $throwable): bool
    {
        for ($e = $throwable; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof RetryableConflict) {
                return true;
            }
        }

        return false;
    }
}
