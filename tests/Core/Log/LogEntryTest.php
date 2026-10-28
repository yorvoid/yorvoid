<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Log;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

final class LogEntryTest extends TestCase
{
    /**
     * A known answer: the format of entries is part of what verifiers rely on, so a change to it
     * must be deliberate (and versioned), never a side effect.
     */
    public function testTheHashOfAKnownEntry(): void
    {
        $entry = self::entry(['kind' => 'human', 'status' => 'active']);

        self::assertSame('2026-10-27 18:40:59', $entry->recordedAt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $entry->recordedAt->getTimezone()->getName());
        self::assertSame(LogEntry::ALGORITHM, $entry->algorithm);
        self::assertSame(hash('sha256', '{"format":1,"realm":"acme","sequence":7,"id":"01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c","recorded_at":"2026-10-27T18:40:59Z","action":"yorvoid.principal.create","outcome":"success","origin":["console","yorvoid:principal:create","req-42"],"actor":"01970d9c-8a2e-7b1c-9f3a-000000000001","subject":["principal","01970d9c-8a2e-7b1c-9f3a-000000000002"],"details":{"kind":"human","status":"active"},"previous_hash":"previous"}'), $entry->hash);
        self::assertSame($entry->hash, $entry->computeHash());
    }

    public function testTheHashOfTextWithSlashesAndUnicode(): void
    {
        $entry = self::entry(['path' => '/admin/users', 'name' => 'Ана Ковачевска']);

        self::assertSame(hash('sha256', '{"format":1,"realm":"acme","sequence":7,"id":"01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c","recorded_at":"2026-10-27T18:40:59Z","action":"yorvoid.principal.create","outcome":"success","origin":["console","yorvoid:principal:create","req-42"],"actor":"01970d9c-8a2e-7b1c-9f3a-000000000001","subject":["principal","01970d9c-8a2e-7b1c-9f3a-000000000002"],"details":{"name":"Ана Ковачевска","path":"/admin/users"},"previous_hash":"previous"}'), $entry->hash);
    }

    public function testRecordsTextThatIsntValidUtf8(): void
    {
        // E.g. what someone typed into a failed sign-in: recording it must never fail.
        $entry = LogEntry::create(Realm::default(), 1, Uuid::fromString('01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c'), new \DateTimeImmutable(), 'yorvoid.session.sign_in', Outcome::Denied, new Origin(OriginKind::Web, "/login\xFF"), null, 'identifier', "grace\xC3", ['typed' => "grace\xC3\x28", "key\x80" => ['nested' => "\xE9"]], 'previous');

        self::assertSame("grace\u{FFFD}", $entry->subjectId);
        self::assertSame("/login\u{FFFD}", $entry->origin->detail);
        self::assertSame(["key\u{FFFD}" => ['nested' => "\u{FFFD}"], 'typed' => "grace\u{FFFD}("], $entry->details);
        self::assertSame($entry->hash, $entry->computeHash());
    }

    public function testTheOrderOfDetailsDoesntMatter(): void
    {
        self::assertSame(
            self::entry(['b' => 1, 'a' => ['y' => true, 'x' => null], 'list' => [3, 1, 2]])->hash,
            self::entry(['a' => ['x' => null, 'y' => true], 'list' => [3, 1, 2], 'b' => 1])->hash,
        );
        self::assertNotSame(self::entry(['list' => [3, 1, 2]])->hash, self::entry(['list' => [1, 2, 3]])->hash, 'The order of a list is part of its meaning.');
    }

