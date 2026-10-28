<?php

declare(strict_types=1);

$finder = new PhpCsFixer\Finder()
    ->in(__DIR__)
    ->exclude(['var', 'vendor', 'packages/browser/node_modules'])
    // Code that breaks the rules on purpose, to test them.
    ->notPath('tests/Tools/PHPStan/data');

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@PER-CS' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'final_class' => true,
    ])
    ->setFinder($finder);
