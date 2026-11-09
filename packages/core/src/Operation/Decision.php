<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Precedence;
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
     * @param string|null               $id            Its ID, from the evaluator, which the operation's permit and its log entries carry
     * @param \DateTimeImmutable|null   $at            When it was decided
     * @param array<string, int>        $versions      The versions of what it read, by source, e.g. ["policy" => 12, "relations" => 4] (AUZ-009)
     * @param string|null               $hash          Its record's hash in the decision log, when it's recorded there
     * @param Precedence|null           $precedence    The row of the precedence that decided, from the evaluator (experimental)
     * @param Decision|null             $viewer        Viewing as someone else, the viewer's own decision: this one is both
     *                                                 intersected, with the reasons of the side that decided (experimental)
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
        public ?string $id = null,
        public ?\DateTimeImmutable $at = null,
        public array $versions = [],
        public ?string $hash = null,
        public ?Precedence $precedence = null,
        public ?self $viewer = null,
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
    public static function permit(AuthorizedBy $basis, string $reason, array $reasons = [], ?Precedence $precedence = null): self
    {
        return new self(Verdict::Permit, $basis, $reason, $reasons, precedence: $precedence);
    }

    /**
     * @param list<Reason> $reasons
     * @param list<Remedy> $remedies
     */
    public static function deny(string $reason, array $reasons = [], array $remedies = [], ?Precedence $precedence = null): self
    {
        return new self(Verdict::Deny, null, $reason, $reasons, $remedies, precedence: $precedence);
    }

    /**
     * @param non-empty-list<IndeterminateReason> $indeterminate
     * @param list<Reason>                        $reasons
     */
    public static function indeterminate(array $indeterminate, string $reason, array $reasons = [], ?Precedence $precedence = null): self
    {
        return new self(Verdict::Indeterminate, null, $reason, $reasons, indeterminate: $indeterminate, precedence: $precedence);
    }

    /**
     * The same decision, with its ID, its time and the versions it read.
     *
     * @param array<string, int> $versions
     */
    public function identified(string $id, \DateTimeImmutable $at, array $versions): self
    {
        return new self($this->verdict, $this->basis, $this->reason, $this->reasons, $this->remedies, $this->indeterminate, $id, $at, $versions, $this->hash, $this->precedence, $this->viewer);
    }

    /**
     * The same decision, with the hash of its record in the decision log.
     */
    public function recorded(string $hash): self
    {
        return new self($this->verdict, $this->basis, $this->reason, $this->reasons, $this->remedies, $this->indeterminate, $this->id, $this->at, $this->versions, $hash, $this->precedence, $this->viewer);
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
