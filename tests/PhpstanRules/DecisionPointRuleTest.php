<?php

declare(strict_types=1);

namespace YorvoID\Tests\PhpstanRules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use YorvoID\PhpstanRules\DecisionPointRule;
use YorvoID\PhpstanRules\YorvoIDCode;

/**
 * An application that builds a decision point of its own is flagged; YorvoID's own packages, which
 * build the application's one, aren't. In a process of its own, as the other rules' tests.
 *
 * @extends RuleTestCase<DecisionPointRule>
 */
#[RunTestsInSeparateProcesses]
final class DecisionPointRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'A second YorvoID decision point: an application has one Operations, which its bundle or composition root builds. Another one\'s permits aren\'t in the decision log.';

    private const string TIP = 'Ask for the Operations service. Building it on purpose, in a composition root without the bundle: ignore this error there. See https://github.com/yorvoid/yorvoid/blob/main/docs/concepts/operations-and-events.md#the-decision-point';

    protected function getRule(): Rule
    {
        return new DecisionPointRule(new YorvoIDCode([\dirname(__DIR__, 2).'/vendor/yorvoid', __DIR__.'/data/vendor/yorvoid']));
    }

    public function testFlagsAnApplicationThatBuildsOperations(): void
    {
        $this->analyse([__DIR__.'/data/decision-points.php'], [
            [self::MESSAGE, 16, self::TIP],
            [self::MESSAGE, 21, self::TIP],
        ]);
    }

    public function testYorvoIDsOwnPackagesBuildIt(): void
    {
        $this->analyse([\dirname(__DIR__, 2).'/vendor/yorvoid/testing/src/Scenario.php'], []);
    }
}
