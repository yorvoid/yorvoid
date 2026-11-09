<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Setup;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Setup\ClaimSetup;
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Authn\Setup\InvalidSetupCode;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\LogEntry;
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
use YorvoID\Tests\Support\Permits;
use YorvoID\Throttle\Throttle;

/**
 * Replays executions of the formal model of the setup claim (spec/setup/setup_claim.qnt, atomic: the
 * claim locks the realm's log before it checks, LOCKED = true) against SetupCodes, by the order the
 * transactions commit in.
 *
 * Why that's enough (see spec/README.md): SetupCodes::claimWith() takes the realm's log lock before it
 * checks and holds it until it commits, the model's LOCKED. Under it the claims and an invitation's
 * principal serialize, so the order they commit or are refused in decides the outcome; what happens
 * inside one transaction is the model's, and SetupClaimConcurrencyTest's with two connections. The test
 * checks that abstraction too: executions in the same order must end the same in the model.
 *
 * The model's invitation accepted is a principal created active another way, its log entry under the
 * lock: Principals::create(), as accepting an invitation does.
 */
final class SetupClaimTracesTest extends DatabaseTestCase
{
    private const string TRACES = __DIR__.'/../../../spec/setup/traces.itf.json';

    #[DataProvider('implementations')]
    public function testTheClaimBehavesAsTheModel(string $implementation): void
    {
        $stack = $this->stack($implementation);
        $clock = new MockClock('2026-11-09 18:00:00 UTC');
        $log = new SecurityLog($stack->log, $stack->transactions, clock: $clock);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions, clock: $clock);
        $setup = new SetupCodes($stack->setup, $principals, $log, $stack->transactions, new Attempts(new Throttle($stack->throttle, $clock)), new Sessions($stack->sessions, $principals, $log, $stack->transactions, clock: $clock), $clock);
        /** @var array<string, array<string, mixed>> $outcomes Each order of commits, and how the model ends after it */
        $outcomes = [];

        foreach (Itf::traces(self::TRACES) as $number => $trace) {
            $realm = new Realm('trace-'.$number);
            $code = Permits::call($setup->create(...), new CreateSetupCode($realm, Duration::days(1)), new Origin(OriginKind::Console));
            $claim = static fn (): bool => self::claims($setup, $realm, $code);
            $where = 'Execution '.$number;
            $order = [];
            $owners = [];
            $first = '';

            foreach ($trace as $i => $state) {
                $before = $trace[$i - 1] ?? null;

                if ('commit' === $state->action && $before instanceof ItfState) {
                    $claimer = $state->pick('c');
                    \assert(\is_string($claimer));
                    $won = 'Won' === self::phase($state, $claimer);
                    $order[] = \sprintf('%s %s', $claimer, $won ? 'won' : 'lost');
                    self::assertSame($won, $claim(), \sprintf('%s, step %d: %s %s in the model.', $where, $i, $claimer, $won ? 'won' : 'lost'));

                    if ($won) {
                        $owners[] = $claimer;
                        $first = '' === $first ? $claimer : $first;
                    }
                } elseif ('accept' === $state->action) {
                    $order[] = 'invitation';
                    Permits::call($principals->create(...), new CreatePrincipal($realm, PrincipalKind::Human), new Origin(OriginKind::Web));
                    $first = '' === $first ? 'invitee' : $first;
                }
            }

            $last = end($trace);
            \assert($last instanceof ItfState);
            $model = ['owners' => self::sorted($last->var('owners')), 'first' => $last->var('first'), 'claimed' => $last->var('claimed'), 'invited' => $last->var('invited')];
            self::assertSame($model, ['owners' => $owners, 'first' => $first, 'claimed' => $setup->isClaimed($realm), 'invited' => \in_array('invitation', $order, true)], $where.': it ends as the model does.');
            $created = array_filter(iterator_to_array($stack->log->entries($realm, 1), false), static fn (LogEntry $entry): bool => 'yorvoid.principal.create' === $entry->action);
            self::assertCount(\count($owners) + (true === $model['invited'] ? 1 : 0), $created, $where.': the losers keep no principal.');

            // A claim after the model's end: refused once the realm is claimed or someone is active in it.
            if (true === $model['claimed'] || true === $model['invited']) {
                self::assertFalse($claim(), $where.': a claim after the end got through.');
            }

            // The abstraction is sound only if the order decides the outcome.
            $signature = implode(' / ', $order);
            self::assertSame($outcomes[$signature] ??= $model, $model, \sprintf('%s: ends otherwise than an execution in the same order (%s): the order doesn\'t decide the outcome.', $where, $signature));
        }

        self::assertGreaterThanOrEqual(2, \count($outcomes), 'Too few orders were replayed to mean much.');
    }

    private static function claims(SetupCodes $setup, Realm $realm, Secret $code): bool
    {
        try {
            Permits::call($setup->claim(...), new ClaimSetup($realm, $code, new CreatePrincipal($realm, PrincipalKind::Human)), new Origin(OriginKind::Web));

            return true;
        } catch (InvalidSetupCode) {
            return false;
        }
    }

    private static function phase(ItfState $state, string $claimer): string
    {
        $phase = $state->var('phase');
        \assert(\is_array($phase) && $phase[$claimer] instanceof ItfVariant);

        return $phase[$claimer]->tag;
    }

    /**
     * @return list<string>
     */
    private static function sorted(mixed $set): array
    {
        \assert(\is_array($set));
        $list = array_values(array_filter($set, \is_string(...)));
        sort($list);

        return $list;
    }
}
