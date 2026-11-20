<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\Remedy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use YorvoID\Core\Storage\RetryableConflict;
use YorvoID\Symfony\Remedy\RetryableConflictListener;
use YorvoID\Tests\Core\Storage\RetriesTest;

/**
 * A request whose work conflicted gets 503 with Retry-After, also when the conflict is wrapped, and
 * nothing about the database.
 */
final class RetryableConflictListenerTest extends TestCase
{
    public function testAConflictIsAServiceUnavailableToTryAgain(): void
    {
        $conflict = new RetryableConflict(RetriesTest::deadlock(), 3);

        foreach (['html' => $conflict, 'json' => new \RuntimeException('The application\'s own.', previous: $conflict)] as $format => $thrown) {
            $request = new Request();
            $request->setRequestFormat($format);
            $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $thrown);

            new RetryableConflictListener()($event);

            $response = $event->getResponse();
            self::assertNotNull($response, $format);
            self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
            self::assertSame('1', $response->headers->get('Retry-After'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertStringNotContainsString('deadlock', (string) $response->getContent());
            $text = 'The request conflicted with another one. Try again.';
            if ('json' === $format) {
                self::assertSame('application/json', $response->headers->get('Content-Type'));
                self::assertSame(['error' => $text, 'retryable' => true], json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR));
            } else {
                self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
                self::assertSame($text, $response->getContent());
            }
        }
    }

    public function testAnythingElseIsLeftAlone(): void
    {
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Not a conflict.'));

        new RetryableConflictListener()($event);

        self::assertNull($event->getResponse());
    }
}
