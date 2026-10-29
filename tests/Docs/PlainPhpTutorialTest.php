<?php

declare(strict_types=1);

namespace YorvoID\Tests\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * docs/tutorials/plain-php.md runs as it's written: its PHP blocks, in order, are one script; the
 * page's hidden "test" comments stand in for the browser and the requests between the blocks.
 */
final class PlainPhpTutorialTest extends TestCase
{
    private const string TUTORIAL = __DIR__.'/../../docs/tutorials/plain-php.md';

    public function testTheTutorialRunsAsWritten(): void
    {
        $directory = \sprintf('%s/yorvoid-tutorial-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        mkdir($directory);

        try {
            file_put_contents($directory.'/tutorial.php', self::script());
            exec(\sprintf('cd %s && php -d zend.assertions=1 -d assert.exception=1 -d output_buffering=65536 tutorial.php 2>&1', escapeshellarg($directory)), $output, $status);

            self::assertSame(0, $status, implode("\n", $output));
            self::assertSame('The tutorial works.', end($output));
        } finally {
            new Filesystem()->remove($directory);
        }
    }

    /**
     * The page's PHP blocks and test comments, in order, as one script on this repository's autoloader.
     */
    private static function script(): string
    {
        preg_match_all('/```php\n(?<code>.*?)```|<!-- test\n(?<glue>.*?)-->/s', (string) file_get_contents(self::TUTORIAL), $parts, \PREG_SET_ORDER);
        $script = implode("\n", array_map(static fn (array $part): string => ($part['code'] ?? '').($part['glue'] ?? ''), $parts));
        self::assertStringStartsWith("<?php\n", $script, 'The tutorial\'s first PHP block starts the script.');

        // The "use" lines of later blocks, moved to the top, where PHP wants them.
        preg_match_all('/^use [^;]+;$/m', $script, $uses);
        $script = (string) preg_replace('/^use [^;]+;\n/m', '', $script);

        return str_replace(["<?php\n", "require 'vendor/autoload.php';"], ["<?php\n".implode("\n", array_unique($uses[0]))."\n", \sprintf('require %s;', var_export(\dirname(__DIR__, 2).'/vendor/autoload.php', true))], $script);
    }
}
