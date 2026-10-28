<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Symfony\Security\Authenticator\PasskeyAuthenticator;
use YorvoID\Symfony\Security\Authenticator\SignInRefused;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\Json;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * The parts of the security integration a browser can't reach.
 */
final class SecurityUnitTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid-security-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testWithoutARequestThereIsNoSession(): void
    {
        $current = new CurrentSession(new RequestStack(), $this->sessions(), Realm::default());

        self::assertNull($current->get());
        self::assertFalse($current->isHeld());
        $current->forget();
        $this->expectExceptionObject(new \LogicException('There is no request to hold a session in.'));

        $current->hold(Secret::fromString('token'));
    }

    public function testHoldingASessionReplacesThePhpSession(): void
    {
        $storage = new MockFileSessionStorage($this->directory);
        $session = new Session($storage);
        $session->set('cart', 3);
        $session->save();
        $before = $session->getId();
        self::assertFileExists(\sprintf('%s/%s.mocksess', $this->directory, $before));
        $request = new Request();
        $request->setSession($session);
        $requests = new RequestStack();
        $requests->push($request);

        new CurrentSession($requests, $this->sessions(), Realm::default())->hold(Secret::fromString('token'));

        self::assertNotSame($before, $session->getId());
        self::assertFileDoesNotExist(\sprintf('%s/%s.mocksess', $this->directory, $before), 'The old PHP session can still be used (SES-002).');
        self::assertSame(['cart' => 3, 'yorvoid_session' => 'token'], $session->all());
    }

    public function testForgetsTheSessionsToken(): void
    {
        $session = new Session(new MockFileSessionStorage($this->directory));
        $session->set('yorvoid_session', 'token');
        $session->set('cart', 3);
        $withSession = new Request();
        $withSession->setSession($session);
        $requests = new RequestStack();
        $requests->push($withSession);

        new CurrentSession($requests, $this->sessions(), Realm::default())->forget();
        self::assertSame(['cart' => 3], $session->all());

        $requests->pop();
        $requests->push(new Request());
        new CurrentSession($requests, $this->sessions(), Realm::default())->forget();
        self::assertFalse($requests->getMainRequest()?->hasSession());
    }

    public function testDescribesTheDeviceByItsUserAgent(): void
    {
        $request = Request::create('/');
        $request->headers->remove('User-Agent');
        self::assertNull(Json::device($request));

        $request->headers->set('User-Agent', '');
        self::assertNull(Json::device($request));

        $request->headers->set('User-Agent', str_repeat('é', 300));
        self::assertSame(str_repeat('é', 255), Json::device($request));
    }

    public function testThePagesTranslateWithoutARequestToo(): void
    {
        $translator = new \YorvoID\Translation\Translator(\YorvoID\Symfony\YorvoIDBundle::TRANSLATIONS, 'de');
        $requests = new RequestStack();
        $filter = new \YorvoID\Symfony\Twig\YorvoIDExtension($translator, $requests)->getFilters()[0];
        $callable = $filter->getCallable();
        \assert(\is_callable($callable));

        self::assertSame('yorvoid_trans', $filter->getName());
        self::assertSame('Anmelden', $callable('yorvoid.ui.passkey.title'), 'The default language.');

        $request = new Request();
        $request->setLocale('en');
        $requests->push($request);
        self::assertSame('You were invited as ana@example.com. Accept, then add a passkey to sign in with.', $callable('yorvoid.ui.invitation.intro', ['email' => 'ana@example.com']));
    }

    public function testOtherFailuresAnswer401(): void
    {
        $authenticator = $this->service(PasskeyAuthenticator::class);

        $response = $authenticator->onAuthenticationFailure(new Request(), new AuthenticationException());

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertSame(401, new SignInRefused('No.')->status);
        self::assertSame(0, new SignInRefused('No.')->getCode());
    }

    private function sessions(): Sessions
    {
        return $this->service(Sessions::class);
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
        if (null === $this->kernel) {
            $this->kernel = new TestKernel($this->directory.'/app');
            $this->kernel->boot();
        }

        $service = $this->kernel->getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}
