<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\EndSessions;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\RegisterPasskey;
use YorvoID\Symfony\Flow\SessionFlowStore;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Symfony\App\TestKernel;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * A flow ends with the session it runs in (H-6, BRIDGE-07): signing out, here or elsewhere, ends it,
 * the next person in the browser doesn't get it, and nothing says it ever existed.
 */
final class FlowsEndWithTheSessionTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/flows-session-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testUnconfirmedRecoveryCodesDontOutliveSigningOut(): void
    {
        foreach (['invalidate_session: true' => false, 'invalidate_session: false' => true] as $how => $keepsData) {
            $browser = $this->browser($keepsData);
            [, $url] = $this->setUpAccount($browser, 'ana@example.com');
            self::assertCount(10, $browser->getCrawler()->filter('[data-yorvoid-recovery-codes] code'), $how.': the codes, unconfirmed.');

            $browser->request('GET', '/sign-out');
            self::assertFalse($browser->getRequest()->getSession()->has(SessionFlowStore::KEY), $how.': signing out takes the flows out of the PHP session.');
            $browser->request('GET', $url);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $how.': the next person in the browser.');
            self::assertCount(0, $browser->getCrawler()->filter('[data-yorvoid-recovery-codes] code'));
            $this->kernel?->shutdown();
            $this->directory .= '-next';
        }
    }

    public function testSigningOutElsewhereEndsTheFlowOnItsNextRequest(): void
    {
        $browser = $this->browser(true);
        [, $url] = $this->setUpAccount($browser, 'ana@example.com');
        $principals = $this->container()->get(Principals::class);
        \assert($principals instanceof Principals);
        $ana = $principals->findByIdentifier(Realm::default(), IdentifierKind::Email, 'ana@example.com');
        \assert(null !== $ana);

        $sessions = $this->container()->get(Sessions::class);
        \assert($sessions instanceof Sessions);
        Permits::call($sessions->endAll(...), new EndSessions(Realm::default(), $ana->id, SessionEndReason::SignedOut), new Origin(OriginKind::Console));

        $browser->request('GET', $url);
        self::assertSame(404, $browser->getResponse()->getStatusCode(), 'Her session ended: so did the flow.');
        self::assertSame([], (array) $browser->getRequest()->getSession()->get(SessionFlowStore::KEY), 'And it\'s gone from the store.');
    }

    public function testSomeoneElseSigningInDoesntGetTheFlow(): void
    {
        $browser = $this->browser();
        $this->setUpAccount($browser, 'ana@example.com');
        $bob = $this->withPasskey('bob@example.com');
        $browser->request('GET', '/yorvoid/flows/yorvoid.add_passkey', [], [], ['HTTP_ACCEPT' => 'application/json']);
        $id = $this->json($browser)['id'] ?? null;
        self::assertIsString($id, 'Ana adds a passkey.');

        // Bob signs in in the same browser, without Ana signing out.
        $options = $this->post($browser, '/yorvoid/sign-in/options', []);
        self::assertSame(['signed_in' => true], $this->post($browser, '/yorvoid/sign-in', ['ceremony' => $options['ceremony'] ?? null, 'response' => json_decode($bob->signIn((string) json_encode($options['options'] ?? null)), true)]), 'Bob, signed in.');
        $browser->request('GET', '/yorvoid/flows/yorvoid.add_passkey/'.$id, [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(404, $browser->getResponse()->getStatusCode());
        self::assertStringNotContainsString('ana@example.com', (string) $browser->getResponse()->getContent(), 'Nothing of Ana\'s (BRIDGE-07).');
        self::assertArrayNotHasKey($id, (array) $browser->getRequest()->getSession()->get(SessionFlowStore::KEY), 'Her flow is gone.');
    }

    public function testAnEndedFlowLooksLikeOneThatNeverWas(): void
    {
        $browser = $this->browser(true);
        [, $url] = $this->setUpAccount($browser, 'ana@example.com');
        $browser->request('GET', '/sign-out');
        $unknown = (string) preg_replace('~/[^/]+$~', '/'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), $url);

        foreach ([[], ['HTTP_ACCEPT' => 'application/json']] as $headers) {
            $browser->request('GET', $url, [], [], $headers);
            $ended = [$browser->getResponse()->getStatusCode(), $browser->getResponse()->getContent()];
            $browser->request('GET', $unknown, [], [], $headers);

            self::assertSame([$browser->getResponse()->getStatusCode(), $browser->getResponse()->getContent()], $ended, 'The same answer as for an ID that never was (C3).');
            self::assertSame(404, $ended[0]);
        }
    }

    /**
     * Sets up an account with a setup code, up to the recovery codes, which it leaves unconfirmed.
     *
     * @return array{VirtualAuthenticator, string} Its passkey, and the URL of the recovery codes' page
     */
    private function setUpAccount(KernelBrowser $browser, string $email): array
    {
        $codes = $this->container()->get(SetupCodes::class);
        \assert($codes instanceof SetupCodes);
        $code = Permits::call($codes->create(...), new CreateSetupCode(Realm::default(), Duration::days(1)), new Origin(OriginKind::Console));
        $authenticator = new VirtualAuthenticator();

        $browser->request('GET', '/yorvoid/flows/yorvoid.setup');
        $page = $browser->followRedirect();
        $browser->submit($page->filter('form')->form(), ['code' => $code->reveal(), 'email' => $email]);
        $registration = $browser->followRedirect();
        $form = $registration->filter('form[data-yorvoid-ceremony="create"]');
        $browser->submit($form->form(), ['response' => $authenticator->register((string) $form->attr('data-yorvoid-options')), 'label' => 'Laptop']);
        $browser->followRedirect();

        return [$authenticator, $browser->getRequest()->getPathInfo()];
    }

    /**
     * Someone else in the realm, with a passkey: the authenticator that holds it.
     */
    private function withPasskey(string $email): VirtualAuthenticator
    {
        $principals = $this->container()->get(Principals::class);
        $sessions = $this->container()->get(Sessions::class);
        $passkeys = $this->container()->get(Passkeys::class);
        \assert($principals instanceof Principals && $sessions instanceof Sessions && $passkeys instanceof Passkeys);
        $principal = Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: $email, emailVerified: true), new Origin(OriginKind::Console));
        $session = Permits::call($sessions->start(...), new StartSession(Realm::default(), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::SetupCode, new \DateTimeImmutable()))), new Origin(OriginKind::Web))->session;
        $authenticator = new VirtualAuthenticator();
        $ceremony = $passkeys->beginRegistration($session);
        Permits::call($passkeys->finishRegistration(...), new RegisterPasskey($session->realm, $ceremony->id, $authenticator->register($ceremony->options), 'Phone'), new Origin(OriginKind::Web), $session);

        return $authenticator;
    }

    private function browser(bool $keepsData = false): KernelBrowser
    {
        $this->kernel = new TestKernel($this->directory, keepsData: $keepsData);
        $this->kernel->boot();
        new CommandTester(new Application($this->kernel)->find('yorvoid:migrate'))->execute([]);

        return $this->otherBrowser();
    }

    private function otherBrowser(): KernelBrowser
    {
        $browser = new KernelBrowser($this->kernel ?? throw new \LogicException('Not booted.'), ['HTTPS' => 'on', 'HTTP_HOST' => 'example.com']);
        $browser->disableReboot();

        return $browser;
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel?->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    private function post(KernelBrowser $browser, string $path, array $body): array
    {
        $browser->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json'], [] === $body ? '{}' : (string) json_encode($body));

        return $this->json($browser);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(KernelBrowser $browser): array
    {
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
