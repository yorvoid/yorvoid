<?php

declare(strict_types=1);

// PHP's built-in server: the signing origin (sign.localhost) to the signing pages; on the console's,
// the browser client's script as the bridge ships it, and everything else to the application.
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($uri) ? $uri : '/', \PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

$host = $_SERVER['HTTP_HOST'] ?? '';

if (is_string($host) && str_starts_with($host, 'sign.localhost')) {
    require __DIR__.'/public/signing.php';

    return true;
}

if (1 === preg_match('#^/bundles/yorvoid/browser/([a-z0-9]+\.js)$#D', $path, $match)) {
    $file = dirname(__DIR__, 2).'/packages/symfony/public/browser/'.$match[1];

    if (is_file($file)) {
        header('Content-Type: text/javascript');
        readfile($file);

        return true;
    }
}

require __DIR__.'/public/index.php';

return true;
