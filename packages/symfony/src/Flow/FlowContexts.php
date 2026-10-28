<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Flow;

use Symfony\Component\HttpFoundation\Request;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Secret;
use YorvoID\Flows\FlowContext;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\Json;

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
    ) {
    }

    public function of(Request $request): FlowContext
    {
        $cookie = $request->cookies->get($this->deviceCookie);
        $route = $request->attributes->get('_route');

        return new FlowContext(
            new Origin(OriginKind::Web, \is_string($route) ? $route : null),
            $this->current->get(),
            Json::device($request),
            $request->getClientIp(),
            \is_string($cookie) && '' !== $cookie ? Secret::fromString($cookie) : null,
            $request->getLocale(),
        );
    }
}
