<?php

declare(strict_types=1);

namespace YorvoID\Testing\Symfony;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\Principal;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Testing\Scenario;

/**
 * Acts as a principal in a Symfony functional test (PLT-084): a real YorvoID session, held in the
 * browser's PHP session as signing in would, so the next request is signed in through YorvoID's own
 * authenticator. Symfony's loginUser() would bypass YorvoID, and its session checks with it.
 *
 * @api
 */
final class BrowserSignIn
{
    public static function as(KernelBrowser $browser, Principal $principal, AuthenticationMethod $method = AuthenticationMethod::Passkey): StartedSession
    {
        $session = $browser->getSession() ?? throw new IntegratorError('The application has no PHP session to sign in with.', 'Turn it on with framework.session.', 'concepts/testing.md#signing-in-in-functional-tests');
        $container = $browser->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $started = Scenario::of($container)->session($principal, $method);
        $session->set(CurrentSession::KEY, $started->token->reveal());
        $session->save();

        return $started;
    }
}
