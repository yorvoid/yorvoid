<?php

declare(strict_types=1);

namespace YorvoID\Tests\PolicyTesting;

use PHPUnit\Framework\TestCase;

/**
 * vendor/bin/yorvoid-policy: the cases of an application without Symfony, from its bootstrap file.
 */
final class BinTest extends TestCase
{
    public function testRunsTheBootstrapsCases(): void
    {
        [$status, $output] = self::bin(['test', '--bootstrap='.__DIR__.'/Fixtures/bootstrap.php']);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('✔ An editor publishes', $output);
        self::assertStringContainsString('1 cases, 0 failed.', $output);

        [$status, $output] = self::bin(['--bootstrap='.__DIR__.'/Fixtures/bootstrap.php', '--format=json'], ['YORVOID_POLICY_FAIL' => '1']);
        self::assertSame(1, $status, 'test is the default command; a failing case fails it.');
        self::assertStringContainsString('"failed": 1', $output);
    }

    public function testMutatesThePolicy(): void
    {
        [$status, $output] = self::bin(['mutate', '--bootstrap='.__DIR__.'/Fixtures/bootstrap.php']);
        self::assertSame(1, $status, 'One case catches few mutants.');
        self::assertStringContainsString('widen:acme.pages.delete:member: The role "member" has "acme.pages.delete". No case catches it.', $output);

        [$status, $output] = self::bin(['mutate', '--bootstrap='.__DIR__.'/Fixtures/bootstrap.php', '--min-score=0', '--ignore=widen:acme.pages.delete:member', '--ignore=widen:acme.pages.delete:manager', '--format=json']);
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('"narrow:acme.pages.publish:editor": "An editor publishes"', $output);
        self::assertStringContainsString('"id": "widen:acme.pages.delete:member"', $output, 'Each ignored one…');
        self::assertStringContainsString('"id": "widen:acme.pages.delete:manager"', $output, '…of several.');
    }

    public function testRefusesWhatIsntABootstrap(): void
    {
        [$status, $output] = self::bin(['test']);
        self::assertSame(2, $status);
        self::assertStringContainsString('Give the file that returns the PolicySuite: --bootstrap=tests/policy.php', $output);

        [$status, $output] = self::bin(['test', '--bootstrap='.__FILE__.'.missing']);
        self::assertSame(2, $status);

        [$status, $output] = self::bin(['test', '--bootstrap='.__DIR__.'/Fixtures/AcmeCases.php']);
        self::assertSame(1, $status);
        self::assertStringContainsString('AcmeCases.php returns int, not a PolicySuite.', $output);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     *
     * @return array{int, string}
     */
    private static function bin(array $arguments, array $environment = []): array
    {
        $process = proc_open([\PHP_BINARY, '-d', 'auto_prepend_file='.\dirname(__DIR__).'/bootstrap.php', \dirname(__DIR__, 2).'/packages/policy-testing/bin/yorvoid-policy', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment + ['COLUMNS' => '1000'] + getenv());
        \assert(\is_resource($process));
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
