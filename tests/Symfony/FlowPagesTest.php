<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Cookie;
use YorvoID\Authn\Recovery\GenerateRecoveryCodes;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Symfony\Flow\SessionFlowStore;
use YorvoID\Tests\Support\VirtualAuthenticator;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * YorvoID's flows over HTTP: the reference pages, with forms, and the browser client's JSON (PLT-070,
 * UX-002).
 */
final class FlowPagesTest extends TestCase
{
    private string $directory;

    private TestKernel $kernel;

    private KernelBrowser $browser;

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/flows-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testClaimsAFreshInstallationThroughThePages(): void
    {
        $this->boot();
        $setupCodes = $this->container->get(SetupCodes::class);
        \assert($setupCodes instanceof SetupCodes);
        $code = $setupCodes->create(new CreateSetupCode(Realm::default(), Duration::days(1)), new Origin(OriginKind::Console));

        $this->browser->request('GET', '/yorvoid/flows/yorvoid.setup');
        self::assertSame(303, $this->statusCode());
        $page = $this->browser->followRedirect();
        self::assertMatchesRegularExpression('#^/yorvoid/flows/yorvoid\.setup/[A-Za-z0-9_-]{43}$#', $this->browser->getRequest()->getPathInfo());
        self::assertSame(['en', 'Set up your account', 'Set up your account', 'yorvoid.setup'], [$page->filter('html')->attr('lang'), $page->filter('title')->text(), $page->filter('h1')->text(), $page->filter('body')->attr('data-yorvoid-flow')]);
        self::assertSame(['one-time-code', 'email'], [$page->filter('#yorvoid-code')->attr('autocomplete'), $page->filter('#yorvoid-email')->attr('autocomplete')]);
        self::assertSame(['Setup code', 'Email address'], $page->filter('label')->each(static fn (Crawler $label): string => $label->text()));
        self::assertSame(['yorvoid-code', 'yorvoid-email'], $page->filter('label')->each(static fn (Crawler $label): ?string => $label->attr('for')));
        self::assertSame('/bundles/yorvoid/browser/forms.js', $page->filter('script[type="module"]')->attr('src'));

        $refused = $this->browser->submit($page->filter('form')->form(), ['code' => 'WRONG-CODE', 'email' => 'ana@example.com']);
        self::assertSame(422, $this->statusCode());
        self::assertSame('That setup code didn\'t work. Check it, or create a new one on the server.', $refused->filter('[role="alert"] a[href="#yorvoid-code"]')->text());
        self::assertSame(['true', 'yorvoid-code-problem'], [$refused->filter('#yorvoid-code')->attr('aria-invalid'), $refused->filter('#yorvoid-code')->attr('aria-describedby')]);
        self::assertSame('That setup code didn\'t work. Check it, or create a new one on the server.', $refused->filter('#yorvoid-code-problem')->text());
        self::assertNull($refused->filter('#yorvoid-email')->attr('aria-invalid'));

        $this->browser->submit($refused->filter('form')->form(), ['code' => $code->reveal(), 'email' => 'ana@example.com']);
        self::assertSame(303, $this->statusCode());
        $device = $this->browser->getResponse()->headers->getCookies()[0] ?? null;
        \assert($device instanceof Cookie);
        self::assertSame(['__Host-yorvoid_device', true, true, 'lax', false], [$device->getName(), $device->isSecure(), $device->isHttpOnly(), $device->getSameSite(), $device->isPartitioned()]);
        $registration = $this->browser->followRedirect();
        self::assertSame('Add a passkey', $registration->filter('h1')->text());
        $form = $registration->filter('form[data-yorvoid-ceremony="create"]');
        self::assertCount(0, $registration->filter('button[value="skip"]'), 'The owner needs a passkey.');
        self::assertSame('yorvoid-label-hint', $registration->filter('#yorvoid-label')->attr('aria-describedby'));
        $messages = json_decode((string) $form->attr('data-yorvoid-messages'), true);
        self::assertIsArray($messages);
        self::assertSame('That was cancelled or took too long. Please try again.', $messages['cancelled']);
        self::assertCount(13, $messages);

        $authenticator = new VirtualAuthenticator();
        $this->browser->submit($form->form(), ['response' => $authenticator->register((string) $form->attr('data-yorvoid-options')), 'label' => 'Laptop']);
        $codes = $this->browser->followRedirect();
        self::assertSame('Save your recovery codes', $codes->filter('h1')->text());
        self::assertCount(10, $codes->filter('[data-yorvoid-recovery-codes] code'));

        $this->browser->submit($codes->filter('form')->form(), []);
        self::assertSame(422, $this->statusCode());
        $unsaved = $this->browser->getCrawler();
        self::assertSame(['true', 'yorvoid-saved-problem'], [$unsaved->filter('#yorvoid-saved')->attr('aria-invalid'), $unsaved->filter('#yorvoid-saved')->attr('aria-describedby')]);
        self::assertSame($codes->filter('code')->each(static fn (Crawler $code): string => $code->text()), $unsaved->filter('code')->each(static fn (Crawler $code): string => $code->text()), 'The same codes.');

        $form = $unsaved->filter('form')->form();
        $saved = $form['saved'];
        \assert($saved instanceof \Symfony\Component\DomCrawler\Field\ChoiceFormField);
        $saved->tick();
        $this->browser->submit($form);
        self::assertSame([303, '/'], [$this->statusCode(), $this->browser->getResponse()->headers->get('Location')]);

        $me = $this->get('/me');
        self::assertSame(['setup_code'], $me['methods'], 'Signed in to the firewall from the YorvoID session the flow started.');
        $principals = $this->container->get(Principals::class);
        \assert($principals instanceof Principals);
        self::assertSame((string) $principals->findByIdentifier(Realm::default(), \YorvoID\Core\Principal\IdentifierKind::Email, 'ana@example.com')?->id, $me['user']);
    }

