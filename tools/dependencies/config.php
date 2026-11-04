<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// check.sh runs this once per package, naming it in YORVOID_PACKAGE.
$package = (string) getenv('YORVOID_PACKAGE');
$config = new Configuration();
$config->disableReportingUnmatchedIgnores();

// A package's own classes resolve through vendor/yorvoid/<package>, a link to it.
$config->ignoreErrorsOnPackage('yorvoid/'.$package, [ErrorType::SHADOW_DEPENDENCY]);

// What's used only when it's installed: each is a "suggest", and the code checks for it first.
$optional = [
    'intent-verifier' => ['ext-posix'],
    'passkeys' => ['symfony/translation'],
    'symfony' => ['symfony/mailer', 'symfony/mime', 'symfony/translation', 'symfony/var-dumper', 'twig/twig'],
    'testing' => ['symfony/framework-bundle', 'yorvoid/symfony'],
];

foreach ($optional[$package] ?? [] as $suggested) {
    str_starts_with($suggested, 'ext-')
        ? $config->ignoreErrorsOnExtension($suggested, [ErrorType::SHADOW_DEPENDENCY])
        : $config->ignoreErrorsOnPackage($suggested, [ErrorType::SHADOW_DEPENDENCY]);
}

// Required for what they bring rather than for classes: the security bundle the firewall needs, the
// translations the bundle registers.
if ('symfony' === $package) {
    $config->ignoreErrorsOnPackage('symfony/security-bundle', [ErrorType::UNUSED_DEPENDENCY]);
    $config->ignoreErrorsOnPackage('yorvoid/translation', [ErrorType::UNUSED_DEPENDENCY]);
    $config->ignoreErrorsOnPackage('doctrine/doctrine-bundle', [ErrorType::UNUSED_DEPENDENCY]);
    $config->ignoreErrorsOnPackage('symfony/framework-bundle', [ErrorType::UNUSED_DEPENDENCY]);
}

return $config;
