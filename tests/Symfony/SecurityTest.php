<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session as PhpSession;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use YorvoID\Authn\Recovery\GenerateRecoveryCodes;
use YorvoID\Authn\Recovery\IssueRecoveryLink;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryLinks;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSessions;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\EndSessionOnSignOut;
use YorvoID\Symfony\Security\PrincipalUser;
use YorvoID\Symfony\Security\PrincipalUserChecker;
use YorvoID\Symfony\Security\PrincipalUserProvider;
use YorvoID\Symfony\Security\PrincipalUsers;
use YorvoID\Tests\Symfony\App\TestKernel;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * Signing in through the application's own firewall, with YorvoID's authenticators and endpoints
 * (PLT-010, PLT-011, SES-005, SES-008).
 */
final class SecurityTest extends TestCase
{
    private string $directory;

    private TestKernel $kernel;

    private KernelBrowser $browser;

    private ContainerInterface $container;

    /** @var array<string, VirtualAuthenticator> */
    private array $authenticators = [];

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/symfony-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testSignsInWithAPasskeyAndKeepsOnlyTheSessionsTokenInThePhpSession(): void
    {
        $this->boot();
        $ana = $this->principal();
        $authenticator = $this->passkey($ana);

        $options = $this->post('/yorvoid/sign-in/options', []);
        self::assertSame(200, $this->statusCode());
        $signIn = $this->post('/yorvoid/sign-in', ['ceremony' => $options['ceremony'], 'response' => json_decode($authenticator->signIn((string) json_encode($options['options'])), true)]);

        self::assertSame(['signed_in' => true], $signIn);
        $cookie = $this->browser->getResponse()->headers->getCookies()[0] ?? null;
        \assert($cookie instanceof Cookie);
        self::assertSame(['__Host-yorvoid_device', true, true, 'lax', '/', false], [$cookie->getName(), $cookie->isSecure(), $cookie->isHttpOnly(), $cookie->getSameSite(), $cookie->getPath(), $cookie->isPartitioned()]);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', (string) $cookie->getValue());

        $me = $this->get('/me');
        self::assertSame([(string) $ana->id, PrincipalUser::class, ['passkey']], [$me['user'], $me['class'], $me['methods']]);
        $held = $this->browser->getRequest()->getSession()->get('yorvoid_session');
        self::assertIsString($held);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $held, 'The PHP session holds something other than the token.');

