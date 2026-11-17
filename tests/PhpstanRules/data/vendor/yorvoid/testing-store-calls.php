<?php

declare(strict_types=1);

namespace App;

use YorvoID\Fake\FakeStore;

/**
 * The application's, though its path begins as a package's (vendor/yorvoid/testing) does.
 */
final readonly class BesideAPackage
{
    public function handle(FakeStore $store): void
    {
        $store->write();
    }
}
