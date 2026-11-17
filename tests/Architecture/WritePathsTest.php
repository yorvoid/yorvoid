<?php

declare(strict_types=1);

namespace YorvoID\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * Every public method of the services the Symfony bundle gives applications either takes a Permit,
 * is @internal, or is classified in WritesWithoutPermit: a read, a write that logs, or
 * bookkeeping (D-41). A new one fails here until someone says which: it's where M3's grants and
 * budgets meet the rule. The test can't tell a read from a write, so the list is reviewed like code.
 */
final class WritePathsTest extends TestCase
{
    public function testEveryPublicMethodWithoutAPermitIsClassified(): void
    {
        $directory = sys_get_temp_dir().'/yorvoid-write-paths-'.bin2hex(random_bytes(4));
        $kernel = new TestKernel($directory);

        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            \assert($container instanceof Container);
            $methods = PublicMethodsWithoutPermit::of($container);
        } finally {
            $kernel->shutdown();
            new Filesystem()->remove($directory);
        }

        $listed = array_keys(WritesWithoutPermit::METHODS);
        sort($listed);

        self::assertSame($listed, $methods, 'A public method without a permit isn\'t classified (or is gone): add it to WritesWithoutPermit, with what it is.');
    }
}
