<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Policy\Attribute\AttributeResolver;
use YorvoID\Policy\Attribute\Attributes;
use YorvoID\Policy\Attribute\AttributeType;
use YorvoID\Policy\Attribute\ForgetAttributesOfDeletedPrincipals;
use YorvoID\Policy\Attribute\MissingAttribute;
use YorvoID\Policy\Attribute\SecurityAttribute;
use YorvoID\Policy\Attribute\SetAttribute;
use YorvoID\Policy\Attribute\UnknownAttribute;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;
use YorvoID\Policy\Storage\InMemoryAttributeStore;
use YorvoID\Tests\Policy\Fixtures\AcmeAttributes;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\RecordingDispatcher;

/**
 * Security attributes (IDN-055) and attributes of the request (AUZ-013), read by rules; one that isn't
 * known makes the decision Indeterminate, never a Permit (AUZ-007).
 */
final class AttributesTest extends TestCase
{
    public function testARuleReadsThePrincipalsAttributes(): void
    {
        $world = new PolicyWorld(rulesOf: static fn (Attributes $attributes): array => [self::researchOnly($attributes)]);
        $ada = $world->principal();
        $bob = $world->principal();
        $world->asOperator(new SetAttribute($world->realm, self::id($ada), 'acme.department', 'R&D'));
        $world->asOperator(new SetAttribute($world->realm, self::id($bob), 'acme.department', 'Sales'));

        self::assertTrue($world->allows($ada, 'acme.pages.publish'), 'The rule permits R&D.');
        self::assertSame(Verdict::Deny, $world->decide($bob, 'acme.pages.publish')->verdict);

        $unknown = $world->decide($world->principal(), 'acme.pages.publish');
        self::assertSame(Verdict::Indeterminate, $unknown->verdict, 'A principal without a department: it can\'t be told.');
        self::assertSame([IndeterminateReason::UnknownForbid, IndeterminateReason::MissingData], $unknown->indeterminate, 'A rule that can\'t tell may refuse.');
        self::assertStringContainsString('The acting principal has no "acme.department".', $unknown->reason);
        self::assertSame([IndeterminateReason::UnknownForbid, IndeterminateReason::MissingData], $world->decide(new Anonymous(), 'acme.pages.publish')->indeterminate);

        $world->asOperator(new SetAttribute($world->realm, self::id($ada), 'acme.department', null));
        self::assertSame(Verdict::Indeterminate, $world->decide($ada, 'acme.pages.publish')->verdict, 'Removed.');
    }

    public function testThePrincipalsAttributesAreReadOncePerRequest(): void
    {
        $store = new class(new InMemoryAttributeStore()) implements \YorvoID\Policy\Attribute\AttributeStore {
            public int $reads = 0;

            public function __construct(
                private readonly InMemoryAttributeStore $store,
            ) {
            }

            public function of(\YorvoID\Core\Realm $realm, PrincipalId $principalId): array
            {
                ++$this->reads;

                return $this->store->of($realm, $principalId);
            }

            public function set(\YorvoID\Core\Realm $realm, PrincipalId $principalId, string $name, string|int|bool|array $value): void
            {
                $this->store->set($realm, $principalId, $name, $value);
            }

            public function remove(\YorvoID\Core\Realm $realm, PrincipalId $principalId, string $name): bool
            {
                return $this->store->remove($realm, $principalId, $name);
            }

            public function forget(\YorvoID\Core\Realm $realm, PrincipalId $principalId): void
            {
                $this->store->forget($realm, $principalId);
            }
        };
        $ada = new PrincipalId(new UuidV7());
        $store->set(new \YorvoID\Core\Realm('acme'), $ada, 'acme.department', 'R&D');
        $store->set(new \YorvoID\Core\Realm('acme'), $ada, 'acme.clearance', 3);
        $attributes = new Attributes([new AcmeAttributes()], $store);
        $request = Request::forAbility('acme.pages.view', new \YorvoID\Core\Realm('acme'), null, new \YorvoID\Tests\Core\Operation\Fixtures\Badges\Member(new \YorvoID\Core\Realm('acme'), $ada), new Origin(OriginKind::Web), new \DateTimeImmutable());

        self::assertSame('R&D', $attributes->principal($request, 'acme.department'));
        self::assertSame(3, $attributes->principal($request, 'acme.clearance'));
        self::assertSame(1, $store->reads);
    }

    public function testResolversAnswerForTheRequest(): void
    {
        $office = new class implements AttributeResolver {
            public function resolves(string $name): bool
            {
                return 'acme.network' === $name;
            }

            public function resolve(Request $request, string $name): ?string
            {
                return '1' === $request->resource?->id ? 'office' : null;
            }
        };
        $attributes = new Attributes([new AcmeAttributes()], new InMemoryAttributeStore(), [$office]);
        $request = static fn (string $page): Request => Request::forAbility('acme.pages.view', new \YorvoID\Core\Realm('acme'), new \YorvoID\Core\Authorization\ResourceRef('page', $page), new Anonymous(), new Origin(OriginKind::Web), new \DateTimeImmutable());

        self::assertSame('office', $attributes->resolve($request('1'), 'acme.network'));

        foreach ([[$request('2'), 'acme.network'], [$request('1'), 'acme.weather']] as [$for, $name]) {
            try {
                $attributes->resolve($for, $name);
                self::fail('An unknown attribute was resolved.');
            } catch (MissingAttribute $missing) {
                self::assertSame($name, $missing->attribute);
                self::assertSame(\sprintf('The request has no "%s".', $name), $missing->getMessage());
            }
        }
    }

