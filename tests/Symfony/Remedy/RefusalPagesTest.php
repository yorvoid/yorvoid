<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\Remedy;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Remedy;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Symfony\Migrations;
use YorvoID\Symfony\Remedy\NotPermittedListener;
use YorvoID\Symfony\Twig\YorvoIDExtension;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * An operation refused and not caught, answered for the person (M2-10): a page, or JSON, of what would
 * satisfy it; 403 for a refusal, 503 for what couldn't be decided; after the application's own
 * listeners, before Symfony's error page; and the reference shown is the decision in the log.
 */
final class RefusalPagesTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/refusals-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testAStaleSessionIsAskedToConfirmAndComesBack(): void
    {
        $container = $this->boot();
        $refused = $this->refuse($container, false);
        self::assertInstanceOf(StepUpRemedy::class, $refused->decision->remedies[0] ?? null);

        $response = $this->answer($container, $refused, Request::create('/account/email?tab=1'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertStringContainsString("form-action 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $html = (string) $response->getContent();
        self::assertStringContainsString('<title>Before you can do this</title>', $html);
        self::assertStringContainsString('Confirm it&#039;s you, then try again. This confirms: changing your email address or username.', $html);
        self::assertStringContainsString('<a href="/yorvoid/flows/yorvoid.step_up?operation=yorvoid.identifier.set&amp;within=300&amp;return=/account/email?tab%3D1">Confirm it&#039;s you</a>', $html);
        self::assertStringNotContainsString('recently enough', $html, 'Not the decision\'s reason: that\'s for the log.');

        // The reference is the decision, as the log has it: support can look it up.
        $id = $refused->decision->id;
        self::assertNotNull($id);
        self::assertStringContainsString('Reference: '.$id, $html);
        $log = $container->get(DecisionLogStore::class);
        \assert($log instanceof DecisionLogStore);
        $record = $log->find(Realm::default(), $id);
        self::assertSame([$id, 'deny', 'yorvoid.identifier.set'], [$record?->id, $record?->verdict, $record?->name]);
    }

    public function testAfterARecoveryThereIsOnlyTheWait(): void
    {
        $container = $this->boot(['step_up' => ['cool_down' => '24h']]);
        $refused = $this->refuse($container, true);
        self::assertInstanceOf(WaitRemedy::class, $refused->decision->remedies[0] ?? null);

        $html = (string) $this->answer($container, $refused, Request::create('/account/email'))->getContent();

        self::assertMatchesRegularExpression('#<time datetime="[0-9T:+-]+">Your account was recovered recently, so changes like this wait a while, to keep it safe\. You can do this again in about 1 day\.</time>#', $html);
        self::assertStringNotContainsString('yorvoid.step_up', $html);
        self::assertStringContainsString('<p>This wasn&#039;t done.</p>', $html);
        self::assertSame('recovered', $refused->decision->remedies[0]->parameters()['reason'], 'The wait says why (U-1).');
    }

    public function testGoBackLeadsToThePageTheRequestCameFromNeverTheRefusedOne(): void
    {
        $container = $this->boot(['flows' => ['completed_path' => '/home']]);
        $decision = Decision::deny('No role gives it.');
        $back = function (string $method, ?string $referer) use ($container, $decision): string {
            $request = Request::create('http://acme.example/projects/7?tab=1', $method, server: array_filter(['HTTP_REFERER' => $referer], static fn (?string $value): bool => null !== $value));
            $html = (string) $this->answer($container, new NotPermitted('acme.project.remove', $decision), $request)->getContent();
            self::assertSame(1, preg_match('#<a href="([^"]*)" data-yorvoid-back>Go back</a>#', $html, $link), $html);

            return html_entity_decode($link[1]);
        };

        self::assertSame('/projects?page=2', $back('GET', 'http://acme.example/projects?page=2'));
        self::assertSame('/projects?page=2', $back('POST', 'http://acme.example/projects?page=2'));
        self::assertSame('/home', $back('GET', 'http://acme.example/projects/7?tab=1'), 'Not the refused page itself: it would refuse again (D1).');
        self::assertSame('/home', $back('GET', null), 'No Referer: flows.completed_path.');
        self::assertSame('/home', $back('GET', 'http://evil.example/projects'), 'Another site: never.');
        self::assertSame('/home', $back('POST', 'http://acme.example//evil.example/'));
    }

    public function testAWaitAndAStepUpGiveNoButtonUntilTheWaitIsOver(): void
    {
        $container = $this->boot();
        $decision = Decision::deny('Two elements refused.', remedies: [new StepUpRemedy(Duration::minutes(5)), new WaitRemedy(new \DateTimeImmutable('+2 hours'))]);

        $response = $this->answer($container, new NotPermitted('acme.project.remove', $decision), Request::create('/projects', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));
        $remedies = $this->decoded($response)['not_permitted']['remedies'];

        self::assertSame(['wait', 'step_up'], array_column($remedies, 'name'));
        self::assertSame([null, null], array_column($remedies, 'action'));
        self::assertSame('After that, you\'ll be asked to confirm it\'s you.', $remedies[1]['message']);
    }

    public function testScriptsGetJsonInTheShapeTheTwigFunctionGives(): void
    {
        $container = $this->boot();
        $refused = $this->refuse($container, false);
        $request = Request::create('/account/email', server: ['HTTP_ACCEPT' => 'application/json']);

        $response = $this->answer($container, $refused, $request);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $json = $this->decoded($response);
        self::assertSame(['error', 'not_permitted'], array_keys((array) json_decode((string) $response->getContent(), true)), 'Nothing else: no reason.');
        self::assertSame('Before you can do this', $json['error']);
        self::assertSame(['verdict' => 'deny', 'decision' => $refused->decision->id], \array_slice($json['not_permitted'], 0, 2));
        self::assertSame('/yorvoid/flows/yorvoid.step_up?operation=yorvoid.identifier.set&within=300&return=/account/email', $json['not_permitted']['remedies'][0]['action']['url'] ?? null);

        $twig = $container->get(YorvoIDExtension::class);
        \assert($twig instanceof YorvoIDExtension);
        $stack = $container->get('request_stack');
        \assert($stack instanceof RequestStack);
        $stack->push($request);
        self::assertSame($json['not_permitted']['remedies'], $twig->remedies($refused->decision, '/account/email'));
        $german = Request::create('/account/email');
        $german->setLocale('de');
        $stack->push($german);
        self::assertStringStartsWith('Bestätigen Sie', $twig->remedies($refused->decision)[0]['message'], 'In the request\'s language.');
        $stack->pop();
        $stack->pop();
        self::assertStringStartsWith('Confirm it\'s you', $twig->remedies($refused->decision)[0]['message'], 'English, outside a request.');
        $stack->push($request);
        self::assertSame('/yorvoid/flows/yorvoid.step_up?operation=yorvoid.identifier.set&within=300', $twig->remedies($refused->decision, '//evil.example')[0]['action']['url'] ?? null, 'A return that isn\'t a path on this site is left out.');
    }

    public function testAPostReturnsToThePageItCameFromIfItsOnThisSite(): void
    {
        $container = $this->boot();
        $decision = Decision::deny('No recent proof.', remedies: [new StepUpRemedy(Duration::minutes(5))]);
        $url = function (?string $referer) use ($container, $decision): string {
            $request = Request::create('http://acme.example/projects/7/remove', 'POST', server: array_filter(['HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => $referer], static fn (?string $value): bool => null !== $value));

            return $this->decoded($this->answer($container, new NotPermitted('acme.project.remove', $decision), $request))['not_permitted']['remedies'][0]['action']['url'] ?? '';
        };

        self::assertSame('/yorvoid/flows/yorvoid.step_up?within=300&return=/projects?page%3D2', $url('http://acme.example/projects?page=2#list'), 'The path and query, never the address.');
        self::assertSame('/yorvoid/flows/yorvoid.step_up?within=300', $url('http://evil.example/projects'), 'Another site: nowhere.');
        self::assertSame('/yorvoid/flows/yorvoid.step_up?within=300', $url('https://acme.example/projects'), 'Another scheme: nowhere.');
        self::assertSame('/yorvoid/flows/yorvoid.step_up?within=300', $url('http://acme.example:8080/projects'), 'Another port: nowhere.');
        self::assertSame('/yorvoid/flows/yorvoid.step_up?within=300', $url('http://acme.example//evil.example/'), 'A path that isn\'t one: nowhere.');
        self::assertSame('/yorvoid/flows/yorvoid.step_up?within=300', $url('not an address'));
        self::assertSame('/yorvoid/flows/yorvoid.step_up?within=300', $url(null), 'No Referer: the flow ends where it\'s configured to.');
        self::assertSame('/yorvoid/flows/yorvoid.step_up?within=300&return=/', $url('HTTP://ACME.example'), 'The scheme and host as the request has them, in any case; the root.');

        $onPort = $this->decoded($this->answer($container, new NotPermitted('acme.project.remove', $decision), Request::create('http://acme.example:8080/projects/7/remove', 'POST', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://acme.example:8080/projects'])));
        self::assertSame('/yorvoid/flows/yorvoid.step_up?within=300&return=/projects', $onPort['not_permitted']['remedies'][0]['action']['url'] ?? null, 'On its port.');
    }

    public function testARefusalWithoutRemediesOrThatCouldntBeDecided(): void
    {
        $container = $this->boot();

        $denied = $this->answer($container, new NotPermitted('acme.project.remove', Decision::deny('Rule 7: projects of the board.')), Request::create('/projects'));
        self::assertSame(Response::HTTP_FORBIDDEN, $denied->getStatusCode());
        self::assertStringContainsString('You don&#039;t have permission to do this.', (string) $denied->getContent());
        self::assertStringNotContainsString('Rule 7', (string) $denied->getContent());
        self::assertStringNotContainsString('Reference', (string) $denied->getContent(), 'Not recorded: no reference.');

        $unavailable = $this->answer($container, new NotPermitted('acme.project.remove', Decision::indeterminate([IndeterminateReason::StoreUnreachable], 'The relations store is down.')), Request::create('/projects', server: ['HTTP_ACCEPT' => 'application/json']));
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $unavailable->getStatusCode(), 'A failure, for monitoring.');
        self::assertFalse($unavailable->headers->has('Retry-After'));
        self::assertSame(['error' => 'Whether you can do this couldn\'t be checked just now. Please try again in a moment.', 'not_permitted' => ['verdict' => 'indeterminate', 'decision' => null, 'remedies' => []]], $this->decoded($unavailable));
    }

    public function testTheApplicationsRemedyIsTextNeverMarkup(): void
    {
        new Filesystem()->dumpFile($this->directory.'/translations/yorvoid+intl-icu.en.php', '<?php return [\'yorvoid.remedy.acme_terms\' => \'Accept the terms of {title}.\'];');
        $container = $this->boot();
        $remedy = new class implements Remedy {
            public function name(): string
            {
                return 'acme_terms';
            }

            public function parameters(): array
            {
                return ['title' => '<script>alert(1)</script>'];
            }
        };
        $refused = new NotPermitted('acme.project.remove', Decision::deny('Terms.', remedies: [$remedy]));

        $html = (string) $this->answer($container, $refused, Request::create('/projects'))->getContent();
        self::assertStringContainsString('Accept the terms of &lt;script&gt;alert(1)&lt;/script&gt;.', $html);
        self::assertStringNotContainsString('<script>alert', $html);

        $json = (string) $this->answer($container, $refused, Request::create('/projects', server: ['HTTP_ACCEPT' => 'application/json']))->getContent();
        self::assertStringNotContainsString('<script>', $json);
        self::assertSame('<script>alert(1)</script>', self::refusal($json)['not_permitted']['remedies'][0]['parameters']['title'], 'Data, as given.');
    }

    public function testTheApplicationsListenersComeFirst(): void
    {
        $container = $this->boot();
        $dispatcher = $container->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $refused = new NotPermitted('acme.project.remove', Decision::deny('No.'));

        // One at the default priority that looks and leaves it: YorvoID's page.
        $seen = 0;
        $dispatcher->addListener(KernelEvents::EXCEPTION, static function () use (&$seen): void {
            ++$seen;
        });
        self::assertSame(Response::HTTP_FORBIDDEN, $this->answer($container, $refused, Request::create('/projects'))->getStatusCode());
        self::assertSame(1, $seen);

        // One that answers it: its answer.
        $dispatcher->addListener(KernelEvents::EXCEPTION, static function (ExceptionEvent $event): void {
            $event->setResponse(new Response('The application\'s own', 418));
        });
        $response = $this->answer($container, $refused, Request::create('/projects'));
        self::assertSame([418, 'The application\'s own'], [$response->getStatusCode(), $response->getContent()]);

        // Not the main request, or not a refusal: not YorvoID's to answer.
        $listener = $container->get(NotPermittedListener::class);
        \assert($listener instanceof NotPermittedListener);
        \assert(null !== $this->kernel);
        $event = new ExceptionEvent($this->kernel, Request::create('/fragment'), HttpKernelInterface::SUB_REQUEST, $refused);
        $listener($event);
        self::assertNull($event->getResponse());
        $event = new ExceptionEvent($this->kernel, Request::create('/projects'), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Something else.'));
        $listener($event);
        self::assertNull($event->getResponse());
    }

    public function testThePagesCanBeOff(): void
    {
        $container = $this->boot(['remedies' => ['pages' => false]]);

        self::assertFalse($container->has(NotPermittedListener::class));
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $this->answer($container, new NotPermitted('acme.project.remove', Decision::deny('No.')), Request::create('/projects'))->getStatusCode(), 'Symfony\'s error page, as before.');
    }

    /**
     * @param array<string, mixed> $yorvoid
     */
    private function boot(array $yorvoid = []): ContainerInterface
    {
        $this->kernel = new TestKernel($this->directory, $yorvoid);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $database = $container->get(Database::class);
        \assert($database instanceof Database);
        new Migrator($database, Migrations::all())->migrate();

        return $container;
    }

    /**
     * Ana changes her email address from a session: one signed in an hour ago, or a fresh one just
     * after her account was recovered.
     */
    private function refuse(ContainerInterface $container, bool $recovered): NotPermitted
    {
        $principals = $container->get(Principals::class);
        $sessions = $container->get(Sessions::class);
        $operations = $container->get(Operations::class);
        $stepUp = $container->get(StepUp::class);
        \assert($principals instanceof Principals && $sessions instanceof Sessions && $operations instanceof Operations && $stepUp instanceof StepUp);
        $ana = Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));

        if ($recovered) {
            $stepUp->startCoolDown(Realm::default(), $ana->id, new Origin(OriginKind::Console));
        }

        $signedIn = new \DateTimeImmutable($recovered ? 'now' : '-1 hour');
        $session = Permits::call($sessions->start(...), new StartSession(Realm::default(), $ana->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $signedIn, true, true))), new Origin(OriginKind::Web))->session;
        $command = new SetIdentifier(Realm::default(), $ana->id, IdentifierKind::Email, 'ana@example.org');

        try {
            $operations->run($command, $session, new Origin(OriginKind::Web));
        } catch (NotPermitted $refused) {
            return $refused;
        }

        self::fail('It ran.');
    }

    private function answer(ContainerInterface $container, NotPermitted $refused, Request $request): Response
    {
        \assert(null !== $this->kernel);
        $dispatcher = $container->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $stack = $container->get('request_stack');
        \assert($stack instanceof RequestStack);
        $stack->push($request);
        $event = new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $refused);
        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
        $stack->pop();

        $response = $event->getResponse();
        self::assertNotNull($response, 'Something answered it.');

        return $response;
    }

    /**
     * @return array{error: string, not_permitted: array{verdict: string, decision: string|null, remedies: list<array{name: string, parameters: array<string, scalar|null>, message: string, action: array{url: string, label: string}|null}>}}
     */
    private function decoded(Response $response): array
    {
        return self::refusal((string) $response->getContent());
    }

    /**
     * @return array{error: string, not_permitted: array{verdict: string, decision: string|null, remedies: list<array{name: string, parameters: array<string, scalar|null>, message: string, action: array{url: string, label: string}|null}>}}
     */
    private static function refusal(string $json): array
    {
        /** @var array{error: string, not_permitted: array{verdict: string, decision: string|null, remedies: list<array{name: string, parameters: array<string, scalar|null>, message: string, action: array{url: string, label: string}|null}>}} $decoded */
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
