<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Flow;

use YorvoID\Flows\Flow;

/**
 * Adds the application's own flows next to YorvoID's (BND-11), e.g. a journey for an application's
 * own sign-up: every service implementing it is asked once. Name them with your own prefix, never
 * "yorvoid.". The customizers see them too.
 *
 * @api
 */
interface FlowSource
{
    /**
     * @return iterable<Flow>
     */
    public function flows(): iterable;

    /**
     * Which of them a request may start at /yorvoid/flows/<flow>, and what it may give them: by flow,
     * each request field and the key of the flow's state it goes to. A flow that isn't listed starts
     * only from the application's code.
     *
     * @return array<string, array<string, string>> E.g. ['acme.signup' => ['plan' => 'acme.plan']]
     */
    public function entrances(): array;
}
