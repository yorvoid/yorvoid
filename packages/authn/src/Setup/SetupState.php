<?php

declare(strict_types=1);

namespace YorvoID\Authn\Setup;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A realm's setup: its current code, until when it works, and who claimed the realm with it, if anyone.
 *
 * @api
 */
final readonly class SetupState
{
    public function __construct(
        public Realm $realm,
        public string $codeHash,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $claimedAt = null,
        public ?PrincipalId $claimedBy = null,
    ) {
    }

    public function isClaimed(): bool
    {
        return null !== $this->claimedAt;
    }
}
