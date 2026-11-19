<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;

/**
 * Where what YorvoID does in a web request comes from: one correlation ID for the whole request, its
 * sub-requests included, so its decisions and its security-log entries go together, and the decision
 * log samples the request whole or not at all. Outside a request, each origin has its own.
 *
 * The ID is drawn here, not read from the request: the decision log samples by a hash of it, so a
 * client that chose it would choose whether its request is recorded. To use an ID of your own, e.g. a
 * proxy's, decorate this service, and take the ID only from something the client can't set.
 *
 * @api
 */
final class RequestOrigins
{
    /** @var \WeakMap<Request, string> */
    private \WeakMap $ids;

    /**
     * @param list<string> $apiPaths Path prefixes whose requests are an API's (yorvoid.api.paths)
     */
    public function __construct(
        private readonly RequestStack $requests,
        private readonly array $apiPaths = [],
    ) {
        $this->ids = new \WeakMap();
    }

    /**
     * @param string|null $detail What ran, e.g. the route
     */
    public function of(?string $detail = null): Origin
    {
        $request = $this->requests->getMainRequest();

        if (null === $request) {
            return new Origin(OriginKind::Web, $detail);
        }

        $this->ids[$request] ??= (new Origin(OriginKind::Web))->correlationId;

        return new Origin($this->kind($request), $detail, $this->ids[$request]);
    }

    /**
     * An API's request carries a bearer token or is on an API path; a proxy's Basic auth doesn't make a
     * page an API. The request says it itself, so a rule may only narrow by it (D-45).
     */
    private function kind(Request $request): OriginKind
    {
        if (str_starts_with(strtolower((string) $request->headers->get('Authorization')), 'bearer ')) {
            return OriginKind::Api;
        }

        foreach ($this->apiPaths as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return OriginKind::Api;
            }
        }

        return OriginKind::Web;
    }
}
