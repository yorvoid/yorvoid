<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * What the {@see Authorization} decided about one actor running one operation.
 *
 * @api
 */
final readonly class Decision
{
    /**
     * @param AuthorizedBy|null $basis  What permitted it; null unless it's a Permit
     * @param string            $reason For the log and for developers, e.g. "no ability policy"; never shown to people as is
     */
    public function __construct(
        public Verdict $verdict,
        public ?AuthorizedBy $basis,
        public string $reason,
    ) {
    }

    public static function permit(AuthorizedBy $basis, string $reason): self
    {
        return new self(Verdict::Permit, $basis, $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(Verdict::Deny, null, $reason);
    }

    public static function indeterminate(string $reason): self
    {
        return new self(Verdict::Indeterminate, null, $reason);
    }

    public function permits(): bool
    {
        return Verdict::Permit === $this->verdict && null !== $this->basis;
    }
}
