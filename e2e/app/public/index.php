<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Request;
use YorvoID\E2e\Kernel;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$kernel = new Kernel();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
