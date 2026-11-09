<?php

declare(strict_types=1);

namespace YorvoID\Bench;

use PhpBench\Attributes as Bench;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\RegisterPasskey;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Symfony\App\TestKernel;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * Signing in with a passkey through the Symfony bridge's endpoints (OPS-042: the sign-in endpoints),
 * at the kernel: both requests, the options and the answer, handled by the application's own firewall,
 * authenticator and controllers on PostgreSQL, without a network. The authenticator's signing, a
 * fraction of a millisecond in software, is inside the time. The attempt limits are raised, as one
 * address signs in more often than a person would: they're still counted, as on every sign-in.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Warmup(5)]
#[Bench\Iterations(200)]
#[Bench\Revs(1)]
final class SignInBench
{
    /** @var array{KernelBrowser, VirtualAuthenticator}|null */
    private static ?array $shared = null;

    private KernelBrowser $browser;

    private VirtualAuthenticator $authenticator;

    public function setUp(): void
    {
        [$this->browser, $this->authenticator] = self::$shared ??= self::application();
    }

    public function benchPasskeySignIn(): void
    {
        $this->browser->getCookieJar()->clear();
        $options = self::post($this->browser, '/yorvoid/sign-in/options', []);

        if (!isset($options['ceremony'], $options['options'])) {
            throw new \LogicException(\sprintf('No options (%d): %s', $this->browser->getResponse()->getStatusCode(), substr((string) $this->browser->getResponse()->getContent(), 0, 600)));
        }
        $signedIn = self::post($this->browser, '/yorvoid/sign-in', ['ceremony' => $options['ceremony'], 'response' => json_decode($this->authenticator->signIn((string) json_encode($options['options'])), true)]);

        if (['signed_in' => true] !== $signedIn) {
            throw new \LogicException('Not signed in: '.json_encode($signedIn));
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function post(KernelBrowser $browser, string $path, array $body): array
    {
        $browser->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json'], [] === $body ? '{}' : (string) json_encode($body));
        $answer = json_decode((string) $browser->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($answer)) {
            throw new \LogicException('Not JSON.');
        }

        $named = [];

        foreach ($answer as $key => $value) {
            $named[(string) $key] = $value;
        }

        return $named;
    }

    /**
     * @return array{KernelBrowser, VirtualAuthenticator}
     */
    private static function application(): array
    {
        $url = getenv('YORVOID_TEST_POSTGRES_URL');

        if (false === $url || '' === $url) {
            throw new \RuntimeException('The benchmarks run on PostgreSQL: set YORVOID_TEST_POSTGRES_URL.');
        }

        Postgres::stack('sign_in');
        $kernel = new TestKernel(\sprintf('%s/yorvoid-bench-sign-in-%s', sys_get_temp_dir(), bin2hex(random_bytes(6))), ['storage' => ['schema' => 'yorvoid_bench_sign_in'], 'limits' => ['per_account_and_address' => '100000/1m', 'per_account' => '100000/1m', 'per_address' => '100000/1m']], debugging: false, database: \sprintf('%s?serverVersion=%s', preg_replace('#^pdo-pgsql://#', 'postgresql://', $url), false === getenv('POSTGRES_VERSION') ? '18' : getenv('POSTGRES_VERSION')));
        $kernel->boot();
        $browser = new KernelBrowser($kernel, ['HTTPS' => 'on', 'HTTP_HOST' => 'example.com']);
        $browser->disableReboot();
        $browser->catchExceptions(false);
        $container = $browser->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        new CommandTester(new Application($kernel)->find('yorvoid:migrate'))->execute([]);
        $principals = $container->get(Principals::class);
        $sessions = $container->get(Sessions::class);
        $passkeys = $container->get(Passkeys::class);
        \assert($principals instanceof Principals && $sessions instanceof Sessions && $passkeys instanceof Passkeys);
        $principal = Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));
        $session = Permits::call($sessions->start(...), new StartSession(Realm::default(), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::SetupCode, new \DateTimeImmutable()))), new Origin(OriginKind::Web))->session;
        $authenticator = new VirtualAuthenticator();
        $ceremony = $passkeys->beginRegistration($session);
        Permits::call($passkeys->finishRegistration(...), new RegisterPasskey($session->realm, $ceremony->id, $authenticator->register($ceremony->options), 'Phone'), new Origin(OriginKind::Web), $session);

        return [$browser, $authenticator];
    }
}
