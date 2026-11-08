<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Verdict;

/**
 * Why a decision is what it is (AUZ-020): the row of the precedence that decided, the elements behind
 * that row, what they rest on, the remedies and the versions read. No text for people: an explainer
 * builds its own from it.
 *
 * ```php
 * $explanation = Explanation::of($decision);
 * foreach ($explanation->grounds as $ground) { ... } // e.g. the role "editor", by its default, on site:1
 * ```
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's explainer uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class Explanation
{
    /**
     * @param list<Reason>       $elements What the elements behind the row gave: the forbids that held, the permits that
     *                                     held, the authority check..., or on a Deny with no permit, the permits that failed
     * @param list<Ground>       $grounds  What those rest on
     * @param list<Remedy>       $remedies
     * @param array<string, int> $versions
     */
    private function __construct(
        public ?string $id,
        public Verdict $verdict,
        public Precedence $precedence,
        public array $elements,
        public array $grounds,
        public array $remedies,
        public array $versions,
    ) {
    }

    /**
     * @throws IntegratorError When the decision isn't the evaluator's, which says which row decided
     */
    public static function of(Decision $decision): self
    {
        $precedence = $decision->precedence ?? throw new IntegratorError('Only a decision the evaluator made can be explained.', 'Explain the decisions the evaluator returns: they say which row of the precedence decided.', 'concepts/authorization.md#explanations');
        [$effect, $value] = match ($precedence) {
            Precedence::ForbidHolds => ['forbid', TruthValue::Holds],
            Precedence::ForbidUnknown => ['forbid', TruthValue::Unknown],
            Precedence::AuthorityInvalid, Precedence::AuthorityUnknown => ['authority', null],
            Precedence::PermitHolds => ['permit', TruthValue::Holds],
            Precedence::PermitUnknown => ['permit', TruthValue::Unknown],
            Precedence::NoPermit => ['permit', TruthValue::Fails],
            Precedence::Offline => ['offline', null],
        };
        $elements = array_values(array_filter($decision->reasons, static fn (Reason $reason): bool => $effect === $reason->effect && (null === $value || $value === $reason->truth->value)));
        $grounds = [];

        foreach ($elements as $reason) {
            array_push($grounds, ...$reason->truth->grounds);
        }

        return new self($decision->id, $decision->verdict, $precedence, $elements, $grounds, $decision->remedies, $decision->versions);
    }
}
