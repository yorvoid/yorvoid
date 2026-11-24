<?php

declare(strict_types=1);

namespace YorvoID\Delegation;

/**
 * Whether a delegation's chain holds now (06 §5.3): valid, invalid (a known failure: revoked, expired,
 * outside its parent, a principal no longer active), or unknown (a link missing or inconsistent).
 *
 * @api
 *
 * @experimental
 */
final readonly class ChainCheck
{
    /**
     * @param list<Delegation> $links The chain, the leaf first, as far as it was read
     */
    private function __construct(
        public ?bool $valid,
        public string $reason,
        public array $links,
    ) {
    }

    /**
     * @param list<Delegation> $links
     */
    public static function valid(array $links): self
    {
        return new self(true, 'valid', $links);
    }

    /**
     * @param list<Delegation> $links
     */
    public static function invalid(string $reason, array $links): self
    {
        return new self(false, $reason, $links);
    }

    /**
     * @param list<Delegation> $links
     */
    public static function unknown(string $reason, array $links): self
    {
        return new self(null, $reason, $links);
    }
}
