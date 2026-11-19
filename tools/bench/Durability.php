<?php

declare(strict_types=1);

namespace YorvoID\Bench;

/**
 * How PostgreSQL writes its WAL for the benchmarks (M2-8, step 2): the pull request's gate and its A/A
 * record run with it off, main's table and the change since the last release with it on.
 */
final class Durability
{
    /** The settings off for the gate, on otherwise */
    public const array SETTINGS = ['fsync', 'synchronous_commit', 'full_page_writes'];

    /**
     * @param array<string, string> $settings What SHOW gave, by setting
     * @param 'on'|'off'            $expected
     *
     * @return list<string> What isn't as expected; empty if all is
     */
    public static function mismatches(array $settings, string $expected): array
    {
        $wrong = [];

        foreach (self::SETTINGS as $setting) {
            $value = $settings[$setting] ?? null;

            if ($expected !== $value) {
                $wrong[] = \sprintf('%s is %s, not %s', $setting, $value ?? 'unknown', $expected);
            }
        }

        return $wrong;
    }
}
