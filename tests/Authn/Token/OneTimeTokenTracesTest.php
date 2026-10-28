<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Token;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Replays executions of the formal model of one-time tokens (spec/tokens/one_time_token.qnt, generated
 * by spec/tokens/traces.mjs) against the implementation: every action the model takes, YorvoID takes,
 * and after each step exactly the tokens the model says work, work. The model's tokens are one
 * principal's tokens for one purpose; one tick of its clock is a minute.
 */
final class OneTimeTokenTracesTest extends DatabaseTestCase
{
    private const string TRACES = __DIR__.'/../../../spec/tokens/traces.json';

    #[DataProvider('implementations')]
    public function testTheImplementationBehavesAsTheModel(string $implementation): void
    {
        $file = json_decode((string) file_get_contents(self::TRACES), true, 16, \JSON_THROW_ON_ERROR);
        \assert(\is_array($file) && \is_array($file['traces']) && \is_int($file['lifetime']));
        $stack = $this->stack($implementation);
        $clock = new MockClock('2026-10-27 18:00:00 UTC');
        $log = new SecurityLog($stack->log, $stack->transactions, clock: $clock);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions, clock: $clock);
        $tokens = new OneTimeTokens($stack->tokens, $principals, $log, $stack->transactions, clock: $clock);
        $steps = 0;

        foreach ($file['traces'] as $number => $trace) {
            \assert(\is_array($trace));
            // Each execution in a realm of its own, starting at the model's time 0.
            $realm = new Realm('trace-'.$number);
            $principal = $principals->create(new CreatePrincipal($realm, PrincipalKind::Human), new Origin(OriginKind::System));
            $start = $clock->now();
            /** @var array<string, Secret> $issued */
            $issued = [];

            foreach ($trace as $i => $step) {
                \assert(\is_array($step) && \is_string($step['action']) && \is_int($step['now']) && \is_array($step['works']));
                $token = \is_string($step['token']) ? $step['token'] : '';
                $where = \sprintf('Execution %d, step %d (%s %s)', $number, $i + 1, $step['action'], $token);

                match ($step['action']) {
                    'issue' => $issued[$token] = $tokens->issue(new IssueToken($realm, $principal->id, 'acme.test', Duration::minutes($file['lifetime'])), new Origin(OriginKind::System))->secret,
                    'tick' => $clock->sleep(60),
                    'consumeAtomically' => self::assertNotNull($tokens->consume($realm, 'acme.test', $issued[$token], new Origin(OriginKind::Web)), $where.': the model used the token, the implementation refused.'),
                    'nothingHappens' => null,
                    default => self::fail('Unknown action '.$step['action']),
                };

                self::assertSame($step['now'] * 60, $clock->now()->getTimestamp() - $start->getTimestamp(), $where.': the clocks differ.');

                foreach ($issued as $name => $secret) {
                    $works = \in_array($name, $step['works'], true);
                    self::assertSame($works, null !== $tokens->peek($realm, 'acme.test', $secret), \sprintf('%s: %s %s.', $where, $name, $works ? 'works in the model, not in the implementation' : 'works in the implementation, not in the model'));

                    if (!$works) {
                        self::assertNull($tokens->consume($realm, 'acme.test', $secret, new Origin(OriginKind::Web)), \sprintf('%s: %s was used, although the model says it doesn\'t work.', $where, $name));
                    }
                }

                ++$steps;
            }

            $clock->sleep(3600);
        }

        self::assertGreaterThan(1000, $steps, 'Too few steps were replayed to mean much.');
    }
}
