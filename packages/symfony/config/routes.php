<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use YorvoID\Symfony\Controller\CeremonyController;
use YorvoID\Symfony\Controller\FlowController;

/*
 * YorvoID's endpoints, for the browser client. Import them with a prefix of your choice:
 *
 *   # config/routes/yorvoid.yaml
 *   yorvoid:
 *       resource: '@YorvoIDBundle/config/routes.php'
 *       prefix: /yorvoid
 *
 * Signing in and recovering finish in YorvoID's authenticators, in your firewall: their routes only
 * answer when an authenticator isn't set up. The flows' pages are at /flows/{flow}, e.g.
 * /yorvoid/flows/yorvoid.sign_in.
 */
return static function (RoutingConfigurator $routes): void {
    $post = static fn (string $name, string $path, string $action) => $routes->add($name, $path)->controller([CeremonyController::class, $action])->methods(['POST'])->format('json');

    $post('yorvoid_sign_in_options', '/sign-in/options', 'signInOptions');
    $post('yorvoid_sign_in', '/sign-in', 'notConfigured');
    $post('yorvoid_recover_with_code', '/recover/code', 'notConfigured');
    $post('yorvoid_recover_with_link', '/recover/link', 'notConfigured');
    $post('yorvoid_passkey_options', '/passkeys/options', 'registrationOptions');
    $post('yorvoid_passkey_register', '/passkeys', 'register');
    $post('yorvoid_passkey_signals', '/passkeys/signals', 'signals');
    $post('yorvoid_step_up_options', '/step-up/options', 'stepUpOptions');
    $post('yorvoid_step_up', '/step-up', 'stepUp');

    // Flows: pages, or the browser client's JSON.
    $routes->add('yorvoid_flow_start', '/flows/{flow}')->controller([FlowController::class, 'start'])->methods(['GET', 'POST'])->requirements(['flow' => '[a-z0-9_.]+']);
    $routes->add('yorvoid_flow_show', '/flows/{flow}/{id}')->controller([FlowController::class, 'show'])->methods(['GET'])->requirements(['flow' => '[a-z0-9_.]+', 'id' => '[A-Za-z0-9_-]{43}']);
    $routes->add('yorvoid_flow_submit', '/flows/{flow}/{id}/{step}')->controller([FlowController::class, 'submit'])->methods(['POST'])->requirements(['flow' => '[a-z0-9_.]+', 'id' => '[A-Za-z0-9_-]{43}', 'step' => '[a-z0-9_]+']);
};
