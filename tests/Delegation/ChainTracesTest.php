<?php

declare(strict_types=1);

namespace YorvoID\Tests\Delegation;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Operation\UnderDelegation;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Delegation\Change\IssueDelegation;
use YorvoID\Delegation\Change\RevokeDelegation;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\UnassignRole;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\Itf\Itf;
use YorvoID\Tests\Support\Itf\ItfState;
use YorvoID\Tests\Support\Itf\Replay;
use YorvoID\Tests\Support\TestPrincipal;

/**
 * Replays executions of the formal model of delegation chains over time (spec/delegation/chains.qnt,
 * checked) against the delegation package and core's evaluator: delegations issued, passed on, revoked
 * and expiring, the root's role taken and given back. After each step, every delegation the model
 * issued decides as the model says it's valid now, its delegate publishing a page under it. One tick of
 * the model is an hour; "permitted" is having the editor role.
 */
final class ChainTracesTest extends TestCase
{
    private const string TRACES = __DIR__.'/../../spec/delegation/traces.itf.json';

    private const string START = '2026-11-08 20:00:00';

    private const int MAX_DEPTH = 3;

    public function testChainsDecideAsTheModel(): void
    {
        $steps = 0;
        $uses = 0;

        foreach (Itf::traces(self::TRACES) as $number => $trace) {
            $world = new PolicyWorld(delegations: true);
            $world->clock->modify(self::START);
            $start = $world->clock->now();
            /** @var array<string, TestPrincipal> $people */
            $people = [];

            foreach (['ana', 'agent1', 'agent2', 'agent3'] as $name) {
                $people[$name] = 'ana' === $name ? $world->principal('editor') : $world->principal();
            }

            /** @var array<int, DelegationId> $ids The model's delegation => the code's */
            $ids = [];
            $at = static fn (int $hours): \DateTimeImmutable => $start->modify(\sprintf('+%d hours', $hours));
            $issue = static function (ItfState $state) use ($world, $people, &$ids, $at): void {
                $id = self::int($state->pick('id'));
                $made = self::issued($state)[$id];
                $depth = self::int($made['depth']);
                $delegation = $world->as($people[self::str($made['delegator'])], new IssueDelegation(
                    $world->realm,
                    $people[self::str($made['delegate'])]->principalId ?? throw new \LogicException(),
                    ['acme.pages.publish'],
                    new Selector('page'),
                    $at(self::int($made['expires'])),
                    redelegable: $depth > 0 ? ['acme.pages.publish'] : [],
                    depth: $depth,
                    parent: 0 === self::int($made['parent']) ? null : $ids[self::int($made['parent'])],
                ));
                $ids[$id] = $delegation->id;
            };
            $role = static fn (bool $give): \Closure => static function (ItfState $state) use ($world, $people, $give): void {
                $who = $people[self::str($state->pick('p'))]->principalId ?? throw new \LogicException();
                $world->asOperator($give ? new AssignRole($world->realm, $who, 'editor') : new UnassignRole($world->realm, $who, 'editor'));
            };

            $steps += Replay::trace('Execution '.$number, $trace, [
                'issueRoot' => $issue,
                'issueChild' => $issue,
                'revoke' => static function (ItfState $state, ItfState $before) use ($world, &$ids): void {
                    $id = self::int($state->pick('id'));

                    if (!(bool) self::issued($before)[$id]['revoked']) {
                        $world->asOperator(new RevokeDelegation($world->realm, $ids[$id], 'done'));
                    }
                },
                'tick' => static function () use ($world): void {
                    $world->clock->modify('+1 hour');
                },
                'losePermission' => $role(false),
                'gainPermission' => $role(true),
                'actUnder' => static function () use (&$uses): void {
                    ++$uses;
                },
            ], static fn (ItfState $state): array => self::valid($state), static function () use ($world, $people, &$ids): array {
                $valid = [];

                foreach ($ids as $model => $id) {
                    $delegation = $world->delegationStore?->get($world->realm, $id) ?? throw new \LogicException();
                    $delegate = array_find($people, static fn (TestPrincipal $p): bool => true === $p->principalId?->equals($delegation->delegate)) ?? throw new \LogicException();
                    $valid[$model] = Verdict::Permit === $world->policy->decide(new UnderDelegation($delegate, $id), 'acme.pages.publish', $world->realm, new ResourceRef('page', 'p-1'), new Origin(OriginKind::Web))->verdict;
                }

                ksort($valid);

                return $valid;
            });
        }

        self::assertGreaterThan(1000, $steps, 'Too few steps were replayed to mean much.');
        self::assertGreaterThan(20, $uses, 'Too few uses.');
    }

    /**
     * Which delegations are valid now, as the model's validNow() says.
     *
     * @return array<int, bool>
     */
    private static function valid(ItfState $state): array
    {
        $issued = self::issued($state);
        $now = self::int($state->var('now'));
        $permitted = (array) $state->var('permitted');
        $valid = [];

        foreach (array_keys($issued) as $id) {
            $chain = [$id];
            $at = $id;

            for ($i = 0; $i < self::MAX_DEPTH && 0 !== self::int($issued[$at]['parent']); ++$i) {
                $chain[] = $at = self::int($issued[$at]['parent']);
            }

            $root = $issued[$at];
            $valid[$id] = 0 === self::int($root['parent'])
                && [] === array_filter($chain, static fn (int $x): bool => (bool) $issued[$x]['revoked'] || $now >= self::int($issued[$x]['expires']))
                && \in_array($root['delegator'], $permitted, true);
        }

        ksort($valid);

        return $valid;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function issued(ItfState $state): array
    {
        $issued = [];

        foreach ((array) $state->var('issued') as $id => $delegation) {
            \assert(\is_int($id) && \is_array($delegation));
            $issued[$id] = array_combine(array_map(strval(...), array_keys($delegation)), $delegation);
        }

        return $issued;
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : throw new \LogicException('Not a number of the model\'s.');
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('Not a name of the model\'s.');
    }
}
