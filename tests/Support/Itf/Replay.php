<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support\Itf;

use PHPUnit\Framework\Assert;

/**
 * Replays a model's executions against the code: for each step, the code does what the model's action
 * did, then what the code shows must be what the model's state says, compared through one projection
 * of each. A failure names the execution, the step and the action.
 */
final readonly class Replay
{
    /**
     * @param list<ItfState>                                     $states  The execution, its first state the initial one
     * @param array<string, \Closure(ItfState, ItfState): mixed> $actions What the code does for each of the model's actions,
     *                                                                    given the state after it and the one before
     * @param \Closure(ItfState): mixed                          $model   What the state says, projected
     * @param \Closure(): mixed                                  $code    What the code shows, projected the same way
     *
     * @return int How many steps were replayed
     */
    public static function trace(string $name, array $states, array $actions, \Closure $model, \Closure $code): int
    {
        $steps = 0;

        foreach ($states as $i => $state) {
            if (0 === $i) {
                Assert::assertSame('init', $state->action, $name.' doesn\'t start at init.');
                Assert::assertSame($model($state), $code(), $name.', initially');

                continue;
            }

            $where = \sprintf('%s, step %d (%s %s)', $name, $i, $state->action, json_encode(array_filter($state->picks, static fn (mixed $pick): bool => null !== $pick), \JSON_THROW_ON_ERROR));
            $action = $actions[$state->action] ?? Assert::fail(\sprintf('%s: the replay has nothing for the action "%s".', $where, $state->action));
            $action($state, $states[$i - 1]);
            Assert::assertSame($model($state), $code(), $where);
            ++$steps;
        }

        return $steps;
    }
}
