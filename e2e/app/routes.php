<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use YorvoID\E2e\AppController;

return static function (RoutingConfigurator $routes): void {
    $routes->import(dirname(__DIR__, 2).'/packages/symfony/config/routes.php')->prefix('/yorvoid');
    $routes->add('home', '/')->controller([AppController::class, 'home'])->methods(['GET']);
    $routes->add('signed', '/signed')->controller([AppController::class, 'signed'])->methods(['GET']);
    $routes->add('e2e_reset', '/_e2e/reset')->controller([AppController::class, 'reset'])->methods(['POST']);
    $routes->add('e2e_setup_code', '/_e2e/setup-code')->controller([AppController::class, 'setupCode'])->methods(['POST']);
    $routes->add('e2e_recovery_link', '/_e2e/recovery-link')->controller([AppController::class, 'recoveryLink'])->methods(['POST']);
    $routes->add('e2e_add_key', '/_e2e/verifier/add-key')->controller([AppController::class, 'addKey'])->methods(['GET']);
    $routes->add('e2e_remove_site', '/_e2e/remove-site')->controller([AppController::class, 'removeSite'])->methods(['GET']);
};
