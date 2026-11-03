<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

/**
 * Whether YorvoID's test helpers are installed outside development: yorvoid/testing signs anyone in,
 * and the virtual authenticator keeps private keys in plain sight. They belong in require-dev.
 *
 * @internal
 */
final readonly class TestHelpersCheck implements Check
{
    /** A class of each test helper package */
    private const array HELPERS = [
        'yorvoid/testing' => 'YorvoID\Testing\Scenario',
        'yorvoid/virtual-authenticator' => 'YorvoID\VirtualAuthenticator\VirtualAuthenticator',
    ];

    public function __construct(
        private bool $debug,
    ) {
    }

    public function findings(): iterable
    {
        if ($this->debug) {
            return;
        }

        foreach (self::HELPERS as $package => $class) {
            if (class_exists($class)) {
                yield Finding::problem(\sprintf('%s is installed outside development: it\'s for tests, and makes signing in or keys easy for anyone who can run code here.', $package), \sprintf('Install it with composer require --dev %s, and deploy with composer install --no-dev.', $package), 'concepts/testing.md');
            }
        }
    }
}
