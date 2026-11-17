<?php

declare(strict_types=1);

namespace YorvoID\PhpstanRules;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;

/**
 * Whether code is YorvoID's own: by where its file is, in YorvoID's installed packages, not by its
 * namespace, which any application can claim. The code PHPStan analyses is the application's, so this
 * is about code it reads from there: the store contracts' traits in yorvoid/testing, which an
 * application's own store tests use.
 */
final readonly class YorvoIDCode
{
    /** @var list<string> Each of YorvoID's packages' directories, with links resolved, ending in "/" */
    private array $directories;

    /**
     * @param list<string> $vendors Where YorvoID's packages are installed: vendor/yorvoid
     */
    public function __construct(array $vendors)
    {
        $directories = [];

        foreach ($vendors as $vendor) {
            $packages = glob($vendor.'/*', \GLOB_ONLYDIR);

            foreach (false === $packages ? [] : $packages as $package) {
                // Composer links a path repository's package: its files report their real path.
                $real = realpath($package);

                if (false !== $real) {
                    $directories[] = $real.'/';
                }
            }
        }

        $this->directories = $directories;
    }

    /**
     * Whether the code at this point is in one of YorvoID's packages: a trait's code, where it's from.
     */
    public function contains(Scope $scope): bool
    {
        $trait = $scope->getTraitReflection();
        $file = null === $trait ? $scope->getFile() : $trait->getFileName();

        return null !== $file && $this->has($file);
    }

    /**
     * Whether a class is one of YorvoID's, by its file.
     */
    public function declares(ClassReflection $class): bool
    {
        $file = $class->getFileName();

        return null !== $file && $this->has($file);
    }

    private function has(string $file): bool
    {
        $real = realpath($file);

        foreach ($this->directories as $directory) {
            if (str_starts_with(false === $real ? $file : $real, $directory)) {
                return true;
            }
        }

        return false;
    }
}
