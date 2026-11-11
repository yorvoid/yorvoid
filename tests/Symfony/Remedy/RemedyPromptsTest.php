<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\Remedy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Remedy;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Duration;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Symfony\Remedy\RemedyPrompts;
use YorvoID\Symfony\YorvoIDBundle;
use YorvoID\Translation\Translator;

/**
 * What a refused person is shown (M2-10): what would satisfy the refusal, in order, with a way to do it
 * only when it can succeed now; how long to wait, rounded up; and nothing of why it was refused.
 */
final class RemedyPromptsTest extends TestCase
{
    private MockClock $clock;

    private RemedyPrompts $prompts;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-10 12:00:00 UTC');
        $routes = new RouteCollection();
        $routes->add('yorvoid_flow_start', new Route('/yorvoid/flows/{flow}'));
        $this->prompts = new RemedyPrompts(new Translator(YorvoIDBundle::TRANSLATIONS), new UrlGenerator($routes, new RequestContext()), $this->clock);
    }

    public function testAStepUpLinksToTheFlowAndComesBack(): void
    {
        $decision = Decision::deny('No recent proof.', remedies: [new StepUpRemedy(Duration::minutes(5), multiFactor: true, operation: 'yorvoid.identifier.set')]);

        self::assertSame([[
            'name' => 'step_up',
            'parameters' => ['max_age' => 300, 'multi_factor' => true, 'phishing_resistant' => false, 'operation' => 'yorvoid.identifier.set'],
            'message' => 'Confirm it\'s you, then try again. This confirms: changing your email address or username.',
            'action' => ['url' => '/yorvoid/flows/yorvoid.step_up?operation=yorvoid.identifier.set&multi_factor=1&within=300&return=/account?tab%3Demail', 'label' => 'Confirm it\'s you'],
        ]], $this->prompts->of($decision, '/account?tab=email', 'en'));

        // An operation without words, no operation, nowhere to return to: the plain message, and only what's given.
        [$prompt] = $this->prompts->of(Decision::deny('No recent proof.', remedies: [new StepUpRemedy(Duration::minutes(1), operation: 'acme.thing.do')]), null, 'en');
        self::assertSame(['Confirm it\'s you, then try again.', '/yorvoid/flows/yorvoid.step_up?operation=acme.thing.do&within=60'], [$prompt['message'], $prompt['action']['url'] ?? null]);
        [$prompt] = $this->prompts->of(Decision::deny('No recent proof.', remedies: [new StepUpRemedy(Duration::minutes(1))]), null, 'de');
        self::assertSame(['Bestätigen Sie, dass Sie es sind, und versuchen Sie es dann noch einmal.', '/yorvoid/flows/yorvoid.step_up?within=60', 'Bestätigen, dass Sie es sind'], [$prompt['message'], $prompt['action']['url'] ?? null, $prompt['action']['label'] ?? null]);
    }

    public function testWhileWaitingThereIsNothingToDoButWait(): void
    {
        $until = $this->clock->now()->modify('+3 hours');
        $remedies = [new StepUpRemedy(Duration::minutes(5)), new class implements Remedy {
            public function name(): string
            {
                return 'acme_terms';
            }

            public function parameters(): array
            {
                return [];
            }
        }, new WaitRemedy($until)];

        $prompts = $this->prompts->of(Decision::deny('Recovered, and no recent proof.', remedies: $remedies), '/account', 'en');

        self::assertSame(['wait', 'step_up', 'acme_terms'], array_column($prompts, 'name'), 'Waits first, then step-ups, then the rest.');
        self::assertSame(['You can do this again in about 3 hours.', 'After that, you\'ll be asked to confirm it\'s you.', 'Something more is needed before you can do this. Please ask whoever manages your account.'], array_column($prompts, 'message'));
        self::assertSame([null, null, null], array_column($prompts, 'action'), 'A proof made during the wait would be stale when it ends.');
        self::assertSame(['until' => '2026-11-10T15:00:00+00:00', 'reason' => 'unspecified'], $prompts[0]['parameters']);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function waits(): iterable
    {
        yield 'over' => [0, 'You can try again now.'];
        yield 'past' => [-30, 'You can try again now.'];
        yield 'a second' => [1, 'You can do this again in about 1 minute.'];
        yield '59 s' => [59, 'You can do this again in about 1 minute.'];
        yield '60 s' => [60, 'You can do this again in about 1 minute.'];
        yield '61 s' => [61, 'You can do this again in about 2 minutes.'];
        yield '59 m' => [3540, 'You can do this again in about 59 minutes.'];
        yield '59 m 1 s' => [3541, 'You can do this again in about 1 hour.'];
        yield '1 h' => [3600, 'You can do this again in about 1 hour.'];
        yield '1 h 1 s' => [3601, 'You can do this again in about 2 hours.'];
        yield '23 h' => [82800, 'You can do this again in about 23 hours.'];
        yield '23 h 59 m' => [86340, 'You can do this again in about 1 day.'];
        yield '24 h' => [86400, 'You can do this again in about 1 day.'];
        yield '24 h 1 s' => [86401, 'You can do this again in about 2 days.'];
        yield '7 days' => [604800, 'You can do this again in about 7 days.'];
    }

    #[DataProvider('waits')]
    public function testAWaitIsRoundedUp(int $seconds, string $message): void
    {
        [$prompt] = $this->prompts->of(Decision::deny('Held.', remedies: [new WaitRemedy($this->clock->now()->modify(\sprintf('%+d seconds', $seconds)))]), null, 'en');

        self::assertSame($message, $prompt['message']);
    }

    public function testTheApplicationsRemedyHasItsOwnMessageAndNoAction(): void
    {
        $directory = \sprintf('%s/yorvoid-remedies-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        mkdir($directory);
        file_put_contents($directory.'/yorvoid+intl-icu.en.php', '<?php return '.var_export(['yorvoid.remedy.acme_terms' => 'Accept the terms of {title} (version {version}, signed: {signed}, by {by}).'], true).';');
        $translator = new Translator([...YorvoIDBundle::TRANSLATIONS, $directory]);
        $routes = new RouteCollection();
        $routes->add('yorvoid_flow_start', new Route('/yorvoid/flows/{flow}'));
        $prompts = new RemedyPrompts($translator, new UrlGenerator($routes, new RequestContext()), $this->clock);
        $remedy = new class implements Remedy {
            public function name(): string
            {
                return 'acme_terms';
            }

            public function parameters(): array
            {
                return ['title' => 'Acme <b>', 'version' => 3, 'signed' => false, 'by' => null];
            }
        };

        $refused = $prompts->of(Decision::deny('Terms.', remedies: [$remedy]), null, 'en');
        unlink($directory.'/yorvoid+intl-icu.en.php');
        rmdir($directory);

        self::assertSame([['name' => 'acme_terms', 'parameters' => ['title' => 'Acme <b>', 'version' => 3, 'signed' => false, 'by' => null], 'message' => 'Accept the terms of Acme <b> (version 3, signed: 0, by ).', 'action' => null]], $refused, 'Text, not markup: the page escapes it.');
    }

    public function testThePageSaysWhatKindOfRefusalNeverWhy(): void
    {
        self::assertSame(['title' => 'Before you can do this', 'text' => null], $this->prompts->summary(Decision::deny('The secret rule 7 refused it.', remedies: [new WaitRemedy($this->clock->now())]), 'en'));
        self::assertSame(['title' => 'You can\'t do this', 'text' => 'You don\'t have permission to do this. If you think you should, ask whoever manages your account.'], $this->prompts->summary(Decision::deny('The secret rule 7 refused it.'), 'en'));
        self::assertSame(['title' => 'This couldn\'t be checked', 'text' => 'Whether you can do this couldn\'t be checked just now. Please try again in a moment.'], $this->prompts->summary(Decision::indeterminate([IndeterminateReason::StoreUnreachable], 'The store is down.'), 'en'));
        self::assertSame('Das können Sie nicht tun', $this->prompts->summary(Decision::deny('No.'), 'de')['title']);

        self::assertNull($this->prompts->reference(Decision::deny('No.'), 'en'), 'A decision that wasn\'t recorded has no reference.');
        self::assertSame('Reference: 019a6e2c-8a00-7000-8000-000000000001', $this->prompts->reference(Decision::deny('No.')->identified('019a6e2c-8a00-7000-8000-000000000001', $this->clock->now(), []), 'en'));
    }

    public function testOnlyARefusalIsForbiddenWhatCouldntBeDecidedIsUnavailable(): void
    {
        self::assertSame([true, true, false, false], array_map(RemedyPrompts::unavailable(...), [Verdict::Indeterminate, Verdict::PermitOffline, Verdict::Deny, Verdict::Permit]));
    }
}
