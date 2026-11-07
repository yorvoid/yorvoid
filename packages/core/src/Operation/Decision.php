<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Reason;
use YorvoID\Core\Authorization\Remedy;
use YorvoID\Core\Exception\IntegratorError;

/**
 * What the {@see Authorization} decided about one actor running one operation: the verdict, why, and
 * what could change a refusal.
 *
 * @api
 */
final readonly class Decision
{
    /**
     * @param AuthorizedBy|null         $basis         What permitted it; null unless it's a Permit or Permit-offline
     * @param string                    $reason        A summary for the log and for developers, e.g. "no permit applies"; never shown to people as is
     * @param list<Reason>              $reasons       What each element that matched, and the authority check, gave
     * @param list<Remedy>              $remedies      What could satisfy what's missing, on a Deny caused only by unmet evidence
     * @param list<IndeterminateReason> $indeterminate Why it couldn't decide: at least one exactly when it's Indeterminate
     *
     * @throws IntegratorError When the parts don't fit the verdict
     */
    public function __construct(
        public Verdict $verdict,
        public ?AuthorizedBy $basis,
        public string $reason,
        public array $reasons = [],
        public array $remedies = [],
        public array $indeterminate = [],
    ) {
        $fits = match ($verdict) {
            Verdict::Permit, Verdict::PermitOffline => null !== $basis && [] === $remedies && [] === $indeterminate,
            Verdict::Deny => null === $basis && [] === $indeterminate,
            Verdict::Indeterminate => null === $basis && [] === $remedies && [] !== $indeterminate,
        };

        if (!$fits) {
            throw new IntegratorError(\sprintf('A %s decision can\'t have these parts.', $verdict->value), 'A permit has a basis and nothing else; a refusal has no basis; only a Deny has remedies, and only Indeterminate has reasons it couldn\'t decide.', 'concepts/authorization.md');
        }
    }

    /**
     * @param list<Reason> $reasons
     */
    public static function permit(AuthorizedBy $basis, string $reason, array $reasons = []): self
    {
        return new self(Verdict::Permit, $basis, $reason, $reasons);
    }

    /**
     * @param list<Reason> $reasons
     * @param list<Remedy> $remedies
     */
    public static function deny(string $reason, array $reasons = [], array $remedies = []): self
    {
        return new self(Verdict::Deny, null, $reason, $reasons, $remedies);
    }

    /**
     * @param non-empty-list<IndeterminateReason> $indeterminate
     * @param list<Reason>                        $reasons
     */
    public static function indeterminate(array $indeterminate, string $reason, array $reasons = []): self
    {
        return new self(Verdict::Indeterminate, null, $reason, $reasons, indeterminate: $indeterminate);
    }

    /**
     * Whether it's a Permit, and only that: Permit-offline isn't (P1′).
     */
    public function permits(): bool
    {
        return match ($this->verdict) {
            Verdict::Permit => null !== $this->basis,
            Verdict::PermitOffline, Verdict::Deny, Verdict::Indeterminate => false,
        };
    }
}
