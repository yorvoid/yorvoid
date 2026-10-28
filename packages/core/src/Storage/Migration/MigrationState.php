<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Migration;

/**
 * A migration as the database knows it: pending, executed, or executed by a version of YorvoID this
 * code doesn't know (newer, during a rolling upgrade).
 *
 * @api
 */
final readonly class MigrationState
{
    public function __construct(
        public string $version,
        public ?string $description,
        public ?LockProfile $lockProfile,
        public ?\DateTimeImmutable $executedAt,
    ) {
    }

    public function isExecuted(): bool
    {
        return null !== $this->executedAt;
    }

    /**
     * Executed, but unknown to this code.
     */
    public function isUnknown(): bool
    {
        return null === $this->lockProfile;
    }
}
