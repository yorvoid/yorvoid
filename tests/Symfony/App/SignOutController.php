<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use Symfony\Component\HttpFoundation\JsonResponse;
use YorvoID\Symfony\Security\CurrentSession;

/**
 * Signs the person out in the application's own code, not through the firewall's logout.
 */
final readonly class SignOutController
{
    public function __construct(
        private CurrentSession $current,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $this->current->signOut();

        return new JsonResponse(['signed_out' => true]);
    }
}