    public function testAttributesAreDeclaredAndOfTheirType(): void
    {
        $world = new PolicyWorld();
        $ada = $world->principal();

        foreach ([['acme.ghost', 'x', 'The attribute "acme.ghost" isn\'t declared.'], ['acme.clearance', 'high', 'The attribute "acme.clearance" takes a value of the type integer.'], ['acme.contractor', 'no', 'The attribute "acme.contractor" takes a value of the type boolean.'], ['acme.department', 7, 'The attribute "acme.department" takes a value of the type string.']] as [$name, $value, $message]) {
            try {
                $world->asOperator(new SetAttribute($world->realm, self::id($ada), $name, $value));
                self::fail($message);
            } catch (UnknownAttribute $error) {
                self::assertSame($message, $error->getMessage());
            }
        }

        $world->asOperator(new SetAttribute($world->realm, self::id($ada), 'acme.teams', ['blue']));
        $world->asOperator(new SetAttribute($world->realm, self::id($ada), 'acme.clearance', 3));
        $world->asOperator(new SetAttribute($world->realm, self::id($ada), 'acme.contractor', true));
        self::assertSame(['acme.clearance' => 3, 'acme.contractor' => true, 'acme.teams' => ['blue']], $world->memory->attributes->of($world->realm, self::id($ada)));
        self::assertSame([['attribute' => 'acme.teams', 'removed' => false], 'principal'], [self::entries($world)[0]->details, self::entries($world)[0]->subjectType], 'The name, never the value.');

        $this->expectException(UnknownPrincipal::class);
        $world->asOperator(new SetAttribute($world->realm, new PrincipalId(new UuidV7()), 'acme.department', 'R&D'));
    }

    public function testAttributesAreNamedAndDeclaredOnce(): void
    {
        $attributes = new Attributes([new AcmeAttributes()], new InMemoryAttributeStore());
        self::assertSame(AttributeType::Integer, $attributes->declared('acme.clearance')?->type);
        self::assertNull($attributes->declared('acme.ghost'));

        try {
            new Attributes([new AcmeAttributes(), new AcmeAttributes()], new InMemoryAttributeStore());
            self::fail('An attribute was declared twice.');
        } catch (IntegratorError $error) {
            self::assertStringStartsWith('The attribute "acme.department" is declared twice.', $error->getMessage());
        }

        try {
            new SecurityAttribute('department', 'Department', 'acme');
            self::fail('An attribute outside a namespace was taken.');
        } catch (IntegratorError $error) {
            self::assertStringStartsWith('The attribute name "department" is invalid.', $error->getMessage());
        }

        $request = Request::forAbility('acme.pages.view', new \YorvoID\Core\Realm('acme'), null, new Anonymous(), new Origin(OriginKind::Web), new \DateTimeImmutable());

        try {
            $attributes->principal($request, 'acme.ghost');
            self::fail('An undeclared attribute was read.');
        } catch (IntegratorError $error) {
            self::assertStringStartsWith('The attribute "acme.ghost" isn\'t declared.', $error->getMessage());
        }

        self::assertTrue(AttributeType::Strings->fits([]));
        self::assertFalse(AttributeType::Strings->fits('a'));
        self::assertFalse(AttributeType::Strings->fits(['a', 1]));
        self::assertFalse(AttributeType::Strings->fits(['x' => 'a']));
        self::assertTrue(AttributeType::Strings->fits(['a', 'b']));
    }

    public function testADeletedPrincipalKeepsNoAttribute(): void
    {
        $world = new PolicyWorld();
        $ada = $world->principal();
        $world->asOperator(new SetAttribute($world->realm, self::id($ada), 'acme.department', 'R&D'));
        $events = new RecordingDispatcher();
        $events->listen(PrincipalStatusChanging::class, new ForgetAttributesOfDeletedPrincipals($world->memory->attributes)(...));
        $principals = new Principals($world->memory->principals, $world->memory->identifiers, new SecurityLog($world->memory->log, $world->memory->transactions), $world->memory->transactions, events: $events);

        Permits::call($principals->suspend(...), new SuspendPrincipal($world->realm, self::id($ada)), new Origin(OriginKind::Console));
        self::assertCount(1, $world->memory->attributes->of($world->realm, self::id($ada)), 'Suspending keeps them.');
        Permits::call($principals->delete(...), new DeletePrincipal($world->realm, self::id($ada)), new Origin(OriginKind::Console));
        self::assertSame([], $world->memory->attributes->of($world->realm, self::id($ada)));
    }

    private static function researchOnly(Attributes $attributes): Rule
    {
        return new readonly class($attributes) implements Rule {
            public function __construct(
                private Attributes $attributes,
            ) {
            }

            public function name(): string
            {
                return 'acme.research_publishes';
            }

            public function matches(Request $request): bool
            {
                return 'acme.pages.publish' === $request->ability;
            }

            public function decide(Request $request): RuleAnswer
            {
                return 'R&D' === $this->attributes->principal($request, 'acme.department') ? RuleAnswer::permit('R&D publishes.') : RuleAnswer::abstain();
            }
        };
    }

    private static function id(Actor $actor): PrincipalId
    {
        \assert(null !== $actor->principalId);

        return $actor->principalId;
    }

    /**
     * @return list<LogEntry>
     */
    private static function entries(PolicyWorld $world): array
    {
        return array_values(array_filter(iterator_to_array($world->memory->log->entries($world->realm, 1), false), static fn (LogEntry $entry): bool => 'yorvoid.attribute.set' === $entry->action));
    }
}
