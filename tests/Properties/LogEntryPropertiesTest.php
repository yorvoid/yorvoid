<?php

declare(strict_types=1);

namespace YorvoID\Tests\Properties;

use Innmind\BlackBox\PHPUnit\BlackBox;
use Innmind\BlackBox\Set;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Realm;

final class LogEntryPropertiesTest extends TestCase
{
    use BlackBox;

    public function testTheOrderOfKeysNeverChangesTheHash(): void
    {
        self::forAll(self::details())->then(static function (array $details): void {
            self::assertSame(self::hash($details), self::hash(self::reversed($details)));
        });
    }

    public function testChangingAnyValueChangesTheHash(): void
    {
        self::forAll(self::details()->filter(static fn (array $details): bool => [] !== $details), Set::integers())->then(static function (array $details, int $pick): void {
            $keys = array_keys($details);
            $key = $keys[abs($pick) % \count($keys)];
            $changed = $details;
            $changed[$key] = [$details[$key]];

            self::assertNotSame(self::hash($details), self::hash($changed), \sprintf('Changing "%s" kept the hash.', $key));
        });
    }

    /**
     * Maps of up to 6 keys whose values are strings, integers, booleans, null, lists, or maps of the same.
     *
     * @return Set<array<mixed>>
     */
    private static function details(): Set
    {
        $scalar = Set::either(Set::strings()->unicode()->between(0, 12), Set::integers(), Set::of(true, false, null));
        $key = Set::strings()->madeOf(Set::strings()->chars()->lowercaseLetter())->between(1, 6);
        $map = static fn (Set $values): Set => Set::sequence(Set::tuple($key, $values))->between(0, 6)->map(static function (array $pairs): array {
            $map = [];

            foreach ($pairs as [$k, $v]) {
                \assert(\is_string($k));
                $map[$k] = $v;
            }

            return $map;
        });

        return $map(Set::either($scalar, Set::sequence($scalar)->between(0, 3), $map($scalar)));
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function reversed(array $value): array
    {
        $value = array_map(static fn (mixed $item): mixed => \is_array($item) ? self::reversed($item) : $item, $value);

        return array_is_list($value) ? $value : array_reverse($value, true);
    }

    /**
     * @param array<mixed> $value
     */
    private static function hash(array $value): string
    {
        $details = [];

        foreach ($value as $key => $item) {
            $details[(string) $key] = $item;
        }

        return LogEntry::create(Realm::default(), 1, Uuid::fromString('01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c'), new \DateTimeImmutable('2026-10-27 19:00:00 UTC'), 'yorvoid.test.property', Outcome::Success, new Origin(OriginKind::System, null, 'x'), null, null, null, $details, 'previous')->hash;
    }
}
