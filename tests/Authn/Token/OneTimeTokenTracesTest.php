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
use YorvoID\Tests\Support\Itf\Itf;
use YorvoID\Tests\Support\Itf\ItfState;
use YorvoID\Tests\Support\Itf\ItfVariant;
use YorvoID\Tests\Support\Itf\Replay;
use YorvoID\Tests\Support\Permits;

/**
 * Replays executions of the formal model of one-time tokens (spec/tokens/one_time_token.qnt, atomic,
 * written by spec/traces.mjs) against the implementation: every action the model takes, YorvoID takes,
 * and after each step exactly the tokens the model says work, work. The model's tokens are one
 * principal's tokens for one purpose; one tick of its clock is a minute.
 */
final class OneTimeTokenTracesTest extends DatabaseTestCase
{
    private const string TRACES = __DIR__.'/../../../spec/tokens/traces.itf.json';

    #[DataProvider('implementations')]
    public function testTheImplementationBehavesAsTheModel(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $clock = new MockClock('2026-10-27 18:00:00 UTC');
        $log = new SecurityLog($stack->log, $stack->transactions, clock: $clock);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions, clock: $clock);
        $tokens = new OneTimeTokens($stack->tokens, $principals, $log, $stack->transactions, clock: $clock);
        $steps = 0;

        foreach (Itf::traces(self::TRACES) as $number => $trace) {
            // Each execution in a realm of its own, starting at the model's time 0.
            $realm = new Realm('trace-'.$number);
            $principal = Permits::call($principals->create(...), new CreatePrincipal($realm, PrincipalKind::Human), new Origin(OriginKind::System));
            $start = $clock->now();
            /** @var array<string, Secret> $issued */
            $issued = [];
            $token = static fn (ItfState $state): string => \is_string($state->pick('t')) ? $state->pick('t') : throw new \LogicException('No token picked.');

            $steps += Replay::trace('Execution '.$number, $trace, [
                'issue' => static function (ItfState $state, ItfState $before) use (&$issued, $tokens, $realm, $principal, $token): void {
                    $t = $token($state);
                    $expiry = $state->var('expiry');
                    \assert(\is_array($expiry) && \is_int($expiry[$t]) && \is_int($state->var('now')));
                    $issued[$t] = Permits::call($tokens->issue(...), new IssueToken($realm, $principal->id, 'acme.test', Duration::minutes($expiry[$t] - $state->var('now'))), new Origin(OriginKind::System))->secret;
                },
                'tick' => static fn (): null => $clock->sleep(60),
                'consumeAtomically' => static function (ItfState $state) use (&$issued, $tokens, $realm, $token): void {
                    self::assertNotNull($tokens->consume($realm, 'acme.test', $issued[$token($state)], new Origin(OriginKind::Web)), 'The model used the token, the implementation refused.');
                },
                'nothingHappens' => static fn (): null => null,
            ], static function (ItfState $state): array {
                $states = $state->var('state');
                $expiry = $state->var('expiry');
                $now = $state->var('now');
                \assert(\is_array($states) && \is_array($expiry) && \is_int($now));
                $works = array_keys(array_filter($states, static fn (mixed $one, string $t): bool => $one instanceof ItfVariant && 'Valid' === $one->tag && $now < $expiry[$t], \ARRAY_FILTER_USE_BOTH));
                sort($works);

                return ['minutes' => $now, 'works' => $works];
            }, static function () use (&$issued, $tokens, $realm, $clock, $start): array {
                $works = array_keys(array_filter($issued, static fn (Secret $secret): bool => null !== $tokens->peek($realm, 'acme.test', $secret)));
                sort($works);

                return ['minutes' => intdiv($clock->now()->getTimestamp() - $start->getTimestamp(), 60), 'works' => $works];
            });

            // What doesn't work can't be used either.
            foreach ($issued as $secret) {
                if (null === $tokens->peek($realm, 'acme.test', $secret)) {
                    self::assertNull($tokens->consume($realm, 'acme.test', $secret, new Origin(OriginKind::Web)));
                }
            }

            $clock->sleep(3600);
        }

        self::assertGreaterThan(1000, $steps, 'Too few steps were replayed to mean much.');
    }
}
