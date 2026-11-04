<?php

declare(strict_types=1);

namespace YorvoID\Tests\Tools\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use YorvoID\Tools\PHPStan\InternalAcrossPackagesRule;

/**
 * In a process of its own, as SecretComparisonRuleTest.
 *
 * @extends RuleTestCase<InternalAcrossPackagesRule>
 */
#[RunTestsInSeparateProcesses]
final class InternalAcrossPackagesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new InternalAcrossPackagesRule($this->createReflectionProvider());
    }

    public function testRefusesAnotherPackagesInternals(): void
    {
        $this->analyse([__DIR__.'/data/internal-across-packages.php'], [
            ['YorvoID\Core\Migrations\CreatePrincipals is internal to yorvoid/core: mark it @api, or keep it out of yorvoid/throttle.', 7],
            ['YorvoID\Core\Migrations\CreateSecurityLog is internal to yorvoid/core: mark it @api, or keep it out of yorvoid/throttle.', 12],
            ['YorvoID\Core\Migrations\CreatePrincipals is internal to yorvoid/core: mark it @api, or keep it out of yorvoid/throttle.', 12],
        ]);
    }

    public function testTestsMayUseInternals(): void
    {
        $this->analyse([__DIR__.'/data/internal-in-tests.php'], []);
    }
}
