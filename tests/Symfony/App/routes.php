<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use YorvoID\Tests\Symfony\App\IntentController;
use YorvoID\Tests\Symfony\App\MeController;

return static function (RoutingConfigurator $routes): void {
    $routes->import(dirname(__DIR__, 3).'/packages/symfony/config/routes.php')->prefix('/yorvoid');
    $routes->import(dirname(__DIR__, 3).'/packages/symfony/config/routes/gallery.php')->prefix('/_yorvoid/templates');
    // The firewall's logout answers it; the route only gives it a name.
    $routes->add('sign_out', '/sign-out')->methods(['GET']);
    $routes->add('me', '/me')->controller(MeController::class)->methods(['GET']);
    $routes->add('confirm_intent', '/confirm-intent')->controller(IntentController::class)->methods(['GET']);
};
