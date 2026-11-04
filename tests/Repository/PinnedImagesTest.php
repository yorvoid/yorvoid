<?php

declare(strict_types=1);

namespace YorvoID\Tests\Repository;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Every image the repository runs is pinned by digest, so a tag moved upstream changes nothing here
 * until a reviewed update does: Dockerfiles' base images, and the images compose.yaml pulls rather
 * than builds.
 */
final class PinnedImagesTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testDockerfilesStartFromADigest(): void
    {
        $finder = new Finder()->files()->in(self::ROOT)->name('Dockerfile')->exclude(['vendor', 'var'])->notPath('node_modules');
        self::assertGreaterThanOrEqual(6, \count($finder), 'The Dockerfiles moved.');

        foreach ($finder as $file) {
            preg_match_all('/^FROM\s+(\S+)/mi', $file->getContents(), $from);
            self::assertNotEmpty($from[1], $file->getRelativePathname());

            foreach ($from[1] as $image) {
                self::assertMatchesRegularExpression('/@sha256:[0-9a-f]{64}$/', $image, $file->getRelativePathname());
            }
        }
    }

    public function testComposePullsOnlyDigests(): void
    {
        $compose = Yaml::parseFile(self::ROOT.'/compose.yaml');
        self::assertIsArray($compose);
        self::assertIsArray($compose['services'] ?? null);

        foreach ($compose['services'] as $name => $service) {
            self::assertIsArray($service);

            if (isset($service['build'])) {
                continue;
            }

            self::assertIsString($service['image'] ?? null, (string) $name);
            self::assertMatchesRegularExpression('/(^\$\{IMAGES_PREFIX:-\}yorvoid-|@sha256:[0-9a-f]{64}$)/', $service['image'], \sprintf('The service %s pulls an image by tag.', $name));
        }
    }
}
