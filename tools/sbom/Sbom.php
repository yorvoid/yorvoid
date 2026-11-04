<?php

declare(strict_types=1);

namespace YorvoID\Tools\Sbom;

/**
 * Builds a package's CycloneDX 1.6 SBOM (see sbom.php).
 */
final class Sbom
{
    /**
     * @return array<string, mixed> The SBOM, ready for json_encode()
     */
    public static function of(string $root, string $directory, string $version, string $timestamp): array
    {
        if (1 !== preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/D', $version)) {
            throw new \RuntimeException(\sprintf('"%s" isn\'t a version, e.g. 0.2.0.', $version));
        }

        $directory = trim($directory, '/');

        return is_file(\sprintf('%s/%s/composer.json', $root, $directory))
            ? self::composer($root, $directory, $version, $timestamp)
            : self::npm($root, $directory, $version, $timestamp);
    }

    /**
     * @return array<string, mixed>
     */
    private static function composer(string $root, string $directory, string $version, string $timestamp): array
    {
        $package = self::json(\sprintf('%s/%s/composer.json', $root, $directory));
        $lock = self::json($root.'/composer.lock');
        $name = self::string($package, 'name');

        /** @var array<string, array<string, mixed>> $locked By name, and by every name it replaces or provides */
        $locked = [];

        foreach (self::list($lock, 'packages') as $entry) {
            foreach ([self::string($entry, 'name'), ...array_keys(self::map($entry, 'replace')), ...array_keys(self::map($entry, 'provide'))] as $alias) {
                $locked[$alias] ??= $entry;
            }
        }

        // Everything it needs, through what each of those needs; PHP and its extensions aren't packages.
        $needed = [];
        $dependencies = [];
        $queue = [[$name, $package]];

        while ([] !== $queue) {
            [$from, $entry] = array_shift($queue);
            $on = [];

            foreach (array_keys(self::map($entry, 'require')) as $required) {
                if (self::isPlatform($required) || !isset($locked[$required])) {
                    continue;
                }

                $resolved = $locked[$required];
                $resolvedName = self::string($resolved, 'name');
                $on[$resolvedName] = true;

                if (!isset($needed[$resolvedName]) && $resolvedName !== $name) {
                    $needed[$resolvedName] = $resolved;
                    $queue[] = [$resolvedName, $resolved];
                }
            }

            ksort($on);
            $dependencies[$from] = array_keys($on);
        }

        ksort($needed);
        $ref = static fn (string $n, string $v): string => \sprintf('pkg:composer/%s@%s', $n, $v);
        $versionOf = static fn (string $n): string => str_starts_with($n, 'yorvoid/') ? $version : self::version(self::string($needed[$n], 'version'));
        $components = [];

        foreach ($needed as $neededName => $entry) {
            $components[] = self::component($neededName, $versionOf($neededName), $ref($neededName, $versionOf($neededName)), self::licenses($entry), self::source($entry, $version));
        }

        $graph = [];

        foreach ($dependencies as $from => $on) {
            $graph[] = ['ref' => $ref($from, $from === $name ? $version : $versionOf($from)), 'dependsOn' => array_map(static fn (string $n): string => $ref($n, $versionOf($n)), $on)];
        }

        usort($graph, static fn (array $a, array $b): int => $a['ref'] <=> $b['ref']);

        return self::document($timestamp, self::component($name, $version, $ref($name, $version), self::licenses($package), \sprintf('https://github.com/yorvoid/yorvoid/tree/v%s/%s', $version, $directory)), $components, $graph);
    }

    /**
     * The browser client, which has no runtime dependencies.
     *
     * @return array<string, mixed>
     */
    private static function npm(string $root, string $directory, string $version, string $timestamp): array
    {
        $file = \sprintf('%s/%s/package.json', $root, $directory);

        if (!is_file($file)) {
            throw new \RuntimeException(\sprintf('%s is no package: it has neither composer.json nor package.json.', $directory));
        }

        $package = self::json($file);
        $name = self::string($package, 'name');

        if ([] !== self::map($package, 'dependencies')) {
            throw new \RuntimeException(\sprintf('%s has runtime dependencies now: list them in its SBOM.', $name));
        }

        $ref = \sprintf('pkg:npm/%s@%s', str_replace('@', '%40', $name), $version);
        $license = $package['license'] ?? null;

        return self::document($timestamp, self::component($name, $version, $ref, \is_string($license) ? [$license] : [], \sprintf('https://github.com/yorvoid/yorvoid/tree/v%s/%s', $version, $directory)), [], [['ref' => $ref, 'dependsOn' => []]]);
    }

