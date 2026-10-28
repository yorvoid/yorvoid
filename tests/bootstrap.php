<?php

declare(strict_types=1);

use Doctrine\Deprecations\Deprecation;

require dirname(__DIR__).'/vendor/autoload.php';

// Doctrine reports its deprecations only when asked; as errors, PHPUnit fails on them, so YorvoID
// never builds on an API that the next major version removes.
Deprecation::enableWithTriggerError();