        $this->signIn($ana, again: true);
        $again = $this->browser->getResponse()->headers->getCookies()[0] ?? null;
        \assert($again instanceof Cookie);
        self::assertSame($cookie->getValue(), $again->getValue(), 'A known device got a new cookie.');
    }

    public function testDoesntStartAPhpSessionForVisitors(): void
    {
        $this->boot();

        $this->post('/yorvoid/sign-in/options', []);

        self::assertSame(200, $this->statusCode());
        self::assertSame([], $this->browser->getResponse()->headers->getCookies());
    }

    public function testSigningOutEndsTheYorvoIDSession(): void
    {
        $this->boot();
        $ana = $this->principal();
        $this->signIn($ana);
        $this->get('/me');
        $held = $this->browser->getRequest()->getSession()->get('yorvoid_session');
        \assert(\is_string($held));

        $this->browser->request('GET', '/sign-out');

        self::assertSame(302, $this->statusCode());
        $sessions = $this->container->get(Sessions::class);
        \assert($sessions instanceof Sessions);
        self::assertNull($sessions->validate(Realm::default(), Secret::fromString($held)), 'The YorvoID session outlived signing out.');
        $this->browser->request('GET', '/me');
        self::assertSame(401, $this->statusCode());

        // Signed out already: nothing to end.
        $this->browser->request('GET', '/sign-out');
        self::assertSame(302, $this->statusCode());
    }

    public function testSigningOutForgetsTheTokenWhenThePhpSessionStays(): void
    {
        // An application whose logout keeps the PHP session (invalidate_session: false).
        $this->boot();
        $sessions = $this->container->get(Sessions::class);
        \assert($sessions instanceof Sessions);
        $started = $sessions->start(new StartSession(Realm::default(), $this->principal()->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable()))), new Origin(OriginKind::Web));
        $request = Request::create('/sign-out');
        $request->setSession(new PhpSession(new MockArraySessionStorage()));
        $request->getSession()->set(CurrentSession::KEY, $started->token->reveal());
        $request->getSession()->set('cart', ['book']);
        $request->cookies->set($request->getSession()->getName(), 'the browser\'s');
        $requests = $this->container->get('request_stack');
        \assert($requests instanceof RequestStack);
        $requests->push($request);
        $listener = $this->container->get(EndSessionOnSignOut::class);
        \assert($listener instanceof EndSessionOnSignOut);

        $listener(new LogoutEvent($request, null));

        self::assertFalse($request->getSession()->has(CurrentSession::KEY));
        self::assertNull($sessions->validate(Realm::default(), $started->token));
        self::assertSame(['book'], $request->getSession()->get('cart'));
    }

    public function testEndingTheYorvoIDSessionSignsOutAtTheNextRequest(): void
    {
        $this->boot();
        $ana = $this->principal();
        $this->signIn($ana);
        self::assertSame((string) $ana->id, $this->get('/me')['user']);

        $sessions = $this->container->get(Sessions::class);
        \assert($sessions instanceof Sessions);
        $sessions->endAll(new EndSessions(Realm::default(), $ana->id, SessionEndReason::EndedByAdministrator), new Origin(OriginKind::Console));

        $this->browser->request('GET', '/me');
        self::assertSame(401, $this->statusCode());
        self::assertNull($this->browser->getRequest()->getSession()->get('yorvoid_session'), 'The ended session\'s token stayed.');
    }

    public function testASuspendedPrincipalIsSignedOut(): void
    {
        $this->boot();
        $ana = $this->principal();
        $this->signIn($ana);
        $principals = $this->container->get(Principals::class);
        \assert($principals instanceof Principals);

        $principals->suspend(new SuspendPrincipal(Realm::default(), $ana->id), new Origin(OriginKind::Console));

        $this->browser->request('GET', '/me');
        self::assertSame(401, $this->statusCode());
    }

    public function testRefusesSignInsThatDontWorkOrComeTooOften(): void
    {
        $this->boot(['limits' => ['per_address' => '2/1h']]);
        $options = $this->post('/yorvoid/sign-in/options', []);

        self::assertSame(['error' => 'That passkey didn\'t work. Try another, or another way to sign in.'], $this->post('/yorvoid/sign-in', ['ceremony' => $options['ceremony'], 'response' => ['id' => 'x']]));
        self::assertSame(401, $this->statusCode());

        $unknown = new VirtualAuthenticator();
        $unknown->credentials[] = ['id' => random_bytes(16), 'key' => sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()), 'publicKey' => '', 'userHandle' => random_bytes(32), 'counter' => 0];
        $options = $this->post('/yorvoid/sign-in/options', []);
        self::assertSame(['error' => 'That passkey didn\'t work. Try another, or another way to sign in.', 'unknown_credential' => true], $this->post('/yorvoid/sign-in', ['ceremony' => $options['ceremony'], 'response' => json_decode($unknown->signIn((string) json_encode($options['options'])), true)]));

        $this->browser->request('POST', '/yorvoid/sign-in', [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'ceremony=x');
        self::assertSame(415, $this->statusCode());
        self::assertSame(['error' => 'Send the request as JSON.'], json_decode((string) $this->browser->getResponse()->getContent(), true));

        $this->browser->request('POST', '/yorvoid/sign-in/options', [], [], ['CONTENT_TYPE' => 'text/plain'], '{}');
        self::assertSame(415, $this->statusCode());
        self::assertSame(['error' => 'Send the request as JSON.'], $this->json());
        $this->browser->request('POST', '/yorvoid/sign-in', [], [], ['CONTENT_TYPE' => 'application/json'], '["not", "an", "object"]');
        self::assertSame(['error' => 'Send the request as JSON.'], $this->json());
        self::assertSame(415, $this->statusCode());

        $this->post('/yorvoid/sign-in', ['ceremony' => 'x', 'response' => []]);
        $this->post('/yorvoid/sign-in', ['ceremony' => 'x', 'response' => []]);
        self::assertSame(429, $this->statusCode());
        self::assertSame('1800', $this->browser->getResponse()->headers->get('Retry-After'));
    }

    public function testAttemptsOnAccountsThatDontExistCount(): void
    {
        $this->boot(['limits' => ['per_address' => '1/1h']]);

        $this->post('/yorvoid/recover/code', ['email' => 'nobody@example.com', 'code' => 'x']);
        self::assertSame(401, $this->statusCode());
        $this->post('/yorvoid/recover/code', ['email' => 'nobody@example.com', 'code' => 'x']);
        self::assertSame(429, $this->statusCode());
    }

    public function testRecoversWithACodeOrALink(): void
    {
        $this->boot();
        $ana = $this->principal();
        $session = $this->session($ana);
        $codes = $this->container->get(RecoveryCodes::class);
        $links = $this->container->get(RecoveryLinks::class);
        \assert($codes instanceof RecoveryCodes && $links instanceof RecoveryLinks);
        $code = $codes->generate(new GenerateRecoveryCodes(Realm::default()), $session, new Origin(OriginKind::Web))[0];

        self::assertSame(['error' => 'That code didn\'t work. Check it, or use another one.'], $this->post('/yorvoid/recover/code', ['email' => 'ana@example.com', 'code' => 'wrong']));
        self::assertSame(['error' => 'That code didn\'t work. Check it, or use another one.'], $this->post('/yorvoid/recover/code', ['email' => 'nobody@example.com', 'code' => $code->reveal()]), 'The answer told whether an account exists.');
        self::assertSame(['error' => 'That code didn\'t work. Check it, or use another one.'], $this->post('/yorvoid/recover/code', ['email' => 5]));
        self::assertSame(['signed_in' => true], $this->post('/yorvoid/recover/code', ['email' => 'ANA@example.com', 'code' => $code->reveal()]));
        self::assertSame(['recovery_code'], $this->get('/me')['methods']);

        $link = $links->issue(new IssueRecoveryLink(Realm::default(), $ana->id), new Origin(OriginKind::Console));
        self::assertSame(['signed_in' => true], $this->post('/yorvoid/recover/link', ['token' => $link->secret->reveal()]));
        self::assertSame(['recovery_link'], $this->get('/me')['methods']);
        self::assertSame(['error' => 'This link doesn\'t work any more. Ask for a new one.'], $this->post('/yorvoid/recover/link', ['token' => $link->secret->reveal()]));
        self::assertSame(['error' => 'This link doesn\'t work any more. Ask for a new one.'], $this->post('/yorvoid/recover/link', []));

        $principals = $this->container->get(Principals::class);
        \assert($principals instanceof Principals);
        $codeAgain = $codes->generate(new GenerateRecoveryCodes(Realm::default()), $this->session($ana), new Origin(OriginKind::Web))[0];
        $principals->suspend(new SuspendPrincipal(Realm::default(), $ana->id), new Origin(OriginKind::Console));
        self::assertSame(['error' => 'This account can\'t sign in.'], $this->post('/yorvoid/recover/code', ['email' => 'ana@example.com', 'code' => $codeAgain->reveal()]));
        self::assertSame(401, $this->statusCode());
    }

    public function testAddsAPasskeyAndStepsUpFromTheSignedInSession(): void
    {
        $this->boot();
        $ana = $this->principal();
        $authenticator = $this->signIn($ana);

        $options = $this->post('/yorvoid/passkeys/options', []);
        self::assertSame(200, $this->statusCode());
        $added = $this->post('/yorvoid/passkeys', ['ceremony' => $options['ceremony'], 'response' => json_decode($authenticator->register((string) json_encode($options['options'])), true), 'label' => 'Laptop']);
        self::assertSame(201, $this->statusCode());
        self::assertSame('Laptop', $added['label']);
        self::assertIsString($added['credential']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/D', $added['credential']);

        $held = $this->browser->getRequest()->getSession()->get('yorvoid_session');
        $options = $this->post('/yorvoid/step-up/options', ['operation' => 'acme.site.remove', 'multi_factor' => true]);
        self::assertSame('required', ((array) $options['options'])['userVerification']);
        self::assertSame(['stepped_up' => true], $this->post('/yorvoid/step-up', ['ceremony' => $options['ceremony'], 'response' => json_decode($authenticator->signIn((string) json_encode($options['options']), 1), true)]));
        self::assertNotSame($held, $this->browser->getRequest()->getSession()->get('yorvoid_session'), 'Stepping up kept the old token (SES-002).');

        $signals = $this->post('/yorvoid/passkeys/signals', []);
        self::assertSame(['rpId', 'userId', 'allAcceptedCredentialIds', 'name', 'displayName'], array_keys($signals));
        self::assertCount(2, (array) $signals['allAcceptedCredentialIds']);
        self::assertSame('ana@example.com', $signals['name']);
        self::assertSame(['passkey'], $this->get('/me')['methods'], 'Stepping up signed the person out.');

        self::assertSame(['error' => 'That isn\'t an operation\'s name.'], $this->post('/yorvoid/step-up/options', ['operation' => 'Remove!']));
        self::assertSame(400, $this->statusCode());
        $refused = $this->post('/yorvoid/step-up', ['ceremony' => 'x', 'response' => []]);
        self::assertSame(400, $this->statusCode());
        self::assertSame(['error', 'reason'], array_keys($refused));
        self::assertSame('That took too long. Please try again.', $refused['error']);
        self::assertIsString($refused['reason']);
    }

    public function testChangesNeedARecentProofAndASession(): void
    {
        $this->boot();
        $ana = $this->principal();
        $this->signIn($ana);

        self::assertSame(['error' => 'Sign in first.'], $this->post('/yorvoid/passkeys/options', [], fresh: true));
        self::assertSame(401, $this->statusCode());

        $this->signIn($ana, again: true);
        $response = $this->postAfter(360, '/yorvoid/passkeys/options', []);
        self::assertSame(403, $this->statusCode());
        self::assertSame(['error', 'step_up'], array_keys($response));
        self::assertIsString($response['error']);
        self::assertSame(['operation' => 'yorvoid.credential.add', 'within' => '5m'], $response['step_up']);
    }

    public function testStepsUpToMultiFactorOnlyWhenAsked(): void
    {
        $this->boot(['passkeys' => ['user_verification' => 'preferred']]);
        $this->signIn($this->principal());

        self::assertSame('preferred', ((array) $this->post('/yorvoid/step-up/options', [])['options'])['userVerification']);
        self::assertSame('preferred', ((array) $this->post('/yorvoid/step-up/options', ['multi_factor' => 'yes'])['options'])['userVerification']);
        self::assertSame('required', ((array) $this->post('/yorvoid/step-up/options', ['multi_factor' => true])['options'])['userVerification']);
    }

    public function testThereAreNoSignalsWithoutAPasskey(): void
    {
        $this->boot();
        $ana = $this->principal();
        $codes = $this->container->get(RecoveryCodes::class);
        \assert($codes instanceof RecoveryCodes);
        $code = $codes->generate(new GenerateRecoveryCodes(Realm::default()), $this->session($ana), new Origin(OriginKind::Web))[0];
        $this->post('/yorvoid/recover/code', ['email' => 'ana@example.com', 'code' => $code->reveal()]);

        $this->browser->request('POST', '/yorvoid/passkeys/signals', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertSame(204, $this->statusCode());
    }

    public function testWithoutTheAuthenticatorsTheRoutesSaySo(): void
    {
        $this->boot(authenticators: false);

        self::assertSame(['error' => 'Signing in this way isn\'t set up here.'], $this->post('/yorvoid/sign-in', []));
        self::assertSame(404, $this->statusCode());
    }

    public function testSignsInTheApplicationsOwnUsers(): void
    {
        $this->boot(['users' => 'test.users']);
        $ana = $this->principal();
        $this->signIn($ana);

        $me = $this->get('/me');
        self::assertSame([(string) $ana->id, InMemoryUser::class, ['ROLE_OWN']], [$me['user'], $me['class'], $me['roles']], 'The application\'s own user wasn\'t kept after signing in.');
        self::assertSame((string) $ana->id, $this->get('/me')['user']);
    }

    public function testTheProviderAndCheckerKnowPrincipals(): void
    {
        $this->boot();
        $ana = $this->principal();
        $provider = $this->container->get(PrincipalUserProvider::class);
        \assert($provider instanceof PrincipalUserProvider);

        $user = $provider->loadUserByIdentifier((string) $ana->id);
        self::assertEquals(new PrincipalUser($ana), $user);
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertEquals($user, $provider->refreshUser($user));
        self::assertTrue($provider->supportsClass(PrincipalUser::class));
        self::assertTrue($provider->supportsClass(InMemoryUser::class));
        self::assertEquals($user, $provider->refreshUser(new InMemoryUser((string) $ana->id, null)));

        foreach (['not-an-id', (string) new PrincipalId(new \Symfony\Component\Uid\UuidV7())] as $unknown) {
            try {
                $provider->loadUserByIdentifier($unknown);
                self::fail(\sprintf('"%s" was loaded.', $unknown));
            } catch (UserNotFoundException) {
            }
        }

        $principals = $this->container->get(Principals::class);
        \assert($principals instanceof Principals);
        $wrong = new PrincipalUserProvider($principals, Realm::default(), new class implements PrincipalUsers {
            public function userFor(Principal $principal): UserInterface
            {
                return new InMemoryUser('ana@example.com', null);
            }
        });

        try {
            $wrong->loadUserByIdentifier((string) $ana->id);
            self::fail('A user identified otherwise was loaded.');
        } catch (IntegratorError $error) {
            self::assertSame(\sprintf('The user %s gave for principal %s is identified as "ana@example.com".', PrincipalUsers::class.'@anonymous', $ana->id), $error->problem);
        }

        $principals->suspend(new SuspendPrincipal(Realm::default(), $ana->id), new Origin(OriginKind::Console));

        try {
            $provider->refreshUser($user);
            self::fail('A suspended principal was loaded.');
        } catch (UserNotFoundException) {
        }

        $checker = new PrincipalUserChecker();
        $checker->checkPreAuth($user);
        $checker->checkPreAuth(new InMemoryUser('x', null));
        $checker->checkPostAuth($user);
        $this->expectException(CustomUserMessageAccountStatusException::class);

        $checker->checkPreAuth(new PrincipalUser($ana->withStatus(PrincipalStatus::Suspended, new \DateTimeImmutable())));
    }

    /**
     * @param array<string, mixed> $yorvoid
     */
    private function boot(array $yorvoid = [], bool $authenticators = true): void
    {
        $this->kernel = new TestKernel($this->directory, $yorvoid, authenticators: $authenticators);
        $this->kernel->boot();
        $this->browser = new KernelBrowser($this->kernel, ['HTTPS' => 'on', 'HTTP_HOST' => 'example.com']);
        $this->browser->disableReboot();
        $container = $this->browser->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $this->container = $container;
        new CommandTester(new Application($this->kernel)->find('yorvoid:migrate'))->execute([]);
    }

    private function principal(): Principal
    {
        $principals = $this->container->get(Principals::class);
        \assert($principals instanceof Principals);

        return $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));
    }

    private function session(Principal $principal): Session
    {
        $sessions = $this->container->get(Sessions::class);
        \assert($sessions instanceof Sessions);

        return $sessions->start(new StartSession(Realm::default(), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::SetupCode, new \DateTimeImmutable()))), new Origin(OriginKind::Web))->session;
    }

    private function passkey(Principal $principal): VirtualAuthenticator
    {
        $passkeys = $this->container->get(Passkeys::class);
        \assert($passkeys instanceof Passkeys);
        $session = $this->session($principal);
        $authenticator = new VirtualAuthenticator();
        $ceremony = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Phone', new Origin(OriginKind::Web));

        return $this->authenticators[(string) $principal->id] = $authenticator;
    }

    private function signIn(Principal $principal, bool $again = false): VirtualAuthenticator
    {
        $authenticator = $again ? $this->authenticators[(string) $principal->id] : $this->passkey($principal);
        $options = $this->post('/yorvoid/sign-in/options', []);
        self::assertSame(['signed_in' => true], $this->post('/yorvoid/sign-in', ['ceremony' => $options['ceremony'], 'response' => json_decode($authenticator->signIn((string) json_encode($options['options'])), true)]));

        return $authenticator;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body, bool $fresh = false): array
    {
        if ($fresh) {
            $this->browser->getCookieJar()->clear();
        }

        $this->browser->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json'], [] === $body ? '{}' : (string) json_encode($body));

        return $this->json();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function postAfter(int $seconds, string $path, array $body): array
    {
        $clock = $this->container->get(\Psr\Clock\ClockInterface::class);
        \assert($clock instanceof \Symfony\Component\Clock\MockClock);
        $clock->sleep($seconds);

        return $this->post($path, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $this->browser->request('GET', $path);
        self::assertSame(200, $this->statusCode(), (string) $this->browser->getResponse()->getContent());

        return $this->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true);
        \assert(\is_array($decoded));
        $fields = [];

        foreach ($decoded as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }

    private function statusCode(): int
    {
        return $this->browser->getResponse()->getStatusCode();
    }
}
