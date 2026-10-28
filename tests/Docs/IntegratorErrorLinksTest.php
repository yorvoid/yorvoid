<?php

declare(strict_types=1);

namespace YorvoID\Tests\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Every IntegratorError links to a page of the documentation (PLT-085). A link to a page or heading
 * that doesn't exist would leave a developer with a dead end, so each one is checked here.
 */
final class IntegratorErrorLinksTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testEveryLinkPointsToAnExistingPageAndHeading(): void
    {
        $links = $this->links();
        self::assertNotEmpty($links, 'No IntegratorError links found; has the way they are written changed?');

        foreach ($links as $link => $where) {
            [$page, $anchor] = explode('#', $link, 2) + [1 => null];
            $path = self::ROOT.'/docs/'.$page;

            self::assertFileExists($path, \sprintf('%s links to docs/%s, which doesn\'t exist.', $where, $page));

            if (null !== $anchor) {
                self::assertContains($anchor, $this->anchors((string) file_get_contents($path)), \sprintf('%s links to docs/%s, which has no heading for it.', $where, $link));
            }
        }
    }

    /**
     * @return array<string, string> Link => the first file that uses it
     */
    private function links(): array
    {
        $links = [];
        $files = new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/packages', \FilesystemIterator::SKIP_DOTS)), '/\/src\/.+\.php$/');

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            // The docs link is the last argument: a quoted path ending in .md, optionally with an anchor.
            preg_match_all("/new IntegratorError\\(.*'([a-z0-9\\/-]+\\.md(?:#[a-z0-9-]+)?)'\\)/", (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $link) {
                $links[$link] ??= substr($file->getPathname(), \strlen(self::ROOT) + 1);
            }
        }

        return $links;
    }

    /**
     * GitHub's anchors: headings lowercased, punctuation dropped, spaces as hyphens.
     *
     * @return list<string>
     */
    private function anchors(string $markdown): array
    {
        preg_match_all('/^#{1,6} (.+)$/m', $markdown, $headings);

        return array_map(static fn (string $heading): string => str_replace(' ', '-', (string) preg_replace('/[^a-z0-9 -]/', '', strtolower(trim($heading)))), $headings[1]);
    }
}
