<?php

declare(strict_types=1);

namespace YorvoID\Tests\Tools\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use YorvoID\Tools\PHPStan\VerdictMatchRule;

/**
 * In a process of its own, as the other rules' tests: PHPStan's container stays in it.
 *
 * @extends RuleTestCase<VerdictMatchRule>
 */
#[RunTestsInSeparateProcesses]
final class VerdictMatchRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new VerdictMatchRule();
    }

    public function testAVerdictIsReadOnlyThroughAnExhaustiveMatch(): void
    {
        $message = 'Read a Verdict with a match that has an arm for every case and no default, so Permit-offline and Indeterminate are never taken for Permit (P1, P1′).';

        $this->analyse([__DIR__.'/data/verdict-match.php'], [
            [$message, 12],
            [$message, 13],
            [$message, 14],
            [$message, 15],
            [$message, 16],
            [$message, 21],
            [$message, 26],
        ]);
    }
}
