<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Templates;

/**
 * A unified diff of two texts, line by line, with three lines of context: what yorvoid:templates:diff
 * shows. Templates are short, so the longest common subsequence is found the plain way.
 *
 * @internal
 */
final class UnifiedDiff
{
    private const int CONTEXT = 3;

    /**
     * @return string The diff; empty when the texts are the same
     */
    public static function of(string $from, string $to, string $fromName, string $toName): string
    {
        if ($from === $to) {
            return '';
        }

        $operations = self::operations(self::lines($from), self::lines($to));
        $diff = \sprintf("--- %s\n+++ %s\n", $fromName, $toName);

        foreach (self::hunks($operations) as [$first, $last]) {
            $diff .= self::hunk($operations, $first, $last);
        }

        return $diff;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = explode("\n", $text);

        if ('' === end($lines)) {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * The edit script: each line kept (" "), removed ("-") or added ("+"), in order.
     *
     * @param list<string> $from
     * @param list<string> $to
     *
     * @return list<array{string, string}>
     */
    private static function operations(array $from, array $to): array
    {
        $common = array_fill(0, \count($from) + 1, array_fill(0, \count($to) + 1, 0));

        for ($i = \count($from) - 1; $i >= 0; --$i) {
            for ($j = \count($to) - 1; $j >= 0; --$j) {
                $common[$i][$j] = $from[$i] === $to[$j] ? $common[$i + 1][$j + 1] + 1 : max($common[$i + 1][$j], $common[$i][$j + 1]);
            }
        }

        $operations = [];
        [$i, $j] = [0, 0];

        while ($i < \count($from) || $j < \count($to)) {
            if ($i < \count($from) && $j < \count($to) && $from[$i] === $to[$j]) {
                $operations[] = [' ', $from[$i++]];
                ++$j;
            } elseif ($i < \count($from) && ($j === \count($to) || $common[$i + 1][$j] >= $common[$i][$j + 1])) {
                // Removals first, as diff writes them.
                $operations[] = ['-', $from[$i++]];
            } else {
                $operations[] = ['+', $to[$j++]];
            }
        }

        return $operations;
    }

    /**
     * The changes, with their context, grouped where the context of two would touch.
     *
     * @param list<array{string, string}> $operations
     *
     * @return list<array{int, int}> Each hunk's first and last operation
     */
    private static function hunks(array $operations): array
    {
        $hunks = [];

        foreach ($operations as $index => [$kind]) {
            if (' ' === $kind) {
                continue;
            }

            $first = max(0, $index - self::CONTEXT);
            // Past the end is the end: hunk() takes what there is.
            $last = $index + self::CONTEXT;
            $previous = array_key_last($hunks);

            if (null !== $previous && $first <= $hunks[$previous][1] + 1) {
                $hunks[$previous][1] = $last;
            } else {
                $hunks[] = [$first, $last];
            }
        }

        return $hunks;
    }

    /**
     * @param list<array{string, string}> $operations
     */
    private static function hunk(array $operations, int $first, int $last): string
    {
        $before = \array_slice($operations, 0, $first);
        $lines = \array_slice($operations, $first, $last - $first + 1);
        /** @param list<array{string, string}> $operations */
        $count = static fn (array $operations, string $skipped): int => \count(array_filter($operations, static fn (mixed $operation): bool => \is_array($operation) && $skipped !== $operation[0]));
        $fromLength = $count($lines, '+');
        $toLength = $count($lines, '-');
        // An empty side starts at the line before, as diff writes it.
        $fromStart = $count($before, '+') + (0 === $fromLength ? 0 : 1);
        $toStart = $count($before, '-') + (0 === $toLength ? 0 : 1);
        $hunk = \sprintf("@@ -%d,%d +%d,%d @@\n", $fromStart, $fromLength, $toStart, $toLength);

        foreach ($lines as [$kind, $line]) {
            $hunk .= $kind.$line."\n";
        }

        return $hunk;
    }
}