    public function testEveryFieldIsCovered(): void
    {
        $base = self::entry([]);
        $id = Uuid::fromString('01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c');
        $actor = PrincipalId::fromString('01970d9c-8a2e-7b1c-9f3a-000000000001');
        $origin = new Origin(OriginKind::Console, 'yorvoid:principal:create', 'req-42');
        $at = new \DateTimeImmutable('2026-10-27 18:40:59 UTC');
        $variants = [
            LogEntry::create(new Realm('other'), 7, $id, $at, 'yorvoid.principal.create', Outcome::Success, $origin, $actor, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'previous'),
            LogEntry::create(new Realm('acme'), 8, $id, $at, 'yorvoid.principal.create', Outcome::Success, $origin, $actor, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'previous'),
            LogEntry::create(new Realm('acme'), 7, Uuid::fromString('01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8d'), $at, 'yorvoid.principal.create', Outcome::Success, $origin, $actor, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'previous'),
            LogEntry::create(new Realm('acme'), 7, $id, $at->modify('+1 second'), 'yorvoid.principal.create', Outcome::Success, $origin, $actor, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'previous'),
            LogEntry::create(new Realm('acme'), 7, $id, $at, 'yorvoid.principal.suspend', Outcome::Success, $origin, $actor, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'previous'),
            LogEntry::create(new Realm('acme'), 7, $id, $at, 'yorvoid.principal.create', Outcome::Denied, $origin, $actor, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'previous'),
            LogEntry::create(new Realm('acme'), 7, $id, $at, 'yorvoid.principal.create', Outcome::Success, new Origin(OriginKind::Web, 'yorvoid:principal:create', 'req-42'), $actor, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'previous'),
            LogEntry::create(new Realm('acme'), 7, $id, $at, 'yorvoid.principal.create', Outcome::Success, new Origin(OriginKind::Console, 'other', 'req-42'), $actor, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'previous'),
            LogEntry::create(new Realm('acme'), 7, $id, $at, 'yorvoid.principal.create', Outcome::Success, new Origin(OriginKind::Console, 'yorvoid:principal:create', 'req-43'), $actor, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'previous'),
            LogEntry::create(new Realm('acme'), 7, $id, $at, 'yorvoid.principal.create', Outcome::Success, $origin, null, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'previous'),
            LogEntry::create(new Realm('acme'), 7, $id, $at, 'yorvoid.principal.create', Outcome::Success, $origin, $actor, 'credential', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'previous'),
            LogEntry::create(new Realm('acme'), 7, $id, $at, 'yorvoid.principal.create', Outcome::Success, $origin, $actor, 'principal', 'other', [], 'previous'),
            LogEntry::create(new Realm('acme'), 7, $id, $at, 'yorvoid.principal.create', Outcome::Success, $origin, $actor, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', ['x' => 1], 'previous'),
            LogEntry::create(new Realm('acme'), 7, $id, $at, 'yorvoid.principal.create', Outcome::Success, $origin, $actor, 'principal', '01970d9c-8a2e-7b1c-9f3a-000000000002', [], 'other previous'),
        ];

        foreach ($variants as $i => $variant) {
            self::assertNotSame($base->hash, $variant->hash, \sprintf('Variant %d hashes like the original.', $i));
        }
    }

    public function testAnUnknownAlgorithmGivesNoHash(): void
    {
        $entry = self::entry([]);
        $weak = new LogEntry($entry->realm, $entry->sequence, $entry->id, $entry->recordedAt, $entry->action, $entry->outcome, $entry->origin, $entry->actor, $entry->subjectType, $entry->subjectId, $entry->details, 'md5', $entry->previousHash, $entry->hash);

        self::assertNull($weak->computeHash());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidDetails(): iterable
    {
        yield 'a float' => [['amount' => 1.5], 'details.amount" is a float'];
        yield 'a nested float' => [['money' => ['amount' => 1.0]], 'details.money.amount" is a float'];
        yield 'an object' => [['when' => new \DateTimeImmutable()], 'details.when" is a DateTimeImmutable'];
        yield 'too large' => [['text' => str_repeat('x', LogEntry::MAX_DETAILS_BYTES)], 'larger than 16384 bytes'];
    }

    /**
     * @param array<string, mixed> $details
     */
    #[DataProvider('invalidDetails')]
    public function testRefusesDetailsThatCantBeHashedReliably(array $details, string $message): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage($message);

        self::entry($details);
    }

    public function testAcceptsDetailsUpToTheLimit(): void
    {
        $text = str_repeat('x', LogEntry::MAX_DETAILS_BYTES - \strlen('{"text":""}'));

        self::assertSame($text, self::entry(['text' => $text])->details['text']);
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function invalidFields(): iterable
    {
        yield 'action without an owner' => ['create', null, null];
        yield 'uppercase action' => ['yorvoid.Principal.create', null, null];
        yield 'subject type with a hyphen' => ['yorvoid.principal.create', 'principal-key', 'x'];
        yield 'subject type starting with a digit' => ['yorvoid.principal.create', '2fa', 'x'];
        yield 'subject type too long' => ['yorvoid.principal.create', str_repeat('a', 64), 'x'];
        yield 'subject type with a trailing newline' => ['yorvoid.principal.create', "principal\n", 'x'];
        yield 'empty subject ID' => ['yorvoid.principal.create', 'principal', ''];
        yield 'subject ID too long' => ['yorvoid.principal.create', 'principal', str_repeat('a', 192)];
    }

    #[DataProvider('invalidFields')]
    public function testRefusesInvalidFields(string $action, ?string $subjectType, ?string $subjectId): void
    {
        $this->expectException(IntegratorError::class);

        LogEntry::create(Realm::default(), 1, Uuid::v7(), new \DateTimeImmutable(), $action, Outcome::Success, new Origin(OriginKind::System), null, $subjectType, $subjectId, [], 'previous');
    }

    public function testAcceptsTheLongestSubject(): void
    {
        $entry = LogEntry::create(Realm::default(), 1, Uuid::v7(), new \DateTimeImmutable(), 'yorvoid.principal.create', Outcome::Success, new Origin(OriginKind::System), null, str_repeat('a', 63), str_repeat('b', 191), [], 'previous');

        self::assertSame(191, \strlen((string) $entry->subjectId));
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function entry(array $details): LogEntry
    {
        return LogEntry::create(
            new Realm('acme'),
            7,
            Uuid::fromString('01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c'),
            new \DateTimeImmutable('2026-10-27 19:40:59.987654', new \DateTimeZone('Europe/Skopje')),
            'yorvoid.principal.create',
            Outcome::Success,
            new Origin(OriginKind::Console, 'yorvoid:principal:create', 'req-42'),
            PrincipalId::fromString('01970d9c-8a2e-7b1c-9f3a-000000000001'),
            'principal',
            '01970d9c-8a2e-7b1c-9f3a-000000000002',
            $details,
            'previous',
        );
    }
}
