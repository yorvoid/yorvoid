<?php

declare(strict_types=1);

namespace YorvoID\Tests\PhpstanRules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use YorvoID\PhpstanRules\StoreCallRule;
use YorvoID\PhpstanRules\YorvoIDCode;

/**
 * The rule for an application's code (AR-01): a planted call to a store fails, a decorator and YorvoID's
 * store contracts may call one. In a process of its own, as the repository's own rules' tests.
 *
 * @extends RuleTestCase<StoreCallRule>
 */
#[RunTestsInSeparateProcesses]
final class StoreCallRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        // YorvoID's packages, and a stand-in for one under data/vendor/yorvoid.
        return new StoreCallRule(new YorvoIDCode([\dirname(__DIR__, 2).'/vendor/yorvoid', __DIR__.'/data/vendor/yorvoid']));
    }

    public function testFlagsAnApplicationsCallsToAStore(): void
    {
        $this->analyse([__DIR__.'/data/store-calls.php'], [
            ['YorvoID\\Policy\\PolicyStore::version() is a YorvoID store\'s: stores are for implementing, not calling. A call changes or reads YorvoID\'s records without asking YorvoID; use its services instead.', 26, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#interfaces-you-implement'],
            ['YorvoID\\Policy\\PolicyStore::bump() is a YorvoID store\'s: stores are for implementing, not calling. A call changes or reads YorvoID\'s records without asking YorvoID; use its services instead.', 27, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#interfaces-you-implement'],
            ['YorvoID\\Authn\\StepUp\\CoolDownStore::extend() is a YorvoID store\'s: stores are for implementing, not calling. A call changes or reads YorvoID\'s records without asking YorvoID; use its services instead.', 28, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#interfaces-you-implement'],
            ['YorvoID\\Authn\\StepUp\\CoolDownStore::purge() is a YorvoID store\'s: stores are for implementing, not calling. A call changes or reads YorvoID\'s records without asking YorvoID; use its services instead.', 34, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#interfaces-you-implement'],
            // A decorator of one store calling another is no decorator.
            ['YorvoID\\Policy\\PolicyStore::version() is a YorvoID store\'s: stores are for implementing, not calling. A call changes or reads YorvoID\'s records without asking YorvoID; use its services instead.', 65, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#interfaces-you-implement'],
            // Outside a class; an application's own @spi interface, a store's other method and a class that says @spi aren't flagged.
            ['YorvoID\\Authn\\StepUp\\CoolDownStore::purge() is a YorvoID store\'s: stores are for implementing, not calling. A call changes or reads YorvoID\'s records without asking YorvoID; use its services instead.', 81, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#interfaces-you-implement'],
            ['YorvoID\\Fake\\FakeStore::write() is a YorvoID store\'s: stores are for implementing, not calling. A call changes or reads YorvoID\'s records without asking YorvoID; use its services instead.', 84, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#interfaces-you-implement'],
            // A union whose first class isn't a store.
            ['YorvoID\\Fake\\FakeStore::write() is a YorvoID store\'s: stores are for implementing, not calling. A call changes or reads YorvoID\'s records without asking YorvoID; use its services instead.', 90, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#interfaces-you-implement'],
        ]);
    }

    public function testAClassInYorvoIDsNamespaceIsStillTheApplications(): void
    {
        $this->analyse([__DIR__.'/data/store-calls-spoofed.php'], [
            ['YorvoID\\Policy\\PolicyStore::version() is a YorvoID store\'s: stores are for implementing, not calling. A call changes or reads YorvoID\'s records without asking YorvoID; use its services instead.', 20, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#interfaces-you-implement'],
        ]);
    }

    public function testTheStoreContractsMayCallTheStoreTheyTest(): void
    {
        $this->analyse([__DIR__.'/data/store-contract.php', __DIR__.'/data/vendor/yorvoid/testing/CoolDownStoreChecks.php'], []);
    }

    public function testAFileBesideAPackageIsntInIt(): void
    {
        $this->analyse([__DIR__.'/data/vendor/yorvoid/testing-store-calls.php'], [
            ['YorvoID\\Fake\\FakeStore::write() is a YorvoID store\'s: stores are for implementing, not calling. A call changes or reads YorvoID\'s records without asking YorvoID; use its services instead.', 16, 'See https://github.com/yorvoid/yorvoid/blob/main/docs/compatibility.md#interfaces-you-implement'],
        ]);
    }
}
