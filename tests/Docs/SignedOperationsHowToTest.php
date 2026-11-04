<?php

declare(strict_types=1);

namespace YorvoID\Tests\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use YorvoID\IntentVerifier\Intent;
use YorvoID\Passkeys\Intent\IntentSigning;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * docs/how-to/signed-operations.md works as it's written: its privileged side's front controller runs
 * under PHP's built-in server, with only the page's own paths and the reader's classes (Acme\Sites,
 * Acme\EmailNotifier) filled in; its console's client asks it to prepare an operation; a passkey
 * registered with the bootstrap secret signs it on the signing pages; and the operation runs once.
 * The console's controller needs a whole Symfony application: it's checked for the API it uses.
 */
final class SignedOperationsHowToTest extends TestCase
{
    private const string HOW_TO = __DIR__.'/../../docs/how-to/signed-operations.md';

    private string $directory;

    private Process $server;

    private string $address;

    private HttpClientInterface $http;

    private VirtualAuthenticator $owner;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid-how-to-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $port = self::freePort();
        $this->address = '127.0.0.1:'.$port;
        [$frontController, $client] = self::blocks();

        // The page's paths, here: the verifier's files in the test's directory, its internal address on a free port.
        $place = fn (string $code): string => str_replace(
            ['/var/lib/acme-verifier', '127.0.0.1:8081', "require dirname(__DIR__).'/vendor/autoload.php';"],
            [$this->directory.'/state', $this->address, \sprintf("require %s;\nrequire %s;", var_export(\dirname(__DIR__, 2).'/vendor/autoload.php', true), var_export($this->directory.'/acme.php', true))],
            $code,
        );

        $filesystem = new Filesystem();
        $filesystem->mkdir([$this->directory.'/public', $this->directory.'/bin', $this->directory.'/state/outcomes', $this->directory.'/src'], 0o700);
        $filesystem->dumpFile($this->directory.'/public/index.php', $place($frontController));
        // "a command next to this front controller … that prints $verifier->createBootstrapSecret()"
        $filesystem->dumpFile($this->directory.'/bin/bootstrap-secret', $place(substr($frontController, 0, (int) strpos($frontController, '$method = '))."echo \$verifier->createBootstrapSecret();\n"));
        $filesystem->dumpFile($this->directory.'/src/PrivilegedSide.php', $place($client));
        $filesystem->dumpFile($this->directory.'/acme.php', \sprintf(<<<'PHP'
            <?php

            namespace Acme;

            use YorvoID\IntentVerifier\VerifierNotice;
            use YorvoID\IntentVerifier\VerifierNotifier;

            final class Sites
            {
                public function remove(string $site): void
                {
                    file_put_contents(%s, $site."\n", FILE_APPEND);
                }
            }

            final class EmailNotifier implements VerifierNotifier
            {
                public function notify(VerifierNotice $notice): void
                {
                }
            }
            PHP, var_export($this->directory.'/removed.txt', true)));

        $this->server = new Process([\PHP_BINARY, '-S', $this->address, $this->directory.'/public/index.php']);
        $this->server->start();
        $this->http = HttpClient::create(['max_redirects' => 0]);
        $this->owner = new VirtualAuthenticator('https://sign.example.com');

