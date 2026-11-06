<?php

declare(strict_types=1);

namespace YorvoID\Tests\Tools\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use YorvoID\Tools\PHPStan\ApiExposesInternalRule;

/**
 * In a process of its own, as SecretComparisonRuleTest.
 *
 * @extends RuleTestCase<ApiExposesInternalRule>
 */
#[RunTestsInSeparateProcesses]
final class ApiExposesInternalRuleTest extends RuleTestCase
{
    private const string MESSAGE = '%s is @api but names YorvoID\Tests\Tools\PHPStan\data\ApiExposesInternal\Hidden, which is @internal: mark the member @internal, or the class @api.';

    protected function getRule(): Rule
    {
        return new ApiExposesInternalRule($this->createReflectionProvider());
    }

    public function testRefusesInternalTypesInTheApi(): void
    {
        $data = __DIR__.'/data/ApiExposesInternal';

        $this->analyse(["$data/Exposes.php", "$data/Allowed.php", "$data/Untagged.php", "$data/Hidden.php", "$data/Shown.php"], [
            [\sprintf(self::MESSAGE, 'YorvoID\Tests\Tools\PHPStan\data\ApiExposesInternal\Exposes::$field'), 10],
            [\sprintf(self::MESSAGE, 'YorvoID\Tests\Tools\PHPStan\data\ApiExposesInternal\Exposes::$listed'), 10],
            [\sprintf(self::MESSAGE, 'YorvoID\Tests\Tools\PHPStan\data\ApiExposesInternal\Exposes::__construct()'), 17],
            [\sprintf(self::MESSAGE, 'YorvoID\Tests\Tools\PHPStan\data\ApiExposesInternal\Exposes::give()'), 21],
            [\sprintf(self::MESSAGE, 'YorvoID\Tests\Tools\PHPStan\data\ApiExposesInternal\Exposes::all()'), 29],
            [\sprintf(self::MESSAGE, 'YorvoID\Tests\Tools\PHPStan\data\ApiExposesInternal\Exposes::take()'), 34],
            [\sprintf(self::MESSAGE, 'YorvoID\Tests\Tools\PHPStan\data\ApiExposesInternal\Exposes::extend()'), 38],
        ]);
    }
}
