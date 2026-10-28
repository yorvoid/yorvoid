<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use YorvoID\Symfony\Security\CurrentSession;

/**
 * A page only signed-in people see: who they are, and their YorvoID session.
 */
final readonly class MeController
{
    public function __construct(
        private Security $security,
        private CurrentSession $current,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $session = $this->current->get();

        return new JsonResponse([
            'user' => $this->security->getUser()?->getUserIdentifier(),
            'class' => null === $this->security->getUser() ? null : $this->security->getUser()::class,
            'roles' => $this->security->getUser()?->getRoles() ?? [],
            'methods' => null === $session ? [] : array_map(static fn ($method): string => $method->value, $session->authentication->methods()),
        ]);
    }
}
