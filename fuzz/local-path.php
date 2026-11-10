<?php

declare(strict_types=1);

/** @var PhpFuzzer\Config $config */

use YorvoID\Symfony\LocalPath;

require __DIR__.'/bootstrap.php';

/*
 * A path LocalPath accepts must stay on the site whichever way a browser reads it (the URL standard):
 * leading and trailing spaces and controls dropped, tabs and line breaks dropped anywhere, "\" read as
 * "/". Then it must still be a path: one "/" first, and no scheme.
 */
$config->setTarget(static function (string $input): void {
    if (!LocalPath::is($input)) {
        return;
    }

    $read = str_replace('\\', '/', str_replace(["\t", "\n", "\r"], '', trim($input, "\x00..\x20")));

    if (!str_starts_with($read, '/') || str_starts_with($read, '//') || 1 === preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $read)) {
        throw new Error(sprintf('LocalPath accepted %s, which a browser reads as another site: %s', json_encode($input), json_encode($read)));
    }
});
$config->addDictionary(__DIR__.'/local-path.dict');
$config->setMaxLen(64);
