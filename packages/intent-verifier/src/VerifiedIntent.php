<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * An intent one of the verifier's keys signed: run exactly its action with exactly its parameters.
 *
 * @api
 */
final readonly class VerifiedIntent
{
    public function __construct(
        public Intent $intent,
        public Key $key,
        public \DateTimeImmutable $verifiedAt,
    ) {
    }
}
