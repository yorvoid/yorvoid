<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Symfony\Security\RequestOrigins;

/**
 * A request is an API's when it carries a bearer token or is on an API path; a proxy's Basic auth
 * doesn't make a page an API (D-45, API-06).
 */
final class RequestOriginsTest extends TestCase
{
    public function testTellsAnApisRequestsFromAPagesOnes(): void
    {
        $cases = [
            'a page' => [Request::create('/account'), OriginKind::Web],
            'a bearer token' => [Request::create('/account', server: ['HTTP_AUTHORIZATION' => 'Bearer abc']), OriginKind::Api],
            'a proxy\'s Basic auth' => [Request::create('/account', server: ['HTTP_AUTHORIZATION' => 'Basic YTpi']), OriginKind::Web],
            'an API path' => [Request::create('/api/v1/pages'), OriginKind::Api],
            'a path that only begins alike' => [Request::create('/apiary'), OriginKind::Web],
        ];

        foreach ($cases as $case => [$request, $kind]) {
            $requests = new RequestStack();
            $requests->push($request);

            self::assertSame($kind, new RequestOrigins($requests, ['/api/'])->of('route')->kind, $case);
        }
    }
}
