<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\AttributeMacs;
use YorvoID\Core\Authorization\Confirmation;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\MacKey;
use YorvoID\Core\Key\SharedKeyRings;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Realm;
use YorvoID\Policy\Attribute\AttributeResolver;
use YorvoID\Policy\Attribute\Attributes;
use YorvoID\Policy\Attribute\ResolvesPublicValues;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;

/**
 * The attributes a decision read, in its read set (M-9): a boolean and a value declared public as they
 * are, any other value, integers too, as a MAC under the realm's key, and withheld without one. A name is
 * read once in a decision, and each request of a batch reads its own.
 */
final class ResolvedAttributesTest extends TestCase
{
    public function testEachValueIsRecordedAsItsKindAllows(): void
    {
        $macs = new AttributeMacs(new SharedKeyRings(new KeyRing([SigningKey::generate('s-1')], [MacKey::generate('m-1')])));
        $resolver = new PageFacts();
        $world = new PolicyWorld(resolvers: [$resolver], rulesOf: static fn (Attributes $attributes): array => [new ReadsFacts($attributes)], macs: $macs);
        $decision = $this->decide($world, 'p-1');
        $read = $decision->read->attributes ?? self::fail('Not recorded.');

        self::assertSame(['acme.department', 'acme.flagged', 'acme.level', 'acme.status'], array_keys($read), 'Sorted by name.');
        self::assertSame('v:true', $read['acme.flagged'], 'A boolean hides nothing.');
        self::assertSame('v:"draft"', $read['acme.status'], 'Declared public.');
        self::assertMatchesRegularExpression('/^m:m-1:[A-Za-z0-9_-]{43}$/D', $read['acme.department']);
        self::assertMatchesRegularExpression('/^m:m-1:[A-Za-z0-9_-]{43}$/D', $read['acme.level'], 'An integer is MACed unless declared public.');
        self::assertSame(1, $resolver->asked['acme.department'], 'Read twice by the rule, asked once: one value a decision.');

        $realm = new Realm('acme');
        self::assertSame(Confirmation::Matches, $macs->confirm($realm, 'acme.department', $read['acme.department'], 'finance'));
        self::assertSame(Confirmation::Differs, $macs->confirm($realm, 'acme.department', $read['acme.department'], 'sales'));
        self::assertSame(Confirmation::Matches, $macs->confirm($realm, 'acme.level', $read['acme.level'], 7));
        self::assertSame(Confirmation::Differs, $macs->confirm($realm, 'acme.level', $read['acme.level'], '7'), 'An integer isn\'t its text.');
        self::assertSame(Confirmation::Differs, $macs->confirm(new Realm('beta'), 'acme.department', $read['acme.department'], 'finance'), 'Nor in another realm.');
        self::assertSame(Confirmation::Differs, $macs->confirm($realm, 'acme.team', $read['acme.department'], 'finance'), 'Nor as another attribute.');
        self::assertSame(Confirmation::NotRecorded, $macs->confirm($realm, 'acme.team', null, 'x'));
        self::assertSame(Confirmation::Matches, $macs->confirm($realm, 'acme.status', $read['acme.status'], 'draft'));
    }

    public function testWithoutAMacKeyWhatIsntPublicIsWithheld(): void
    {
        $world = new PolicyWorld(resolvers: [new PageFacts()], rulesOf: static fn (Attributes $attributes): array => [new ReadsFacts($attributes)]);
        $read = $this->decide($world, 'p-1')->read->attributes ?? self::fail('Not recorded.');

        self::assertSame(['acme.department' => 'w:', 'acme.flagged' => 'v:true', 'acme.level' => 'w:', 'acme.status' => 'v:"draft"'], $read);
        self::assertSame(Confirmation::Withheld, new AttributeMacs()->confirm(new Realm('acme'), 'acme.department', 'w:', 'finance'));
    }

    /**
     * K5: in a batch, each page is resolved and recorded for itself.
     */
    public function testEachRequestOfABatchReadsItsOwn(): void
    {
        $macs = new AttributeMacs(new SharedKeyRings(new KeyRing([SigningKey::generate('s-1')], [MacKey::generate('m-1')])));
        $world = new PolicyWorld(resolvers: [new PageFacts()], rulesOf: static fn (Attributes $attributes): array => [new ReadsFacts($attributes)], macs: $macs);
        $member = $world->principal('member');
        $pages = [new ResourceRef('page', 'p-1'), new ResourceRef('page', 'p-2')];
        $decisions = $world->policy->decideMany($member, 'acme.pages.publish', $world->realm, $pages, new Origin(OriginKind::Web));
        $realm = new Realm('acme');

        foreach (['p-1' => 'finance', 'p-2' => 'legal'] as $id => $department) {
            $recorded = $decisions->for(new ResourceRef('page', $id))->read->attributes['acme.department'] ?? null;
            self::assertSame(Confirmation::Matches, $macs->confirm($realm, 'acme.department', $recorded, $department), $id);
        }
    }

    private function decide(PolicyWorld $world, string $page): Decision
    {
        // A member can't publish: refused, so its read set is checked, and its attributes recorded.
        return $world->policy->decide($world->principal('member'), 'acme.pages.publish', $world->realm, new ResourceRef('page', $page), new Origin(OriginKind::Web));
    }
}

/**
 * A page's facts: its department, level, a flag and a status, which alone is public.
 */
final class PageFacts implements AttributeResolver, ResolvesPublicValues
{
    /** @var array<string, int> */
    public array $asked = [];

    public function resolves(string $name): bool
    {
        return \in_array($name, ['acme.department', 'acme.level', 'acme.flagged', 'acme.status'], true);
    }

    public function resolve(Request $request, string $name): string|int|bool
    {
        $this->asked[$name] = ($this->asked[$name] ?? 0) + 1;
        $page = $request->resource->id ?? '';

        return match ($name) {
            'acme.department' => 'p-2' === $page ? 'legal' : 'finance',
            'acme.level' => 7,
            'acme.flagged' => true,
            default => 'draft',
        };
    }

    public function publicValue(string $name): bool
    {
        return 'acme.status' === $name;
    }
}

/**
 * Reads every fact, the department twice, and decides nothing.
 */
final readonly class ReadsFacts implements Rule
{
    public function __construct(
        private Attributes $attributes,
    ) {
    }

    public function name(): string
    {
        return 'acme.reads_facts';
    }

    public function matches(Request $request): bool
    {
        return 'acme.pages.publish' === $request->ability;
    }

    public function decide(Request $request): RuleAnswer
    {
        foreach (['acme.department', 'acme.level', 'acme.flagged', 'acme.status', 'acme.department'] as $name) {
            $this->attributes->resolve($request, $name);
        }

        return RuleAnswer::abstain();
    }
}