    /**
     * @param array<string, mixed>                              $component
     * @param list<array<string, mixed>>                        $components
     * @param list<array{ref: string, dependsOn: list<string>}> $dependencies
     *
     * @return array<string, mixed>
     */
    private static function document(string $timestamp, array $component, array $components, array $dependencies): array
    {
        return [
            '$schema' => 'http://cyclonedx.org/schema/bom-1.6.schema.json',
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.6',
            'serialNumber' => 'urn:uuid:'.self::uuid(),
            'version' => 1,
            'metadata' => [
                'timestamp' => $timestamp,
                'lifecycles' => [['phase' => 'build']],
                'tools' => ['components' => [['type' => 'application', 'name' => 'yorvoid/yorvoid tools/sbom']]],
                'component' => $component,
            ],
            'components' => $components,
            'dependencies' => $dependencies,
        ];
    }

    /**
     * @param list<string> $licenses
     *
     * @return array<string, mixed>
     */
    private static function component(string $name, string $version, string $ref, array $licenses, ?string $source): array
    {
        $slash = strrpos($name, '/');
        $component = ['type' => 'library', 'bom-ref' => $ref];

        if (false !== $slash) {
            $component['group'] = substr($name, 0, $slash);
        }

        $component += ['name' => false === $slash ? $name : substr($name, $slash + 1), 'version' => $version, 'purl' => $ref];

        if ([] !== $licenses) {
            $component['licenses'] = array_map(static fn (string $id): array => ['license' => ['id' => $id]], $licenses);
        }

        if (null !== $source) {
            $component['externalReferences'] = [['type' => 'vcs', 'url' => $source]];
        }

        return $component;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<string>
     */
    private static function licenses(array $entry): array
    {
        $licenses = $entry['license'] ?? [];

        return array_values(array_filter(\is_array($licenses) ? $licenses : [$licenses], \is_string(...)));
    }

    /**
     * Where its code comes from: the commit a third-party package was tested at, or YorvoID's tag.
     *
     * @param array<string, mixed> $entry
     */
    private static function source(array $entry, string $version): ?string
    {
        $source = $entry['source'] ?? null;

        if (\is_array($source) && \is_string($source['url'] ?? null) && \is_string($source['reference'] ?? null)) {
            return $source['url'].'#'.$source['reference'];
        }

        $dist = $entry['dist'] ?? null;

        return \is_array($dist) && 'path' === ($dist['type'] ?? null) && \is_string($dist['url'] ?? null)
            ? \sprintf('https://github.com/yorvoid/yorvoid/tree/v%s/%s', $version, $dist['url'])
            : null;
    }

    private static function isPlatform(string $name): bool
    {
        return 'php' === $name || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-') || 'composer-plugin-api' === $name || 'composer-runtime-api' === $name;
    }

    private static function version(string $version): string
    {
        return ltrim($version, 'v');
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $file): array
    {
        return self::object(json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR)) ?? throw new \RuntimeException($file.' isn\'t an object.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : throw new \RuntimeException(\sprintf('"%s" is missing.', $key));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function map(array $data, string $key): array
    {
        return self::object($data[$key] ?? []) ?? [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private static function list(array $data, string $key): array
    {
        $list = [];

        foreach (\is_array($data[$key] ?? null) ? $data[$key] : [] as $item) {
            $object = self::object($item);

            if (null !== $object) {
                $list[] = $object;
            }
        }

        return $list;
    }

    /**
     * A JSON object as an array with string keys; null if it isn't one.
     *
     * @return array<string, mixed>|null
     */
    private static function object(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $object = [];

        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }
}