        for ($tries = 0; false === @fsockopen('127.0.0.1', $port); ++$tries) {
            self::assertLessThan(100, $tries, 'The privileged side didn\'t start: '.$this->server->getErrorOutput());
            usleep(50_000);
        }
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        new Filesystem()->remove($this->directory);
    }

    public function testTheConsoleHasAnOperationPreparedSignedAndRunOnTheOtherSide(): void
    {
        $this->bootstrapAKey();

        require_once $this->directory.'/src/PrivilegedSide.php';
        self::assertSame(1, preg_match('/^namespace ([^;]+);.*^final readonly class (\w+)/ms', self::blocks()[1], $declared));
        $class = $declared[1].'\\'.$declared[2];
        self::assertTrue(class_exists($class), $class);
        $privileged = new $class(HttpClient::create());

        $prepared = self::call($privileged, 'prepare', 'acme.site.remove', ['site' => 'blog']);
        self::assertIsArray($prepared);
        [$intent, $url] = $prepared;
        self::assertInstanceOf(Intent::class, $intent);
        self::assertIsString($url);
        self::assertSame(['acme.site.remove', ['site' => 'blog'], 'acme-verifier:host-1'], [$intent->action, $intent->parameters, $intent->audience]);
        self::assertStringStartsWith('https://sign.example.com/intents/', $url);
        self::assertSame(['outcome' => null], self::call($privileged, 'outcome', $intent->nonce), 'Nothing ran before the signature.');

        $path = (string) parse_url($url, \PHP_URL_PATH);
        $page = $this->signing('GET', $path);
        self::assertStringContainsString('blog', $page->getContent(), 'The signing page shows what is signed.');
        $signed = $this->signing('POST', $path, ['response' => $this->owner->signIn(self::options($page, 'get'))]);

        self::assertSame(303, $signed->getStatusCode(), $signed->getContent(false));
        self::assertStringStartsWith('https://console.example.com/signed?', $signed->getHeaders(false)['location'][0]);
        self::assertStringContainsString('nonce='.$intent->nonce, $signed->getHeaders(false)['location'][0]);
        self::assertSame("blog\n", file_get_contents($this->directory.'/removed.txt'), 'Exactly what was signed ran, once.');

        $outcome = self::call($privileged, 'outcome', $intent->nonce);
        self::assertIsArray($outcome);
        self::assertSame('ran', $outcome['outcome'] ?? null);
        self::assertNotSame('', $outcome['key'] ?? '');
    }

    public function testThePrivilegedSidePreparesOnlyWhatItsPolicyListsAndOnlyForTheConsole(): void
    {
        $refused = $this->internal('POST', '/prepare', ['action' => 'acme.site.wipe', 'parameters' => []]);
        self::assertSame(422, $refused->getStatusCode(), $refused->getContent(false));
        self::assertSame(['refusal' => 'not_listed'], $refused->toArray(false));

        $wrongParameters = $this->internal('POST', '/prepare', ['action' => 'acme.site.remove', 'parameters' => ['site' => 'blog', 'also' => 'shop']]);
        self::assertSame(422, $wrongParameters->getStatusCode(), $wrongParameters->getContent(false));

        self::assertSame(404, $this->signing('POST', '/prepare')->getStatusCode(), 'The internal endpoint answered on the signing origin.');
        self::assertSame(404, $this->internal('GET', '/keys')->getStatusCode(), 'The signing pages answered on the internal address.');
    }

    public function testTheConsolesControllerUsesYorvoIDsApiAsItIs(): void
    {
        $controller = self::blocks()[2];
        preg_match_all('/^use (YorvoID\\\\[A-Za-z\\\\]+);$/m', $controller, $imports);
        preg_match_all('/\$this->signing->(\w+)\(/', $controller, $calls);
        self::assertNotEmpty($imports[1]);
        self::assertNotEmpty($calls[1]);

        foreach ($imports[1] as $name) {
            self::assertTrue(class_exists($name) || interface_exists($name), $name.' doesn\'t exist.');
        }

        foreach ($calls[1] as $method) {
            self::assertTrue(method_exists(IntentSigning::class, $method), 'IntentSigning has no '.$method.'().');
        }
    }

    /**
     * The owner's first signing passkey, added with the bootstrap secret the host's command prints.
     */
    private function bootstrapAKey(): void
    {
        $command = new Process([\PHP_BINARY, $this->directory.'/bin/bootstrap-secret']);
        $command->mustRun();
        $secret = trim($command->getOutput());
        self::assertNotSame('', $secret);

        $created = $this->signing('POST', '/keys/bootstrap', ['label' => 'Laptop']);
        $form = self::form($created, 'create');
        $registered = $this->signing('POST', (string) $form->attr('action'), ['response' => $this->owner->register((string) $form->attr('data-yorvoid-options'))]);
        $location = (string) parse_url($registered->getHeaders(false)['location'][0], \PHP_URL_PATH);
        $done = $this->signing('POST', $location, ['response' => $this->owner->signIn(self::options($this->signing('GET', $location), 'get')), 'secret' => $secret]);

        self::assertSame(303, $done->getStatusCode(), $done->getContent(false));
        self::assertStringContainsString('Laptop', $this->signing('GET', '/keys')->getContent());
    }

    /**
     * @param array<string, string> $form
     */
    private function signing(string $method, string $path, array $form = []): ResponseInterface
    {
        return $this->http->request($method, 'http://'.$this->address.$path, ['headers' => ['Host' => 'sign.example.com'], 'body' => $form]);
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function internal(string $method, string $path, ?array $json = null): ResponseInterface
    {
        return $this->http->request($method, 'http://'.$this->address.$path, ['headers' => ['Host' => $this->address]] + (null === $json ? [] : ['json' => $json]));
    }

    /**
     * A method of the how-to's own class, which only exists while the test runs.
     */
    private static function call(object $object, string $method, mixed ...$arguments): mixed
    {
        $callable = [$object, $method];
        self::assertTrue(\is_callable($callable), $method);

        return $callable(...$arguments);
    }

    private static function form(ResponseInterface $page, string $ceremony): Crawler
    {
        $form = new Crawler($page->getContent(false))->filter(\sprintf('form[method="post"][data-yorvoid-ceremony="%s"]', $ceremony));
        self::assertCount(1, $form, $page->getContent(false));

        return $form;
    }

    private static function options(ResponseInterface $page, string $ceremony): string
    {
        return (string) self::form($page, $ceremony)->attr('data-yorvoid-options');
    }

    /**
     * @return list<string> The page's PHP blocks: the front controller, the console's client, its controller
     */
    private static function blocks(): array
    {
        preg_match_all('/^```php\n(.*?)^```/ms', (string) file_get_contents(self::HOW_TO), $blocks);
        self::assertCount(3, $blocks[1], 'The how-to\'s blocks changed: update this test.');

        return $blocks[1];
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
