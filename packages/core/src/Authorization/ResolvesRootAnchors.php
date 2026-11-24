<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * An outside authority over a chain's root, asked at every use and never cached (M3-1's extended
 * configuration): it may only narrow, failing or unknown with a reason, or null when it has nothing to
 * say. None is registered by default; M3-6 gives it the link's attributes, so what it's given will grow.
 *
 * @api
 *
 * @experimental Don't implement it before M3-6: its signature changes then
 */
interface ResolvesRootAnchors
{
    public function resolve(DelegatedScope $scope, Request $request): ?Truth;
}
