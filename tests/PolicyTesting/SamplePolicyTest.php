<?php

declare(strict_types=1);

namespace YorvoID\Tests\PolicyTesting;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\PolicyTesting\PolicyCasesTest;
use YorvoID\PolicyTesting\PolicySuite;
use YorvoID\PolicyTesting\Symfony\PolicyKernel;
use YorvoID\PolicyTesting\Symfony\YorvoIDPolicyTestingBundle;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * The sample policy's cases, run as an application runs its own: with the PHPUnit trait, on the
 * Symfony application's container in the "yorvoid_policy" environment.
 */
final class SamplePolicyTest extends TestCase
{
    use PolicyCasesTest;

    private static ?string $directory = null;

    private static ?TestKernel $kernel = null;

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        new Filesystem()->remove((string) self::$directory);
    }

    protected static function policySuite(): PolicySuite
    {
        self::$directory = \sprintf('%s/yorvoid/policy-sample-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        self::$kernel = new TestKernel(self::$directory, environment: YorvoIDPolicyTestingBundle::ENVIRONMENT);

        return PolicyKernel::suite(self::$kernel);
    }
}
