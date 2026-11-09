<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support\Itf;

/**
 * Reads executions of a Quint model in ITF (the Informal Trace Format), as spec/traces.mjs writes them:
 * the checker's own output, for the tests to replay against the code (A-8).
 *
 * Values decode as: "#bigint" an int, "#map" a PHP array when its keys are strings or ints and a list of
 * [key, value] otherwise, "#set" and "#tup" a list, a variant ({tag, value}) an ItfVariant, and a
 * record an array by field.
 */
final class Itf
{
    /**
     * @return list<list<ItfState>> Each execution, its first state the initial one
     */
    public static function traces(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data) || !\is_array($data['traces'] ?? null)) {
            throw new \UnexpectedValueException(\sprintf('%s holds no traces.', $file));
        }

        $traces = [];

        foreach ($data['traces'] as $trace) {
            \assert(\is_array($trace));
            $traces[] = array_values(array_map(self::state(...), $trace));
        }

        return $traces;
    }

    private static function state(mixed $state): ItfState
    {
        \assert(\is_array($state));
        $vars = [];
        $action = null;
        $picks = [];

        foreach ($state as $key => $value) {
            $name = substr((string) $key, (int) strrpos(':'.$key, ':'));

            match ((string) $key) {
                'mbt::actionTaken' => $action = $value,
                'mbt::nondetPicks' => $picks = self::picks($value),
                default => $vars[$name] = self::value($value),
            };
        }

        return new ItfState(\is_string($action) ? $action : throw new \UnexpectedValueException('A state has no action: write the traces with --mbt.'), $picks, $vars);
    }

    /**
     * @return array<string, mixed>
     */
    private static function picks(mixed $picks): array
    {
        \assert(\is_array($picks));
        $chosen = [];

        foreach ($picks as $name => $pick) {
            $value = self::value($pick);
            $chosen[(string) $name] = $value instanceof ItfVariant && 'Some' === $value->tag ? $value->value : null;
        }

        return $chosen;
    }

    public static function value(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        return match (true) {
            \array_key_exists('#bigint', $value) => \is_string($value['#bigint']) || \is_int($value['#bigint']) ? (int) $value['#bigint'] : throw new \UnexpectedValueException('A #bigint isn\'t a number.'),
            \array_key_exists('#tup', $value) => array_map(self::value(...), (array) $value['#tup']),
            \array_key_exists('#set', $value) => array_map(self::value(...), (array) $value['#set']),
            \array_key_exists('#map', $value) => self::map((array) $value['#map']),
            \is_string($value['tag'] ?? null) && \array_key_exists('value', $value) && 2 === \count($value) => new ItfVariant($value['tag'], self::value($value['value'])),
            default => array_map(self::value(...), $value),
        };
    }

    /**
     * @param array<mixed> $pairs
     *
     * @return array<mixed>
     */
    private static function map(array $pairs): array
    {
        $decoded = array_map(static fn (mixed $pair): array => \is_array($pair) ? [self::value($pair[0] ?? null), self::value($pair[1] ?? null)] : [null, null], $pairs);
        $scalar = [] === array_filter($decoded, static fn (array $pair): bool => !\is_string($pair[0]) && !\is_int($pair[0]));

        return $scalar ? array_column($decoded, 1, 0) : $decoded;
    }
}
