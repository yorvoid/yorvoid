<?php

declare(strict_types=1);

namespace YorvoID\Tests\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Tools\Sbom\Sbom;

require_once __DIR__.'/../../tools/sbom/Sbom.php';

/**
 * tools/sbom: each released package's CycloneDX SBOM lists what it needs, at the tested versions.
 */
final class SbomTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * @return iterable<string, array{string}>
     */
    public static function packages(): iterable
    {
        foreach ((array) glob(self::ROOT.'/packages/*', \GLOB_ONLYDIR) as $directory) {
            \assert(\is_string($directory));

            yield basename($directory) => ['packages/'.basename($directory)];
        }
    }

    #[DataProvider('packages')]
    public function testEveryPackageHasOne(string $directory): void
    {
        $sbom = Sbom::of(self::ROOT, $directory, '0.2.0', '2026-11-04T18:00:00Z');

        self::assertSame(['CycloneDX', '1.6', 1], [$sbom['bomFormat'], $sbom['specVersion'], $sbom['version']]);
        self::assertIsArray($sbom['metadata']);
        self::assertIsArray($sbom['metadata']['component']);
        self::assertSame('0.2.0', $sbom['metadata']['component']['version']);
        self::assertSame([['license' => ['id' => 'Apache-2.0']]], $sbom['metadata']['component']['licenses']);
        self::assertIsArray($sbom['components']);
        self::assertIsArray($sbom['dependencies']);

        // Every component is in the graph, and the graph names only components.
        $refs = array_map(static fn (mixed $component): mixed => \is_array($component) ? $component['bom-ref'] : null, $sbom['components']);
        $refs[] = $sbom['metadata']['component']['bom-ref'];
        $graph = array_map(static fn (mixed $node): mixed => \is_array($node) ? $node['ref'] : null, $sbom['dependencies']);
        sort($refs);
        sort($graph);
        self::assertSame($refs, $graph);

        foreach ($sbom['dependencies'] as $node) {
            self::assertIsArray($node);
            self::assertIsArray($node['dependsOn']);

            foreach ($node['dependsOn'] as $on) {
                self::assertContains($on, $refs);
            }
        }
    }

    public function testPasskeysListsWhatItNeedsAtTheTestedVersions(): void
    {
        $sbom = Sbom::of(self::ROOT, 'packages/passkeys', '0.2.0', '2026-11-04T18:00:00Z');
        $lock = json_decode((string) file_get_contents(self::ROOT.'/composer.lock'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);
        self::assertIsArray($lock['packages']);
        $webauthn = array_values(array_filter($lock['packages'], static fn (mixed $p): bool => \is_array($p) && 'web-auth/webauthn-lib' === $p['name']))[0] ?? null;
        self::assertIsArray($webauthn);
        self::assertIsString($webauthn['version']);
        self::assertIsArray($sbom['components']);
        $purls = array_map(static fn (mixed $c): mixed => \is_array($c) ? $c['purl'] : null, $sbom['components']);

        self::assertContains('pkg:composer/web-auth/webauthn-lib@'.$webauthn['version'], $purls);
        self::assertContains('pkg:composer/yorvoid/core@0.2.0', $purls, 'YorvoID\'s own packages are released with it.');
        self::assertSame([], preg_grep('#^pkg:composer/phpunit/#', array_filter($purls, \is_string(...))), 'A development tool is listed.');
    }

    public function testTheBrowserClientHasNoDependencies(): void
    {
        $sbom = Sbom::of(self::ROOT, 'packages/browser', '0.2.0', '2026-11-04T18:00:00Z');

        self::assertSame([], $sbom['components']);
        self::assertIsArray($sbom['metadata']);
        self::assertIsArray($sbom['metadata']['component']);
        self::assertSame('pkg:npm/%40yorvoid/browser@0.2.0', $sbom['metadata']['component']['purl']);
    }

    public function testRefusesWhatIsntAVersionOrAPackage(): void
    {
        foreach ([['packages/core', 'v0.2.0'], ['packages/core', '0.2'], ['docs', '0.2.0']] as [$directory, $version]) {
            try {
                Sbom::of(self::ROOT, $directory, $version, '2026-11-04T18:00:00Z');
                self::fail(\sprintf('%s %s was taken.', $directory, $version));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
