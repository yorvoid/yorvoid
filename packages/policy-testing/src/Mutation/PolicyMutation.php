<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Mutation;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\PolicyTesting\PolicyCase;
use YorvoID\PolicyTesting\PolicyResult;
use YorvoID\PolicyTesting\PolicyTests;
use YorvoID\PolicyTesting\PolicyUnderTest;

/**
 * Policy mutation testing (AUZ-045, after XACMUT, Bertolino et al., 2013, and Xu et al., 2018): breaks
 * the policy one change at a time and runs the cases against each, to find the changes no case
 * catches, i.e. the decisions the cases don't pin down.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class PolicyMutation
{
    /**
     * @param iterable<PolicyCase> $cases
     * @param list<string>         $ignore The IDs of mutants that are equivalent: no decision can tell them from the policy
     *
     * @throws IntegratorError When the policy wasn't built through the mutants, or an ignored ID is no mutant's
     */
    public function run(PolicyUnderTest $policy, iterable $cases, array $ignore = []): MutationReport
    {
        $mutants = $policy->mutants ?? throw new IntegratorError('The policy under test wasn\'t built with mutants.', 'Build it with PolicyUnderTest::of(), whose function passes what it builds through the Mutants it gets, or in Symfony with PolicyKernel.', 'how-to/test-your-policy.md#mutation-testing');
        $tests = new PolicyTests();
        $named = PolicyTests::named($cases);
        $mutants->activate(null);
        $failing = array_values(array_filter($tests->run($policy, $named), static fn (PolicyResult $result): bool => !$result->passed()));

        if ([] !== $failing) {
            return new MutationReport($failing, [], [], []);
        }

        $all = $mutants->all();
        $ids = array_map(static fn (Mutant $mutant): string => $mutant->id(), $all);

        foreach ($ignore as $id) {
            if (!\in_array($id, $ids, true)) {
                throw new IntegratorError(\sprintf('The ignored mutant "%s" isn\'t one of the policy\'s.', $id), 'Remove it from the ignored ones: the policy changed, or it was misspelt. The report lists every mutant\'s ID.', 'how-to/test-your-policy.md#equivalent-mutants');
            }
        }

        $killed = [];
        $survived = [];
        $ignored = [];

        foreach ($all as $mutant) {
            if (\in_array($mutant->id(), $ignore, true)) {
                $ignored[] = $mutant;

                continue;
            }

            $caught = $this->caught($policy, $mutants, $mutant, $named);

            if (null === $caught) {
                $survived[] = $mutant;
            } else {
                $killed[$mutant->id()] = $caught;
            }
        }

        $unexercised = array_values(array_map(static fn (Mutant $mutant): string => $mutant->id(), array_filter($survived, static fn (Mutant $mutant): bool => !$mutants->exercised($mutant))));

        return new MutationReport([], $killed, $survived, $ignored, $unexercised);
    }

    /**
     * @param array<string, PolicyCase> $cases
     *
     * @return string|null The description of the first case that fails with it on
     */
    private function caught(PolicyUnderTest $policy, Mutants $mutants, Mutant $mutant, array $cases): ?string
    {
        $tests = new PolicyTests();
        $mutants->activate($mutant);

        try {
            foreach ($cases as $case) {
                if (!$tests->one($policy, $case)->passed()) {
                    return $case->description;
                }
            }

            return null;
        } finally {
            $mutants->activate(null);
        }
    }
}
