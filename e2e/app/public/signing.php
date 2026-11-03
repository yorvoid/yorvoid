<?php

declare(strict_types=1);

use YorvoID\E2e\Signing;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// The signing origin's front controller: the privileged side's pages, with no framework.
$path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', \PHP_URL_PATH);
/** @var array<string, mixed> $form */
$form = $_POST;
$response = Signing::pages()->handle(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET', is_string($path) ? $path : '/', $form);
http_response_code($response->status);

foreach ($response->headers as $name => $value) {
    header($name.': '.$value);
}

echo $response->body;
