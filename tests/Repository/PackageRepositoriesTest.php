<?php

declare(strict_types=1);

namespace YorvoID\Tests\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each directory of packages/ is split off into a read-only repository of its own, which Composer
 * installs it from. Whatever such a repository needs on its own (a README, the license, the way back
 * to this repository) has to be in the directory, since nothing else ends up there.
 */
final class PackageRepositoriesTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private const string MONOREPO = 'https://github.com/yorvoid/yorvoid';

    public function testEveryComposerPackageIsSplitOff(): void
    {
        $files = glob(self::ROOT.'/packages/*/composer.json');
        \assert(\is_array($files));
        $directories = array_map(static fn (string $file): string => 'packages/'.basename(\dirname($file)), $files);

        self::assertSame($directories, array_keys(self::split()), 'Every package with a composer.json needs an entry in .github/split.json, in order.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function packages(): iterable
    {
        foreach (self::split() as $path => $repository) {
            yield $repository => [$path];
        }
    }

    #[DataProvider('packages')]
    public function testShipsTheLicenseAndTheNotice(string $path): void
    {
        self::assertFileEquals(self::ROOT.'/LICENSE', self::ROOT.'/'.$path.'/LICENSE');
        self::assertFileEquals(self::ROOT.'/NOTICE', self::ROOT.'/'.$path.'/NOTICE', 'Apache 2.0 has the NOTICE go along with the code (its section 4).');
    }

    #[DataProvider('packages')]
    public function testLeavesItsGitHubFilesOutOfArchives(string $path): void
    {
        self::assertFileEquals(self::ROOT.'/packages/core/.gitattributes', self::ROOT.'/'.$path.'/.gitattributes');
        self::assertStringContainsString("/.github export-ignore\n", (string) file_get_contents(self::ROOT.'/'.$path.'/.gitattributes'));
    }

    #[DataProvider('packages')]
    public function testClosesPullRequestsOpenedInItsRepository(string $path): void
    {
        self::assertFileEquals(self::ROOT.'/packages/core/.github/workflows/close-pull-request.yaml', self::ROOT.'/'.$path.'/.github/workflows/close-pull-request.yaml');
    }

    #[DataProvider('packages')]
    public function testLinksBackToThisRepository(string $path): void
    {
        $repository = self::split()[$path];
        $composer = self::json($path.'/composer.json');

        self::assertSame($repository, $composer['name'] ?? null);
        self::assertSame('Apache-2.0', $composer['license'] ?? null);
        self::assertSame([
            'issues' => self::MONOREPO.'/issues',
            'source' => self::MONOREPO.'/tree/main/'.$path,
            'docs' => self::MONOREPO.'/tree/main/docs',
            'security' => self::MONOREPO.'/security/policy',
        ], $composer['support'] ?? null);
    }

    #[DataProvider('packages')]
    public function testHasAReadmeThatSaysHowToInstallItAndWhereItIsDeveloped(string $path): void
    {
        $repository = self::split()[$path];
        $readme = (string) file_get_contents(self::ROOT.'/'.$path.'/README.md');

        self::assertStringStartsWith('# '.$repository."\n", $readme);
        self::assertMatchesRegularExpression('/^composer require (--dev )?'.preg_quote($repository, '/').'$/m', $readme);
        self::assertStringContainsString('['.$path.']('.self::MONOREPO.'/tree/main/'.$path.')', str_replace('`', '', $readme));
        self::assertStringContainsString('[Apache License 2.0](LICENSE)', $readme);
        self::assertStringContainsString('[NOTICE](NOTICE)', $readme);

        preg_match_all('#\('.preg_quote(self::MONOREPO, '#').'/blob/main/(docs/[^)\#]+)(?:\#([^)]+))?\)#', $readme, $links, \PREG_SET_ORDER);
        self::assertNotEmpty($links, 'The README links to a page of the documentation.');
        foreach ($links as $link) {
            self::assertFileExists(self::ROOT.'/'.$link[1]);
            if (isset($link[2])) {
                // GitHub's anchors: headings lowercased, punctuation dropped, spaces as hyphens.
                preg_match_all('/^#{1,6} (.+)$/m', (string) file_get_contents(self::ROOT.'/'.$link[1]), $headings);
                $anchors = array_map(static fn (string $heading): string => str_replace(' ', '-', (string) preg_replace('/[^a-z0-9 -]/', '', strtolower(trim($heading)))), $headings[1]);
                self::assertContains($link[2], $anchors, \sprintf('%s links to %s#%s, which has no heading for it.', $path, $link[1], $link[2]));
            }
        }
    }

    public function testTheBrowserClientShipsTheNotice(): void
    {
        self::assertFileEquals(self::ROOT.'/NOTICE', self::ROOT.'/packages/browser/NOTICE');
        // npm always packs the README and the LICENSE, but a NOTICE only when it's listed.
        $files = self::json('packages/browser/package.json')['files'] ?? null;
        \assert(\is_array($files));
        self::assertContains('NOTICE', $files);
    }

    /**
     * @return array<string, string> Directory => repository
     */
    private static function split(): array
    {
        $split = [];
        foreach (self::json('.github/split.json') as $entry) {
            \assert(\is_array($entry) && \is_string($entry['path']) && \is_string($entry['repository']));
            $split[$entry['path']] = $entry['repository'];
        }

        return $split;
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $file): array
    {
        $json = json_decode((string) file_get_contents(self::ROOT.'/'.$file), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($json));

        return $json;
    }
}
