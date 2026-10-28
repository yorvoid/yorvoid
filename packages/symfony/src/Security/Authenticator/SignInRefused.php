<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security\Authenticator;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * A sign-in YorvoID refused, with the message to show and the status to answer with.
 *
 * @internal
 */
final class SignInRefused extends CustomUserMessageAuthenticationException
{
    /**
     * @param int|null              $retryAfter Seconds, when it was throttled
     * @param array<string, scalar> $details    More for the browser client, e.g. that the passkey isn't known
     */
    public function __construct(
        string $message,
        public readonly int $status = 401,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message, previous: $previous);
    }
}
