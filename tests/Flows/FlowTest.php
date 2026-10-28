<?php

declare(strict_types=1);

namespace YorvoID\Tests\Flows;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Flows\Flow;
use YorvoID\Flows\Step;

final class FlowTest extends TestCase
{
    public function testApplicationsChangeFlowsWithoutChangingTheOriginal(): void
    {
        $flow = new Flow('yorvoid.setup', [new TestStep('code'), new TestStep('passkey')], ['code']);

        $changed = $flow
            ->withStepBefore('code', new TestStep('welcome'))
            ->withStepAfter('code', new TestStep('terms'))
            ->withStepAfter('passkey', new TestStep('profile'))
            ->withoutStep('passkey');

        self::assertSame(['welcome', 'code', 'terms', 'profile'], self::names($changed));
        self::assertSame(['code', 'terms', 'passkey'], self::names($flow->withStepAfter('code', new TestStep('terms'))));
        self::assertSame(['code', 'terms', 'passkey'], self::names($flow->withStepBefore('passkey', new TestStep('terms'))));
        self::assertSame(['code', 'passkey'], self::names($flow));
        self::assertSame(['code'], $changed->required);
        self::assertSame('yorvoid.setup', $changed->name);

        $own = new TestStep('code');
        $replaced = $flow->withStepReplaced($own);
        self::assertSame($own, $replaced->step('code'));
        self::assertSame(['code', 'passkey'], self::names($replaced));
        self::assertNull($flow->step('profile'));
    }

    public function testHowLongAFlowMayTake(): void
    {
        $flow = new Flow('acme.one', [new TestStep('a'), new TestStep('c')]);

        self::assertTrue($flow->lifetime->equals(Duration::minutes(30)));
        self::assertTrue($flow->withLifetime(Duration::hours(1))->lifetime->equals(Duration::hours(1)));
        self::assertTrue($flow->withLifetime(Duration::hours(1))->withStepAfter('a', new TestStep('b'))->lifetime->equals(Duration::hours(1)));
        self::assertTrue($flow->withLifetime(Duration::hours(1))->withoutStep('c')->withStepReplaced(new TestStep('a'))->lifetime->equals(Duration::hours(1)));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, IntegratorError}>
     */
    public static function mistakes(): iterable
    {
        $two = static fn (): Flow => new Flow('acme.two', [new TestStep('a'), new TestStep('b')], ['a']);
        $docs = 'concepts/flows.md#changing-a-flow';
        $unknown = new IntegratorError('The flow "acme.two" has no step "c".', 'Use one of its steps: a, b.', $docs);
        $duplicate = new IntegratorError('The flow "acme.two" needs steps, each with a name of its own.', 'Give every step of the flow a different name.', $docs);

        yield 'a name without segments' => [static fn (): Flow => new Flow('onboarding', [new TestStep('a')]), new IntegratorError('"onboarding" isn\'t a flow\'s name.', 'Name flows in lowercase segments separated by dots, e.g. "acme.onboarding".', $docs)];
        yield 'an uppercase name' => [static fn (): Flow => new Flow('Acme.Onboarding', [new TestStep('a')]), new IntegratorError('"Acme.Onboarding" isn\'t a flow\'s name.', 'Name flows in lowercase segments separated by dots, e.g. "acme.onboarding".', $docs)];
        yield 'a name with more after it' => [static fn (): Flow => new Flow("acme.one\n", [new TestStep('a')]), new IntegratorError("\"acme.one\n\" isn't a flow's name.", 'Name flows in lowercase segments separated by dots, e.g. "acme.onboarding".', $docs)];
        yield 'no steps' => [static fn (): Flow => new Flow('acme.none', []), new IntegratorError('The flow "acme.none" needs steps, each with a name of its own.', 'Give every step of the flow a different name.', $docs)];
        yield 'two steps of one name' => [static fn (): Flow => new Flow('acme.two', [new TestStep('a'), new TestStep('a')]), $duplicate];
        yield 'requiring a step it hasn\'t' => [static fn (): Flow => new Flow('acme.two', [new TestStep('a')], ['a', 'z']), new IntegratorError('The flow "acme.two" requires the step "z", which it doesn\'t have.', 'List only the flow\'s own steps as required.', $docs)];
        yield 'inserting before a step it hasn\'t' => [static fn (): Flow => $two()->withStepBefore('c', new TestStep('d')), $unknown];
        yield 'inserting after a step it hasn\'t' => [static fn (): Flow => $two()->withStepAfter('c', new TestStep('d')), $unknown];
        yield 'inserting a step it has' => [static fn (): Flow => $two()->withStepAfter('a', new TestStep('b')), $duplicate];
        yield 'replacing a step it hasn\'t' => [static fn (): Flow => $two()->withStepReplaced(new TestStep('c')), $unknown];
        yield 'removing a step it hasn\'t' => [static fn (): Flow => $two()->withoutStep('c'), $unknown];
        yield 'removing a required step' => [static fn (): Flow => $two()->withoutStep('a'), new IntegratorError('The flow "acme.two" can\'t do without the step "a".', 'Keep the step, or replace it with one of your own that does what it does.', $docs)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mistakes')]
    public function testMistakesAreAnIntegratorsError(\Closure $mistake, IntegratorError $expected): void
    {
        $this->expectExceptionObject($expected);

        $mistake();
    }

    /**
     * @return list<string>
     */
    private static function names(Flow $flow): array
    {
        return array_map(static fn (Step $step): string => $step->name(), $flow->steps);
    }
}
