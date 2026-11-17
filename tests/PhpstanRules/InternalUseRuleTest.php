<?php

declare(strict_types=1);

namespace YorvoID\Tests\PhpstanRules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use YorvoID\PhpstanRules\InternalUseRule;
use YorvoID\PhpstanRules\YorvoIDCode;

/**
 * The rule for an application's code (AR-01): a planted use of what YorvoID marks @internal fails.
 *
 * @extends RuleTestCase<InternalUseRule>
 */
#[RunTestsInSeparateProcesses]
final class InternalUseRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new InternalUseRule(new YorvoIDCode([\dirname(__DIR__, 2).'/vendor/yorvoid', __DIR__.'/data/vendor/yorvoid']), $this->createReflectionProvider());
    }

    public function testFlagsAnApplicationsUseOfYorvoIDsInternals(): void
    {
        $this->analyse([__DIR__.'/data/internal-uses.php'], [
            ['YorvoID\\Authn\\Session\\Sessions::stepUp() is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', 28, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered'],
            ['YorvoID\\Authn\\Credential\\Credentials::markUsed() is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', 29, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered'],
            ['YorvoID\\Authn\\Credential\\Credentials::session() is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', 31, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered'],
            ['YorvoID\\Policy\\RoleAssignment::applies() is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', 32, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered'],
            ['new YorvoID\\Authn\\Recovery\\CredentialDisabledMeanwhile is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', 33, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered'],
            // A property of an internal class, an internal property, an internal constructor, its own or inherited, a static call on an object.
            ['YorvoID\\Authn\\Recovery\\CredentialDisabledMeanwhile::$credential is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', 42, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered'],
            ['YorvoID\\Fake\\FakeApi::$secret is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', 43, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered'],
            ['new YorvoID\\Fake\\FakeApi is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', 45, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered'],
            ['new YorvoID\\Fake\\FakeChild is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', 46, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered'],
            ['YorvoID\\Authn\\Credential\\Credentials::session() is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', 49, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered'],
            // A nullsafe read, once; and nothing for a class without a constructor.
            ['YorvoID\\Fake\\FakeApi::$secret is internal to YorvoID: it\'s for YorvoID\'s own packages, and changes in any minor release. Your application doesn\'t use it.', 50, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#whats-covered'],
        ]);
    }
}
