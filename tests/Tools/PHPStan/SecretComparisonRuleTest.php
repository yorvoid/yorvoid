<?php

declare(strict_types=1);

namespace YorvoID\Tests\Tools\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use YorvoID\Tools\PHPStan\SecretComparisonRule;

/**
 * PHPStan builds its whole container for this test, about 85 MB that stays in the process; a process of
 * its own keeps that from adding to the rest of the suite.
 *
 * @extends RuleTestCase<SecretComparisonRule>
 */
#[RunTestsInSeparateProcesses]
final class SecretComparisonRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new SecretComparisonRule();
    }

    public function testRefusesVariableTimeComparisonsOfRevealedSecrets(): void
    {
        $message = 'A revealed secret is compared in variable time; use Secret::equals(), which compares in constant time (PLT-053).';

        $this->analyse([__DIR__.'/data/secret-comparison.php'], [
            [$message, 11],
            [$message, 12],
            [$message, 13],
            [$message, 14],
            [$message, 15],
            [$message, 16],
        ]);
    }
}
