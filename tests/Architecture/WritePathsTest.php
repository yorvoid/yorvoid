<?php

declare(strict_types=1);

namespace YorvoID\Tests\Architecture;

use PHPUnit\Framework\TestCase;
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
        $collect = new PublicMethodsWithoutPermit();
        $kernel = new TestKernel($directory, passes: [$collect]);

        try {
            $kernel->boot();
            $methods = $collect->methods;
        } finally {
            $kernel->shutdown();
            new Filesystem()->remove($directory);
        }

        $listed = array_keys(WritesWithoutPermit::METHODS);
        sort($listed);

        self::assertSame($listed, $methods, 'A public method without a permit isn\'t classified (or is gone): add it to WritesWithoutPermit, with what it is.');
    }

    /**
     * A "logs" entry is a claim the threat model rests on (:120): it names the test that asserts the
     * entry, and that test exists.
     */
    public function testEachWriteThatLogsNamesTheTestThatShowsIt(): void
    {
        foreach (WritesWithoutPermit::METHODS as $method => $entry) {
            if ('logs' === $entry[0]) {
                [$class, $test] = explode('::', $entry[2]) + [1 => ''];
                self::assertTrue(method_exists($class, $test), $method.': its test '.$entry[2].' doesn\'t exist.');
            }
        }
    }

    /**
     * A listener writes on an event: only YorvoID may make it, so its constructor is @internal, and an
     * application's code that makes one is flagged (yorvoid.internalUse).
     */
    public function testEachListenersEventIsOneOnlyYorvoIDMakes(): void
    {
        foreach (WritesWithoutPermit::METHODS as $method => $entry) {
            if ('event' === $entry[0]) {
                $constructor = new \ReflectionClass($entry[2])->getConstructor();
                self::assertNotNull($constructor, $entry[2]);
                self::assertStringContainsString('@internal', (string) $constructor->getDocComment(), $method.': '.$entry[2].'\'s constructor isn\'t @internal.');
            }
        }
    }
}
