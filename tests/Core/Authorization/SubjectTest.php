<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\PermitRecording;
use YorvoID\Core\Authorization\DecisionLog\Storage\InMemoryDecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\ConcernsResource;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * The request's subject (G1): the application's own object, for elements to read; the resource is the
 * one it concerns, and it's never recorded or logged.
 */
final class SubjectTest extends TestCase
{
    public function testTheResourceIsTheOneTheSubjectConcerns(): void
    {
        $page = self::page('1');
        $at = new \DateTimeImmutable();
        $ask = static fn (?ResourceRef $resource, ?object $subject): Request => Request::forAbility('acme.pages.edit', new Realm('acme'), $resource, self::member(), new Origin(OriginKind::Web), $at, $subject);

        self::assertSame('page:1', (string) $ask(null, $page)->resource, 'The one it concerns.');
        self::assertSame('page:1', (string) $ask(new ResourceRef('page', '1'), $page)->resource, 'The same one, given too.');
        self::assertSame($page, $ask(null, $page)->subject);
        self::assertSame('acme.pages.edit', $ask(null, $page)->name());
        $item = new \stdClass();
        self::assertSame('content:9', (string) $ask(new ResourceRef('content', '9'), $item)->resource, 'Any subject may come with a resource.');
        self::assertNull($ask(null, $item)->resource, 'One that concerns none is about none.');
        self::assertNull($ask(null, null)->subject);

        foreach ([
            'The subject of "acme.pages.edit" concerns page:1, not page:2.' => static fn (): Request => $ask(new ResourceRef('page', '2'), $page),
            'The subject of "acme.pages.edit" is the resource page:1.' => static fn (): Request => $ask(null, new ResourceRef('page', '1')),
        ] as $message => $asking) {
            try {
                $asking();
                self::fail($message);
            } catch (IntegratorError $error) {
                self::assertStringStartsWith($message, $error->getMessage());
            }
        }
    }

    public function testElementsReadTheSubjectAndNothingRecordsIt(): void
    {
        $element = new class implements PermitElement {
            public function name(): string
            {
                return 'acme.unlocked';
            }

            public function basis(): AuthorizedBy
            {
                return AuthorizedBy::Ability;
            }

            public function matches(Request $request): bool
            {
                return null !== $request->subject;
            }

            public function evaluate(Request $request): Truth
            {
                $subject = $request->subject;
                \assert(null !== $subject && property_exists($subject, 'secret'));

                return match ($subject->secret) {
                    'open-sesame' => Truth::holds('It\'s unlocked.'),
                    'mumble' => throw new \RuntimeException('Can\'t tell.'),
                    default => Truth::fails('It\'s locked.'),
                };
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = $message.json_encode($context, \JSON_THROW_ON_ERROR);
            }
        };
        $store = new InMemoryDecisionLogStore();
        $log = new DecisionLog($store, PermitRecording::All);
        $evaluator = new Evaluator([$element], new StubAuthority(Truth::holds('valid')), logger: $logger, decisions: $log);
        $origin = new Origin(OriginKind::Web);

        $decisions = [
            $evaluator->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), null, $origin, self::page('1', 'open-sesame')),
            $evaluator->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), null, $origin, self::page('2', 'closed-sesame')),
            $evaluator->decideAbility(self::member(), 'acme.pages.edit', new Realm('acme'), null, $origin, self::page('3', 'mumble')),
            ...$evaluator->decideAbilities(self::member(), 'acme.pages.edit', new Realm('acme'), [[null, self::page('4', 'open-sesame')], [new ResourceRef('page', '5'), null]], $origin),
        ];

        self::assertSame([true, false, false, true, false], array_map(static fn ($decision): bool => $decision->permits(), $decisions), 'Read from the subject, alone and in a batch.');
        $log->flush();
        $records = '';

        foreach ($decisions as $decision) {
            $record = $store->find(new Realm('acme'), (string) $decision->id);
            \assert(null !== $record);
            $records .= $record->json();
        }

        self::assertStringContainsString('"resource":["page","4"]', $records, 'The resource it concerns is recorded.');
        self::assertNotSame([], $logger->messages);

        foreach (['sesame', 'mumble'] as $data) {
            self::assertStringNotContainsString($data, $records, 'Never in a record.');
            self::assertStringNotContainsString($data, implode("\n", $logger->messages), 'Nor in the log.');
        }
    }

    private static function page(string $id, string $secret = ''): object
    {
        return new class($id, $secret) implements ConcernsResource {
            public function __construct(
                private readonly string $id,
                public readonly string $secret,
            ) {
            }

            public function resource(): ResourceRef
            {
                return new ResourceRef('page', $this->id);
            }
        };
    }

    private static function member(): Member
    {
        return new Member(new Realm('acme'), new PrincipalId(new UuidV7()));
    }
}
