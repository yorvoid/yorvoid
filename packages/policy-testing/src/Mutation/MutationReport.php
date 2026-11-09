<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Mutation;

use YorvoID\PolicyTesting\PolicyResult;

/**
 * What the cases caught (AUZ-045): each mutant, the case that caught it, or that none did.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class MutationReport
{
    /**
     * @param list<PolicyResult>    $failing     The cases that fail on the policy itself: then nothing was mutated
     * @param array<string, string> $killed      Each caught mutant's ID => the description of the case that caught it
     * @param list<Mutant>          $survived    What no case caught
     * @param list<Mutant>          $ignored     Left out, as asked: not in the score
     * @param list<string>          $unexercised The IDs of the survivors no case reached: a forbid no case saw hold, an
     *                                           element no case saw unable to tell
     */
    public function __construct(
        public array $failing,
        public array $killed,
        public array $survived,
        public array $ignored,
        public array $unexercised = [],
    ) {
    }

    /**
     * Whether no case reached what the survivor changes: add one, or ignore it if it's equivalent.
     */
    public function exercised(Mutant $mutant): bool
    {
        return !\in_array($mutant->id(), $this->unexercised, true);
    }

    /**
     * The share of the mutants tried that a case caught, from 0 to 100; 100 when none was tried.
     */
    public function score(): float
    {
        $tried = \count($this->killed) + \count($this->survived);

        return 0 === $tried ? 100.0 : 100.0 * \count($this->killed) / $tried;
    }
}
