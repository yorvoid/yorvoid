<?php

declare(strict_types=1);

/*
 * OPS-042's targets, by subject: what each run's table compares the p99 with. They're reported, not
 * gated (M2-8): a subject over its target at a release needs a line in the release notes.
 */
return [
    'DecisionBench::benchCachedDecision' => ['p99' => 1000, 'requirement' => 'OPS-042: a cached decision, p99 < 1 ms'],
    'DecisionBench::benchUncachedDecision' => ['p99' => 5000, 'requirement' => 'OPS-042: uncached, p99 < 5 ms; measured on role inheritance, the chain in M3-1'],
    'SignInBench::benchPasskeySignIn' => ['p99' => 50000, 'requirement' => 'OPS-042: the passkey sign-in endpoints < 50 ms'],
];
