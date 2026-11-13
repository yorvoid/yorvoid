<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Flow;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use YorvoID\Core\Secret;
use YorvoID\Flows\FlowContext;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\Json;
use YorvoID\Symfony\Security\RequestOrigins;

/**
 * The context a flow continues in, from the request: the YorvoID session, the browser's description,
 * the client's address, the device cookie, the language.
 *
 * @api
 */
final readonly class FlowContexts
{
    public function __construct(
        private CurrentSession $current,
        private string $deviceCookie,
        private RequestOrigins $origins = new RequestOrigins(new RequestStack()),
    ) {
    }

    public function of(Request $request): FlowContext
    {
        $cookie = $request->cookies->get($this->deviceCookie);
        $route = $request->attributes->get('_route');

        return new FlowContext(
            $this->origins->of(\is_string($route) ? $route : null),
            $this->current->get(),
            Json::device($request),
            $request->getClientIp(),
            \is_string($cookie) && '' !== $cookie ? Secret::fromString($cookie) : null,
            $request->getLocale(),
        );
    }
}