    public function testSignsInWithTheBrowserClientsJson(): void
    {
        $this->boot();
        [$ana, $authenticator] = $this->account();

        $started = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in', []);
        self::assertSame(200, $this->statusCode());
        self::assertSame(['flow' => 'yorvoid.sign_in', 'status' => 'in_progress', 'step' => 'passkey', 'problems' => [], 'redirect' => null], array_diff_key($started, ['id' => 0, 'view' => 0]));
        $id = self::id($started);
        $options = (string) json_encode(((array) $started['view'])['options']);

        $refused = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in/'.$id.'/passkey', ['response' => ['id' => 'x']]);
        self::assertSame(422, $this->statusCode());
        self::assertSame([['message' => 'That passkey didn\'t work. Try another, or another way to sign in.', 'field' => null, 'reason' => null]], $refused['problems']);
        $options = (string) json_encode(((array) $refused['view'])['options']);

        $done = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in/'.$id.'/passkey', ['response' => json_decode($authenticator->signIn($options), true)]);
        self::assertSame(['flow' => 'yorvoid.sign_in', 'id' => $id, 'status' => 'completed', 'step' => null, 'view' => null, 'problems' => [], 'redirect' => '/'], $done);
        self::assertSame((string) $ana->id, $this->get('/me')['user']);
        self::assertSame(['passkey'], $this->get('/me')['methods']);
        $log = $this->container->get(\YorvoID\Core\Log\SecurityLogStore::class);
        \assert($log instanceof \YorvoID\Core\Log\SecurityLogStore);
        $starts = array_values(array_filter(iterator_to_array($log->entries(Realm::default(), 1), false), static fn (\YorvoID\Core\Log\LogEntry $entry): bool => 'yorvoid.session.start' === $entry->action));
        self::assertSame('yorvoid_flow_submit', $starts[\count($starts) - 1]->origin->detail, 'The origin names the route.');
        $cookie = $this->browser->getCookieJar()->get('__Host-yorvoid_device', '/', 'example.com')?->getValue();
        self::assertNotNull($cookie);

        $again = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in', []);
        $this->json('POST', '/yorvoid/flows/yorvoid.sign_in/'.self::id($again).'/passkey', ['response' => json_decode($authenticator->signIn((string) json_encode(((array) $again['view'])['options'])), true)]);
        $resent = $this->browser->getResponse()->headers->getCookies()[0] ?? null;
        \assert($resent instanceof Cookie);
        self::assertSame($cookie, $resent->getValue(), 'A known device keeps its cookie.');

        $form = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in', []);
        $this->browser->request('GET', '/yorvoid/flows/yorvoid.sign_in/'.self::id($form), [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(['step' => 'passkey'], array_intersect_key($this->decoded(), ['step' => 0]), 'Asking for JSON is enough.');
        $this->browser->request('POST', '/yorvoid/flows/yorvoid.sign_in/'.self::id($form).'/passkey', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertSame(['step' => 'passkey'], array_intersect_key($this->decoded(), ['step' => 0]), 'Sending JSON is enough.');

        $this->json('POST', '/yorvoid/flows/yorvoid.sign_in/'.$id.'/passkey', ['response' => []]);
        self::assertSame(404, $this->statusCode(), 'A completed flow is gone.');
    }

    public function testRecoveringSignsInTheAccountRecoveredNotTheOneBefore(): void
    {
        $this->boot();
        [$ana, $authenticator] = $this->account('ana@example.com');
        [$bo, , $codes] = $this->account('bo@example.com');
        $this->signIn($authenticator);
        self::assertSame((string) $ana->id, $this->get('/me')['user']);

        $started = $this->json('POST', '/yorvoid/flows/yorvoid.recover_with_code', []);
        $recovered = $this->json('POST', '/yorvoid/flows/yorvoid.recover_with_code/'.self::id($started).'/recovery_code', ['email' => 'bo@example.com', 'code' => $codes[0]]);
        self::assertSame('passkey_registration', $recovered['step']);
        self::assertTrue(((array) $recovered['view'])['can_skip']);
        self::assertSame('completed', $this->json('POST', '/yorvoid/flows/yorvoid.recover_with_code/'.self::id($started).'/passkey_registration', ['action' => 'skip'])['status']);

        self::assertSame([(string) $bo->id, ['recovery_code']], [$this->get('/me')['user'], $this->get('/me')['methods']]);
    }

    public function testStepsUpForAnOperationTheClientNames(): void
    {
        $this->boot();
        [, $authenticator] = $this->account();
        $this->signIn($authenticator);
        $this->clock()->sleep(600);

        $this->browser->request('GET', '/yorvoid/flows/yorvoid.step_up?operation=acme.site.remove&multi_factor=1');
        $page = $this->browser->followRedirect();
        self::assertSame('Confirm it\'s you', $page->filter('h1')->text());
        $form = $page->filter('form[data-yorvoid-ceremony="get"]');
        self::assertNull($form->attr('data-yorvoid-conditional'));
        $options = (string) $form->attr('data-yorvoid-options');
        self::assertSame('required', ((array) json_decode($options, true))['userVerification']);

        $before = $this->browser->getRequest()->getSession()->get('yorvoid_session');
        $this->browser->submit($form->form(), ['response' => $authenticator->signIn($options)]);
        self::assertSame([303, '/'], [$this->statusCode(), $this->browser->getResponse()->headers->get('Location')]);
        self::assertNotSame($before, $this->browser->getRequest()->getSession()->get('yorvoid_session'), 'The session has a new token (SES-002).');
        self::assertTrue($this->browser->getRequest()->getSession()->has('_security_main'), 'The firewall kept who is signed in.');
        self::assertSame(['passkey'], $this->get('/me')['methods'], 'Stepped up, still signed in.');

        $this->json('POST', '/yorvoid/flows/yorvoid.step_up', ['operation' => 'Remove!']);
        self::assertSame(400, $this->statusCode());
        $this->json('POST', '/yorvoid/flows/yorvoid.step_up', ['operation' => str_repeat('a.', 96).'b']);
        self::assertSame(400, $this->statusCode(), 'At most 191 characters.');
        $this->json('POST', '/yorvoid/flows/yorvoid.step_up', ['operation' => 'a.b.'.str_repeat('c', 187)]);
        self::assertSame(200, $this->statusCode(), '191 characters.');
        $this->json('POST', '/yorvoid/flows/yorvoid.step_up', ['operation' => '']);
        self::assertSame(400, $this->statusCode());
    }

    public function testAStepUpNeedsTwoFactorsOnlyWhenAsked(): void
    {
        $this->boot(['passkeys' => ['user_verification' => 'preferred']]);
        [, $authenticator] = $this->account();
        $this->signIn($authenticator);
        $this->clock()->sleep(600);
        $verification = [];

        foreach (['?operation=acme.site.remove', '?operation=acme.site.remove&multi_factor=1', '?operation=acme.site.remove&multi_factor=0', '?multi_factor=yes'] as $query) {
            $this->browser->request('GET', '/yorvoid/flows/yorvoid.step_up'.$query);
            $verification[] = ((array) json_decode((string) $this->browser->followRedirect()->filter('form')->attr('data-yorvoid-options'), true))['userVerification'];
        }

        foreach ([true, false, 1] as $multiFactor) {
            $verification[] = ((array) ((array) $this->json('POST', '/yorvoid/flows/yorvoid.step_up', ['multi_factor' => $multiFactor])['view'])['options'])['userVerification'];
        }

        self::assertSame(['preferred', 'required', 'preferred', 'preferred', 'required', 'preferred', 'preferred'], $verification);
    }

    public function testConfirmsAnOperationTheApplicationStarted(): void
    {
        $this->boot(['flows' => ['completed_path' => '/home', 'completed_paths' => ['yorvoid.sign_intent' => '/intent-confirmed']]]);
        [, $authenticator] = $this->account();
        $started = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in', []);
        $signedIn = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in/'.self::id($started).'/passkey', ['response' => json_decode($authenticator->signIn((string) json_encode(((array) $started['view'])['options'])), true)]);
        self::assertSame('/home', $signedIn['redirect'], 'The other flows go to the default.');
        $options = '{"challenge":"AQID","rpId":"example.com","allowCredentials":[],"userVerification":"required"}';

        $this->browser->request('GET', '/confirm-intent?options='.urlencode($options));
        $page = $this->browser->followRedirect();

        self::assertSame('Remove the site <jane>', $page->filter('[data-yorvoid-summary]')->text());
        self::assertStringContainsString('&lt;jane&gt;', (string) $this->browser->getResponse()->getContent(), 'Escaped.');
        self::assertSame(['site', 'keep backups'], $page->filter('[data-yorvoid-parameters] dt')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame(['<jane>', 'no'], $page->filter('[data-yorvoid-parameters] dd')->each(static fn (Crawler $value): string => $value->text()));
        self::assertSame(['2026-10-28T18:05:00+00:00', 'You can sign it until 18:05 UTC.'], [$page->filter('time')->attr('datetime'), $page->filter('time')->text()]);

        $this->browser->submit($page->filter('form')->form(), ['response' => $authenticator->signIn($options)]);
        self::assertSame([303, '/intent-confirmed'], [$this->statusCode(), $this->browser->getResponse()->headers->get('Location')]);

        $this->json('POST', '/yorvoid/flows/yorvoid.sign_intent', []);
        self::assertSame(404, $this->statusCode(), 'A request can\'t start signing: only the application can.');
    }

    public function testExpiredAndUnknownFlows(): void
    {
        $this->boot();
        $id = str_repeat('A', 43);

        $page = $this->browser->request('GET', '/yorvoid/flows/yorvoid.sign_in/'.$id);
        self::assertSame(404, $this->statusCode());
        self::assertSame(['This page has expired', 'This page has expired. Start again.', '/yorvoid/flows/yorvoid.sign_in', 'yorvoid.sign_in'], [$page->filter('h1')->text(), $page->filter('[role="alert"]')->text(), $page->filter('a')->attr('href'), $page->filter('body')->attr('data-yorvoid-flow')]);
        self::assertCount(0, $page->filter('script'));

        self::assertSame(['flow' => 'yorvoid.sign_in', 'id' => null, 'status' => 'expired', 'step' => null, 'view' => null, 'problems' => [['message' => 'This page has expired. Start again.', 'field' => null, 'reason' => null]], 'redirect' => null], $this->json('POST', '/yorvoid/flows/yorvoid.sign_in/'.$id.'/passkey', []));
        self::assertSame(404, $this->statusCode());

        $started = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in', []);
        $this->browser->request('GET', '/yorvoid/flows/yorvoid.setup/'.self::id($started));
        self::assertSame(404, $this->statusCode(), 'A flow is only at its own address.');
        $this->json('POST', '/yorvoid/flows/yorvoid.setup/'.self::id($started).'/passkey', []);
        self::assertSame(404, $this->statusCode());
        $this->browser->request('GET', '/yorvoid/flows/yorvoid.sign_in/'.self::id($started));
        self::assertSame(200, $this->statusCode());

        $this->browser->request('GET', '/yorvoid/flows/acme.unknown');
        self::assertSame(404, $this->statusCode());
        $failed = $this->browser->request('GET', '/yorvoid/flows/acme.unknown/'.$id);
        self::assertNull($failed->filter('a')->count() > 0 ? $failed->filter('a')->attr('href') : null, 'No way to start a flow that can\'t start here.');

        $this->browser->request('POST', '/yorvoid/flows/yorvoid.sign_in', [], [], ['CONTENT_TYPE' => 'text/plain'], '{}');
        self::assertSame(400, $this->statusCode());
        $this->browser->request('POST', '/yorvoid/flows/yorvoid.sign_in', [], [], ['CONTENT_TYPE' => 'application/json'], '[1]');
        self::assertSame(400, $this->statusCode());
    }

    public function testAFailedFlowSaysWhy(): void
    {
        $this->boot();

        $this->browser->request('GET', '/yorvoid/flows/yorvoid.invitation?token=nope');
        $page = $this->browser->followRedirect();
        self::assertSame('This invitation doesn\'t work any more. Ask for a new one.', $page->filter('main p')->text());
        self::assertCount(0, $page->filter('form'));

        $failed = $this->browser->submit(new Crawler('<form method="post" action="'.str_replace('/yorvoid/flows/yorvoid.invitation/', '/yorvoid/flows/yorvoid.invitation/', $this->browser->getRequest()->getPathInfo()).'/invitation"></form>', 'https://example.com')->filter('form')->form());
        self::assertSame(410, $this->statusCode());
        self::assertSame(['That didn\'t work', 'This invitation doesn\'t work any more. Ask for a new one.', '/yorvoid/flows/yorvoid.invitation', 'yorvoid.invitation'], [$failed->filter('h1')->text(), $failed->filter('[role="alert"]')->text(), $failed->filter('a')->attr('href'), $failed->filter('body')->attr('data-yorvoid-flow')]);

        $started = $this->json('POST', '/yorvoid/flows/yorvoid.invitation', ['token' => 'nope']);
        self::assertSame(['status' => 'failed', 'problems' => [['message' => 'This invitation doesn\'t work any more. Ask for a new one.', 'field' => null, 'reason' => null]]], array_intersect_key($this->json('POST', '/yorvoid/flows/yorvoid.invitation/'.self::id($started).'/invitation', []), ['status' => 0, 'problems' => 0]));
        self::assertSame(410, $this->statusCode());
    }

    public function testTooManyAttemptsSayWhenToTryAgain(): void
    {
        $this->boot(['limits' => ['per_address' => '1/1h']]);
        $started = $this->json('POST', '/yorvoid/flows/yorvoid.recover_with_code', []);
        $path = '/yorvoid/flows/yorvoid.recover_with_code/'.self::id($started).'/recovery_code';

        $this->json('POST', $path, ['email' => 'a@example.com', 'code' => 'x']);
        $throttled = $this->json('POST', $path, ['email' => 'a@example.com', 'code' => 'x']);

        self::assertSame([429, '3600'], [$this->statusCode(), $this->browser->getResponse()->headers->get('Retry-After')]);
        self::assertSame([['message' => 'Too many attempts. Try again in 60 minutes.', 'field' => null, 'reason' => null]], $throttled['problems']);
    }

    public function testApplicationsAddTheirOwnSteps(): void
    {
        $this->boot(customize: true);
        [, $authenticator] = $this->account();

        $started = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in', []);
        $terms = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in/'.self::id($started).'/passkey', ['response' => json_decode($authenticator->signIn((string) json_encode(((array) $started['view'])['options'])), true)]);

        self::assertSame(['terms', ['version' => 3]], [$terms['step'], $terms['view']]);
        self::assertSame([['message' => 'acme.terms.required', 'field' => 'accepted', 'reason' => null]], $this->json('POST', '/yorvoid/flows/yorvoid.sign_in/'.self::id($started).'/terms', [])['problems']);
        self::assertSame('completed', $this->json('POST', '/yorvoid/flows/yorvoid.sign_in/'.self::id($started).'/terms', ['accepted' => true])['status']);
    }

    public function testABrowserKeepsAFewFlowsGoing(): void
    {
        $this->boot();
        $ids = [];

        for ($i = 0; $i <= SessionFlowStore::LIMIT; ++$i) {
            $ids[] = self::id($this->json('POST', '/yorvoid/flows/yorvoid.sign_in', []));
        }

        $this->browser->request('GET', '/yorvoid/flows/yorvoid.sign_in/'.$ids[0]);
        self::assertSame(404, $this->statusCode(), 'The oldest is forgotten.');

        foreach (\array_slice($ids, 1) as $id) {
            $this->browser->request('GET', '/yorvoid/flows/yorvoid.sign_in/'.$id);
            self::assertSame(200, $this->statusCode());
        }

        $this->json('POST', '/yorvoid/flows/yorvoid.sign_in/'.$ids[1].'/passkey', ['response' => []]);
        $this->browser->request('GET', '/yorvoid/flows/yorvoid.sign_in/'.$ids[1]);
        self::assertSame(200, $this->statusCode(), 'A refused answer keeps the flow.');
    }

    public function testThePagesNeedTwigButTheJsonDoesnt(): void
    {
        $this->boot(twig: false);

        self::assertSame('in_progress', $this->json('POST', '/yorvoid/flows/yorvoid.sign_in', [])['status']);

        $this->browser->catchExceptions(false);
        $this->expectExceptionMessage('YorvoID\'s flow pages need Twig.');

        $this->browser->request('GET', '/yorvoid/flows/yorvoid.sign_in');
        $this->browser->followRedirect();
    }

    /**
     * @param array<string, mixed> $yorvoid
     */
    private function boot(array $yorvoid = [], bool $twig = true, bool $customize = false): void
    {
        $this->kernel = new TestKernel($this->directory, $yorvoid, twig: $twig, customize: $customize);
        $this->kernel->boot();
        $this->browser = new KernelBrowser($this->kernel, ['HTTPS' => 'on', 'HTTP_HOST' => 'example.com']);
        $this->browser->disableReboot();
        $container = $this->browser->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $this->container = $container;
        new CommandTester(new Application($this->kernel)->find('yorvoid:migrate'))->execute([]);
    }

    /**
     * A principal with a passkey and recovery codes.
     *
     * @return array{Principal, VirtualAuthenticator, list<string>}
     */
    private function account(string $email = 'ana@example.com'): array
    {
        $principals = $this->container->get(Principals::class);
        $sessions = $this->container->get(Sessions::class);
        $passkeys = $this->container->get(Passkeys::class);
        $codes = $this->container->get(RecoveryCodes::class);
        \assert($principals instanceof Principals && $sessions instanceof Sessions && $passkeys instanceof Passkeys && $codes instanceof RecoveryCodes);
        $principal = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: $email), new Origin(OriginKind::Console));
        $session = $sessions->start(new StartSession(Realm::default(), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::SetupCode, new \DateTimeImmutable()))), new Origin(OriginKind::Web))->session;
        $authenticator = new VirtualAuthenticator();
        $ceremony = $passkeys->beginRegistration($session);
        $passkeys->finishRegistration($session, $ceremony->id, $authenticator->register($ceremony->options), 'Phone', new Origin(OriginKind::Web));

        return [$principal, $authenticator, array_map(static fn (\YorvoID\Core\Secret $code): string => $code->reveal(), $codes->generate(new GenerateRecoveryCodes(Realm::default()), $session, new Origin(OriginKind::Web)))];
    }

