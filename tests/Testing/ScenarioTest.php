<?php

declare(strict_types=1);

namespace YorvoID\Tests\Testing;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSession;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\IdentifierStore;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Testing\Scenario;
use YorvoID\Testing\Symfony\BrowserSignIn;
use YorvoID\Tests\Symfony\App\TestKernel;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * The test helpers integrators use (PLT-084), in an application as one would set it up.
 */
final class ScenarioTest extends TestCase
{
    private string $directory;

    private TestKernel $kernel;

    private KernelBrowser $browser;

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/testing-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $this->kernel = new TestKernel($this->directory, ['realm' => 'acme']);
        $this->kernel->boot();
        $this->browser = new KernelBrowser($this->kernel, ['HTTPS' => 'on', 'HTTP_HOST' => 'example.com']);
        $this->browser->disableReboot();
        $container = $this->browser->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $this->container = $container;
        new CommandTester(new Application($this->kernel)->find('yorvoid:migrate'))->execute([]);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testMakesPrincipalsAndSessionsThroughYorvoIDsServices(): void
    {
        $scenario = Scenario::of($this->container);

        $ana = $scenario->principal('ana@example.com');
        self::assertSame('acme', $ana->realm->name);
        self::assertEquals($ana, $this->service(Principals::class)->findByIdentifier(new Realm('acme'), IdentifierKind::Email, 'ana@example.com'));
        self::assertTrue($this->service(IdentifierStore::class)->find(new Realm('acme'), IdentifierKind::Email, 'ana@example.com')?->isVerified());
        // Without an address, each gets a new one: no clash.
        self::assertNotEquals($scenario->principal()->id, $scenario->principal()->id);

        $started = $scenario->session($ana);
        $session = $this->service(Sessions::class)->validate(new Realm('acme'), $started->token);
        self::assertNotNull($session);
        self::assertEquals($ana->id, $session->principalId);
        self::assertSame([AuthenticationMethod::Passkey], $session->authentication->methods());
        self::assertTrue($session->authentication->factors[0]->multiFactor && $session->authentication->factors[0]->phishingResistant);

        $factor = $scenario->session($ana, AuthenticationMethod::RecoveryCode)->session->authentication->factors[0];
        self::assertSame([AuthenticationMethod::RecoveryCode, false, false], [$factor->method, $factor->multiFactor, $factor->phishingResistant]);
    }

    public function testRegistersPasskeysThatSignInLater(): void
    {
        $scenario = Scenario::of($this->container);
        $ana = $scenario->principal();

        $authenticator = $scenario->passkey($ana, label: 'YubiKey');
        $phone = new VirtualAuthenticator('https://example.com', synced: true);
        self::assertSame($phone, $scenario->passkey($ana, $phone), 'The test\'s own authenticator wasn\'t the one registered.');
        self::assertCount(2, $this->service(Passkeys::class)->of(new Realm('acme'), $ana->id));

        $passkeys = $this->service(Passkeys::class);
        $ceremony = $passkeys->beginSignIn(new Realm('acme'));
        $signedIn = $passkeys->finishSignIn(new Realm('acme'), $ceremony->id, $authenticator->signIn($ceremony->options), new Origin(OriginKind::Web));
        self::assertEquals($ana->id, $signedIn->session->principalId);
    }

    public function testGivesRecoveryCodesThatWork(): void
    {
        $scenario = Scenario::of($this->container);
        $ana = $scenario->principal();

        $codes = $scenario->recoveryCodes($ana);

        self::assertCount(10, $codes);
        self::assertNotNull($this->service(RecoveryCodes::class)->redeem(new Realm('acme'), $ana->id, $codes[3], new Origin(OriginKind::Web)));
    }

    public function testMovesYorvoIDsClock(): void
    {
        $scenario = Scenario::of($this->container);
        $started = $scenario->session($scenario->principal());

        $scenario->clock()->modify('+2 hours');

        self::assertNull($this->service(Sessions::class)->validate(new Realm('acme'), $started->token), 'A session outlived its idle timeout.');

        $native = new Scenario($this->service(Principals::class), $this->service(Sessions::class), $this->service(Passkeys::class), $this->service(RecoveryCodes::class), $this->service(PasskeyPolicy::class), new NativeClock());
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('YorvoID\'s clock is a Symfony\Component\Clock\NativeClock, which tests can\'t move.');
        $native->clock();
    }

    public function testSignsAPrincipalInToAFunctionalTest(): void
    {
        $ana = Scenario::of($this->container)->principal('ana@example.com');

        $this->browser->request('GET', '/me');
        self::assertSame(401, $this->browser->getResponse()->getStatusCode());

        $started = BrowserSignIn::as($this->browser, $ana);
        $this->browser->request('GET', '/me');

        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $me = json_decode((string) $this->browser->getResponse()->getContent(), true);
        \assert(\is_array($me));
        self::assertSame([$ana->id->uuid->toRfc4122(), ['passkey']], [$me['user'], $me['methods']]);

        // Through YorvoID: ending the session signs out.
        $this->service(Sessions::class)->end(new EndSession(new Realm('acme'), $started->session->id, SessionEndReason::SignedOut), new Origin(OriginKind::Web));
        $this->browser->request('GET', '/me');
        self::assertSame(401, $this->browser->getResponse()->getStatusCode());
    }

    public function testSigningInNeedsAPhpSession(): void
    {
        $kernel = new TestKernel($this->directory.'/without-session', session: false);
        $kernel->boot();

        try {
            $browser = new KernelBrowser($kernel);
            $this->expectException(IntegratorError::class);
            $this->expectExceptionMessage('The application has no PHP session to sign in with.');
            BrowserSignIn::as($browser, Scenario::of($this->container)->principal());
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = $this->container->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}
