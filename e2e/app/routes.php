<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use YorvoID\E2e\Acme\AcmeController;
use YorvoID\E2e\AppController;

return static function (RoutingConfigurator $routes): void {
    $routes->import(dirname(__DIR__, 2).'/packages/symfony/config/routes.php')->prefix('/yorvoid');
    // Acme Team, the application the usability test's participants use (M2-10).
    $routes->add('home', '/')->controller([AcmeController::class, 'home'])->methods(['GET']);
    $routes->add('projects', '/projects')->controller([AcmeController::class, 'projects'])->methods(['GET']);
    $routes->add('project_remove', '/projects/{project}/remove')->controller([AcmeController::class, 'remove'])->methods(['POST'])->requirements(['project' => '[a-z0-9-]+']);
    $routes->add('account', '/account')->controller([AcmeController::class, 'account'])->methods(['GET']);
    $routes->add('account_email', '/account/email')->controller([AcmeController::class, 'changeEmail'])->methods(['POST']);
    $routes->add('sign_out', '/sign-out')->methods(['POST']);
    $routes->add('signed', '/signed')->controller([AppController::class, 'signed'])->methods(['GET']);
    $routes->add('e2e_reset', '/_e2e/reset')->controller([AppController::class, 'reset'])->methods(['POST']);
    $routes->add('e2e_clock', '/_e2e/clock')->controller([AppController::class, 'clock'])->methods(['POST']);
    $routes->add('e2e_setup_code', '/_e2e/setup-code')->controller([AppController::class, 'setupCode'])->methods(['POST']);
    $routes->add('e2e_recovery_link', '/_e2e/recovery-link')->controller([AppController::class, 'recoveryLink'])->methods(['POST']);
    $routes->add('e2e_bootstrap_secret', '/_e2e/verifier/bootstrap-secret')->controller([AppController::class, 'bootstrapSecret'])->methods(['POST']);
    $routes->add('e2e_remove_site', '/_e2e/remove-site')->controller([AppController::class, 'removeSite'])->methods(['GET']);
};