    private function signIn(VirtualAuthenticator $authenticator): void
    {
        $started = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in', []);
        $done = $this->json('POST', '/yorvoid/flows/yorvoid.sign_in/'.self::id($started).'/passkey', ['response' => json_decode($authenticator->signIn((string) json_encode(((array) $started['view'])['options'])), true)]);
        self::assertSame('completed', $done['status']);
    }

    private function clock(): \Symfony\Component\Clock\MockClock
    {
        $clock = $this->container->get('clock');
        \assert($clock instanceof \Symfony\Component\Clock\MockClock);

        return $clock;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, array $body): array
    {
        $this->browser->request($method, $path, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], [] === $body ? '{}' : (string) json_encode($body));
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true);

        return \is_array($decoded) ? array_filter($decoded, \is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true);

        return \is_array($decoded) ? array_filter($decoded, \is_string(...), \ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $this->browser->request('GET', $path);
        self::assertSame(200, $this->statusCode(), (string) $this->browser->getResponse()->getContent());
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true);
        \assert(\is_array($decoded));

        return array_filter($decoded, \is_string(...), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private static function id(array $progress): string
    {
        $id = $progress['id'] ?? null;
        \assert(\is_string($id));

        return $id;
    }

    private function statusCode(): int
    {
        return $this->browser->getResponse()->getStatusCode();
    }
}
