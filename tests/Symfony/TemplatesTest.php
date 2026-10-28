<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Symfony\Controller\TemplateGalleryController;
use YorvoID\Symfony\Templates\FlowPages;
use YorvoID\Symfony\Templates\Samples;
use YorvoID\Symfony\Templates\TemplateLint;
use YorvoID\Symfony\Templates\TemplatePublisher;
use YorvoID\Symfony\Templates\TemplateStatus;
use YorvoID\Symfony\Templates\Violation;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * Changing the flow templates safely (UX-006, UX-014): the lint, block overrides, publishing, what
 * changed after an upgrade, and the gallery.
 *
 * Each test in a process of its own: Twig names compiled templates after their path in the project,
 * the same in every test, and PHP keeps the first class of a name.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class TemplatesTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid-templates-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testYorvoIDsOwnPagesHaveWhatEachStepNeeds(): void
    {
        $lint = $this->service(TemplateLint::class);

        self::assertSame([], array_map(strval(...), $lint->lint()));
        self::assertSame([], array_map(strval(...), $lint->lint('de')));
        self::assertSame('Command OK: 23 pages checked: each has what its step needs.', 'Command OK: '.trim((string) preg_replace('/\s+/', ' ', strtr($this->command('yorvoid:templates:lint')[1], ['[OK]' => '']))));
    }

    public function testTheLintFindsWhatAnApplicationsTemplatesLeftOut(): void
    {
        $this->override('passkey', <<<'TWIG'
            {% extends '@YorvoID/flow/layout.html.twig' %}
            {% block title %}Sign in{% endblock %}
            {% block content %}<form method="get" action="/elsewhere"><input name="username" autocomplete="username"><button>Go</button></form>{% endblock %}
            {% block scripts %}{% endblock %}
            TWIG);
        $this->override('setup_code', <<<'TWIG'
            <!DOCTYPE html><html><head><title></title></head><body>
            <form method="post" action="{{ action }}"><input name="code"><input name="email" type="text"></form>
            </body></html>
            TWIG);
        $this->override('confirm', "{% extends '@!YorvoID/flow/confirm.html.twig' %}{% block parameters %}{% endblock %}");
        $this->override('recovery_codes', "{% extends '@!YorvoID/flow/recovery_codes.html.twig' %}{% block content %}<p>{{ view.codes|first }}</p>{% endblock %}");
        $this->override('passkey_registration', <<<'TWIG'
            {% extends '@YorvoID/flow/layout.html.twig' %}
            {% block title %}Add a passkey{% endblock %}
            {% block problems %}{% endblock %}
            {% block content %}<form method="post" action="{{ action }}" data-yorvoid-ceremony="create" data-yorvoid-options="{{ view.options }}"><input type="hidden" name="response"><p data-yorvoid-error></p></form>{% endblock %}
            TWIG);
        $this->override('recovery_code', <<<'TWIG'
            {% extends '@!YorvoID/flow/recovery_code.html.twig' %}
            {% block content %}<form method="post" action="{{ action }}"><label for="e">Email</label><input id="e" name="email"><label for="c">Code</label><input id="c" name="code" aria-describedby="nowhere"></form>{% endblock %}
            TWIG);

        $violations = array_map(strval(...), $this->service(TemplateLint::class)->lint());

        self::assertSame([
            '@YorvoID/flow/passkey.html.twig (default): The field "username" has no label.',
            '@YorvoID/flow/passkey.html.twig (default): A form doesn\'t post: method="post".',
            '@YorvoID/flow/passkey.html.twig (default): A form doesn\'t post to its step: action="{{ action }}".',
            '@YorvoID/flow/passkey.html.twig (default): There\'s no passkey form: <form data-yorvoid-ceremony="get" data-yorvoid-options="{{ view.options }}">.',
            '@YorvoID/flow/passkey.html.twig (default): The email field doesn\'t offer passkeys: autocomplete="username webauthn".',
            '@YorvoID/flow/passkey.html.twig (problems): The field "username" has no label.',
            '@YorvoID/flow/passkey.html.twig (problems): A form doesn\'t post: method="post".',
            '@YorvoID/flow/passkey.html.twig (problems): A form doesn\'t post to its step: action="{{ action }}".',
            '@YorvoID/flow/passkey.html.twig (problems): There\'s no passkey form: <form data-yorvoid-ceremony="get" data-yorvoid-options="{{ view.options }}">.',
            '@YorvoID/flow/passkey.html.twig (problems): The email field doesn\'t offer passkeys: autocomplete="username webauthn".',
            '@YorvoID/flow/setup_code.html.twig (default): The page doesn\'t say its language: <html lang="…">.',
            '@YorvoID/flow/setup_code.html.twig (default): The page has no title.',
            '@YorvoID/flow/setup_code.html.twig (default): The page has no heading: <h1>.',
            '@YorvoID/flow/setup_code.html.twig (default): The field "code" has no label.',
            '@YorvoID/flow/setup_code.html.twig (default): The field "email" has no label.',
            '@YorvoID/flow/setup_code.html.twig (default): There\'s no setup code field: name="code" autocomplete="one-time-code".',
            '@YorvoID/flow/setup_code.html.twig (default): There\'s no email field: name="email" type="email".',
            '@YorvoID/flow/setup_code.html.twig (problems): The page doesn\'t say its language: <html lang="…">.',
            '@YorvoID/flow/setup_code.html.twig (problems): The page has no title.',
            '@YorvoID/flow/setup_code.html.twig (problems): The page has no heading: <h1>.',
            '@YorvoID/flow/setup_code.html.twig (problems): The field "code" has no label.',
            '@YorvoID/flow/setup_code.html.twig (problems): The field "email" has no label.',
            '@YorvoID/flow/setup_code.html.twig (problems): The problem "That setup code didn\'t work. Check it, or create a new one on the server." isn\'t in an alert: role="alert".',
            '@YorvoID/flow/setup_code.html.twig (problems): The field "code" doesn\'t say it\'s invalid: aria-invalid="true".',
            '@YorvoID/flow/setup_code.html.twig (problems): The field "code" doesn\'t say why it\'s invalid: aria-describedby, to its problem.',
            '@YorvoID/flow/setup_code.html.twig (problems): The problem "Enter a valid email address, like name@example.com." isn\'t in an alert: role="alert".',
            '@YorvoID/flow/setup_code.html.twig (problems): The field "email" doesn\'t say it\'s invalid: aria-invalid="true".',
            '@YorvoID/flow/setup_code.html.twig (problems): The field "email" doesn\'t say why it\'s invalid: aria-describedby, to its problem.',
            '@YorvoID/flow/setup_code.html.twig (problems): There\'s no setup code field: name="code" autocomplete="one-time-code".',
            '@YorvoID/flow/setup_code.html.twig (problems): There\'s no email field: name="email" type="email".',
            '@YorvoID/flow/passkey_registration.html.twig (can_skip): There\'s no way to add a passkey later: a button name="action" value="skip".',
            '@YorvoID/flow/passkey_registration.html.twig (problems): The problem "This passkey is already on your account." isn\'t in an alert: role="alert".',
            '@YorvoID/flow/recovery_codes.html.twig (default): A recovery code isn\'t shown: "RS6TV-W7XYZ-AB8CD-EF9GH-JK0MN".',
        ], \array_slice($violations, 0, 33));
        self::assertContains('@YorvoID/flow/recovery_codes.html.twig (default): There\'s no way to confirm the codes were saved: a checkbox name="saved".', $violations);
        self::assertContains('@YorvoID/flow/recovery_code.html.twig (problems): The field "code" doesn\'t say why it\'s invalid: aria-describedby, to its problem.', $violations);
        self::assertContains('@YorvoID/flow/confirm.html.twig (default): The page doesn\'t show what is signed in full: "keep backups" is missing.', $violations);
        self::assertContains('@YorvoID/flow/confirm.html.twig (default): The page doesn\'t show what is signed in full: "no" is missing.', $violations);
        self::assertNotContains('@YorvoID/flow/confirm.html.twig (default): The page doesn\'t show what is signed in full: "Remove the site jane.example" is missing.', $violations, 'The summary is still there.');

        [$status, $output] = $this->command('yorvoid:templates:lint');
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(\sprintf('%d problems in the flow pages:', \count($violations)), $output);
        self::assertStringContainsString('* @YorvoID/flow/passkey.html.twig (default): The field "username" has no label.', $output);
        self::assertSame('x (y): z', '@YorvoID/flow/x.html.twig (y): z' === (string) new Violation('x', 'y', 'z') ? 'x (y): z' : '');
    }

    public function testASingleBlockCanBeChanged(): void
    {
        $this->override('passkey', "{% extends '@!YorvoID/flow/passkey.html.twig' %}{% block alternatives %}<p><a href=\"/help\">Call us</a></p>{% endblock %}");

        $page = new Crawler($this->service(FlowPages::class)->sample(Samples::find('passkey', 'default') ?? self::fail(), 'en'));

        self::assertSame(['Call us'], $page->filter('a')->each(static fn (Crawler $link): string => $link->text()), 'The block changed, and only it.');
        self::assertCount(1, $page->filter('form[data-yorvoid-ceremony="get"]'));
        self::assertSame([], $this->service(TemplateLint::class)->lint());
    }

    public function testPublishesTemplatesToChange(): void
    {
        $this->override('step_up', '{# The application\'s own #}');

        [$status, $output] = $this->command('yorvoid:templates:publish', ['templates' => ['passkey', 'yorvoid.setup', 'passkey']]);
        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(['Published passkey', 'Published setup_code', 'Published passkey_registration', 'Published recovery_codes'], array_values(array_filter(array_map(trim(...), explode("\n", $output)), static fn (string $line): bool => '' !== $line)));

        foreach (['passkey', 'setup_code', 'passkey_registration', 'recovery_codes'] as $template) {
            self::assertFileEquals(\dirname(__DIR__, 2).'/packages/symfony/templates/flow/'.$template.'.html.twig', $this->directory.'/templates/bundles/YorvoIDBundle/flow/'.$template.'.html.twig');
            self::assertFileEquals(\dirname(__DIR__, 2).'/packages/symfony/templates/flow/'.$template.'.html.twig', $this->directory.'/templates/bundles/YorvoIDBundle/.upstream/flow/'.$template.'.html.twig');
        }

        [$status, $output] = $this->command('yorvoid:templates:publish', ['templates' => ['step_up']]);
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('The application has its own "step_up" already', $output);
        self::assertStringEqualsFile($this->directory.'/templates/bundles/YorvoIDBundle/flow/step_up.html.twig', '{# The application\'s own #}');
        self::assertSame(Command::SUCCESS, $this->command('yorvoid:templates:publish', ['templates' => ['step_up'], '--force' => true])[0]);
        self::assertFileEquals(\dirname(__DIR__, 2).'/packages/symfony/templates/flow/step_up.html.twig', $this->directory.'/templates/bundles/YorvoIDBundle/flow/step_up.html.twig');

        [$status, $output] = $this->command('yorvoid:templates:publish', ['templates' => ['nope']]);
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('YorvoID has no flow template "nope".', $output);
        self::assertSame(Command::FAILURE, $this->command('yorvoid:templates:publish', ['templates' => ['acme.unknown']])[0]);

        [$status, $output] = $this->command('yorvoid:templates:publish');
        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Name the templates to publish, or a flow.', $output);
        self::assertStringContainsString('* recovery_link', $output);
        \assert(null !== $this->kernel);
        self::assertStringContainsString("{% extends '@!YorvoID/flow/passkey.html.twig' %}", new Application($this->kernel)->find('yorvoid:templates:publish')->getHelp());

        self::assertSame(['passkey', 'passkey_registration', 'recovery_codes', 'setup_code', 'step_up'], $this->service(TemplatePublisher::class)->published());
        $templates = Samples::TEMPLATES;
        sort($templates);
        self::assertSame($templates, $this->service(TemplatePublisher::class)->templates(), 'Every template YorvoID ships, and only those.');
    }

    public function testTellsWhoChangedWhatAfterAnUpgrade(): void
    {
        $yorvoid = $this->directory.'/yorvoid';
        new Filesystem()->mirror(\dirname(__DIR__, 2).'/packages/symfony/templates/flow', $yorvoid);
        $publisher = new TemplatePublisher($this->directory.'/app', $yorvoid);

        foreach (['passkey', 'setup_code', 'step_up', 'confirm'] as $template) {
            $publisher->publish($template);
        }

        file_put_contents($yorvoid.'/passkey.html.twig', "{# New in YorvoID #}\n".file_get_contents($yorvoid.'/passkey.html.twig'));
        file_put_contents($yorvoid.'/setup_code.html.twig', "{# New in YorvoID #}\n".file_get_contents($yorvoid.'/setup_code.html.twig'));
        file_put_contents($this->directory.'/app/templates/bundles/YorvoIDBundle/flow/setup_code.html.twig', "{# Ours #}\n", \FILE_APPEND);
        unlink($yorvoid.'/confirm.html.twig');

        self::assertSame(
            [TemplateStatus::Upgradable, TemplateStatus::Diverged, TemplateStatus::UpToDate, TemplateStatus::Removed, TemplateStatus::NotPublished],
            array_map($publisher->status(...), ['passkey', 'setup_code', 'step_up', 'confirm', 'invitation']),
        );
        self::assertStringStartsWith("--- published\n+++ YorvoID's now\n@@ -1,", $publisher->changes('passkey'));
        self::assertStringContainsString("\n+{# New in YorvoID #}\n", $publisher->changes('setup_code'));
        self::assertSame('', $publisher->changes('step_up'));

        foreach (['passkey', 'setup_code', 'step_up', 'confirm', 'invitation'] as $template) {
            $publisher->accept($template);
        }

        self::assertStringStartsWith('{# New in YorvoID #}', (string) file_get_contents($this->directory.'/app/templates/bundles/YorvoIDBundle/flow/passkey.html.twig'), 'Taken as it was.');
        self::assertStringEndsWith("{# Ours #}\n", (string) file_get_contents($this->directory.'/app/templates/bundles/YorvoIDBundle/flow/setup_code.html.twig'), 'The application\'s own changes are kept.');
        self::assertSame([TemplateStatus::UpToDate, TemplateStatus::UpToDate, TemplateStatus::UpToDate, TemplateStatus::NotPublished, TemplateStatus::NotPublished], array_map($publisher->status(...), ['passkey', 'setup_code', 'step_up', 'confirm', 'invitation']));
        self::assertFileDoesNotExist($this->directory.'/app/templates/bundles/YorvoIDBundle/invitation.html.twig');
        self::assertSame(['passkey', 'setup_code', 'step_up'], $publisher->published());
    }

    public function testTheDiffCommandSaysWhatToDo(): void
    {
        self::assertSame([Command::SUCCESS, ''], [$this->command('yorvoid:templates:diff')[0], trim($this->command('yorvoid:templates:diff')[1])], 'Nothing published, nothing to say.');
        $this->command('yorvoid:templates:publish', ['templates' => ['passkey', 'step_up']]);
        $old = "{# An older version #}\n";
        file_put_contents($this->directory.'/templates/bundles/YorvoIDBundle/.upstream/flow/passkey.html.twig', $old);
        file_put_contents($this->directory.'/templates/bundles/YorvoIDBundle/flow/passkey.html.twig', $old);

        [$status, $output] = $this->command('yorvoid:templates:diff');
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('YorvoID changed it, and you didn\'t: take the new version as it is.', $output);
        self::assertMatchesRegularExpression('/^ *passkey\n *-+\n/m', $output, 'Each template under its own heading.');
        self::assertStringContainsString("-{# An older version #}\n", $output);
        self::assertStringContainsString('Up to date.', $output);
        self::assertStringContainsString('Run again with --accept', $output);

        [$status, $output] = $this->command('yorvoid:templates:diff', ['--accept' => true]);
        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Took the new version.', $output);
        self::assertFileEquals(\dirname(__DIR__, 2).'/packages/symfony/templates/flow/passkey.html.twig', $this->directory.'/templates/bundles/YorvoIDBundle/flow/passkey.html.twig');

        file_put_contents($this->directory.'/templates/bundles/YorvoIDBundle/.upstream/flow/step_up.html.twig', $old);
        [$status, $output] = $this->command('yorvoid:templates:diff', ['templates' => ['step_up', 'invitation']]);
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('YorvoID changed it, and so did you: merge these changes into your copy.', $output);
        self::assertStringContainsString('Not published.', $output);
        self::assertStringContainsString('Marked as merged', $this->command('yorvoid:templates:diff', ['templates' => ['step_up'], '--accept' => true])[1]);

        file_put_contents($this->directory.'/templates/bundles/YorvoIDBundle/.upstream/flow/gone.html.twig', $old);
        [$status, $output] = $this->command('yorvoid:templates:diff', ['templates' => ['gone']]);
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('YorvoID doesn\'t have it any more', $output);
        self::assertStringContainsString('Forgot the version it came from.', $this->command('yorvoid:templates:diff', ['templates' => ['gone'], '--accept' => true])[1]);
        self::assertSame(Command::SUCCESS, $this->command('yorvoid:templates:diff')[0]);
    }

    public function testPublishingKeepsTheApplicationsOwnUnlessToldOtherwise(): void
    {
        $publisher = new TemplatePublisher($this->directory);
        $publisher->publish('passkey');

        $this->expectExceptionObject(new IntegratorError('The application has its own "passkey" already, in templates/bundles/YorvoIDBundle/flow.', 'Keep it, or publish with --force to replace it with YorvoID\'s.', 'concepts/symfony.md#changing-the-templates'));

        $publisher->publish('passkey');
    }

    public function testPublishingRefusesAnUnknownTemplate(): void
    {
        $this->expectExceptionObject(new IntegratorError('YorvoID has no flow template "../../etc".', 'Publish one of: confirm, expired, failed, fields, invitation, layout, passkey, passkey_registration, recovery_code, recovery_codes, recovery_link, setup_code, step_up.', 'concepts/symfony.md#changing-the-templates'));

        new TemplatePublisher($this->directory)->publish('../../etc');
    }

    public function testTheGalleryShowsEveryPageInEveryLanguage(): void
    {
        $this->boot();
        \assert(null !== $this->kernel);
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();

        $index = $browser->request('GET', '/_yorvoid/templates/');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        self::assertCount(\count(Samples::all()), $index->filter('main > ul > li'));
        self::assertSame(['en', 'YorvoID\'s flow pages', 'YorvoID\'s flow pages', 'Every page with your templates, in every state, with made-up data. Their forms go nowhere.'], [$index->filter('html')->attr('lang'), $index->filter('title')->text(), $index->filter('h1')->text(), $index->filter('main > p')->text()]);
        self::assertSame('passkey, default: en de', $index->filter('li')->first()->text());
        self::assertStringStartsWith('<!DOCTYPE html>', (string) $browser->getResponse()->getContent());
        self::assertStringEndsWith('</ul></main></body></html>', (string) $browser->getResponse()->getContent());
        self::assertSame(['/_yorvoid/templates/passkey/default/en', '/_yorvoid/templates/passkey/default/de'], $index->filter('li')->first()->filter('a')->each(static fn (Crawler $link): ?string => $link->attr('href')));

        $german = $browser->request('GET', '/_yorvoid/templates/passkey/problems/de');
        self::assertSame(['de', 'Anmelden', 'Dieser Passkey hat nicht funktioniert. Versuchen Sie einen anderen, oder melden Sie sich auf andere Weise an.'], [$german->filter('html')->attr('lang'), $german->filter('h1')->text(), $german->filter('[role="alert"]')->text()]);
        self::assertSame('Mit einem Passkey anmelden', $german->filter('button')->text());

        foreach (['/_yorvoid/templates/passkey/nope/en', '/_yorvoid/templates/nope/default/en', '/_yorvoid/templates/passkey/default/fr'] as $missing) {
            $browser->request('GET', $missing);
            self::assertSame(404, $browser->getResponse()->getStatusCode(), $missing);
        }

        $production = new TemplateGalleryController($this->service(FlowPages::class), $this->service(UrlGeneratorInterface::class), false);
        $this->expectException(NotFoundHttpException::class);
        $production->index();
    }

    public function testTheGalleryIsForDevelopmentOnly(): void
    {
        $production = new TemplateGalleryController($this->service(FlowPages::class), $this->service(UrlGeneratorInterface::class), false);

        $this->expectExceptionObject(new NotFoundHttpException('The template gallery is for development only.'));

        $production->page(new \Symfony\Component\HttpFoundation\Request(), 'passkey', 'default', 'en');
    }

    private function override(string $template, string $twig): void
    {
        new Filesystem()->dumpFile($this->directory.'/templates/bundles/YorvoIDBundle/flow/'.$template.'.html.twig', $twig);
    }

    private function boot(): void
    {
        if (null === $this->kernel) {
            new Filesystem()->mkdir($this->directory.'/templates/bundles/YorvoIDBundle');
            $this->kernel = new TestKernel($this->directory);
            $this->kernel->boot();
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
        $this->boot();
        \assert(null !== $this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $service = $container->get($id);
        \assert($service instanceof $id);

        return $service;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function command(string $name, array $input = []): array
    {
        $this->boot();
        \assert(null !== $this->kernel);
        $tester = new CommandTester(new Application($this->kernel)->find($name));
        $status = $tester->execute($input, ['decorated' => false]);

        return [$status, $tester->getDisplay()];
    }
}
