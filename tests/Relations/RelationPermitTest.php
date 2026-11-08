<?php

declare(strict_types=1);

namespace YorvoID\Tests\Relations;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Relations\RelationDeclarations;
use YorvoID\Relations\RelationDefinition;
use YorvoID\Relations\RelationPermit;
use YorvoID\Relations\Relations;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationTuple;
use YorvoID\Relations\ResourceType;
use YorvoID\Relations\Storage\InMemoryRelationStore;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * An ability two relations give: either one gives it, and one too deep to follow makes it unknown.
 */
final class RelationPermitTest extends TestCase
{
    public function testAnyRelationThatGivesTheAbilityCounts(): void
    {
        $schema = RelationSchema::of([new readonly class implements RelationDeclarations {
            public function types(): iterable
            {
                yield new ResourceType('doc', [
                    new RelationDefinition('parent'),
                    new RelationDefinition('owner', grants: ['acme.docs.read']),
                    new RelationDefinition('reader', fromParents: ['parent' => 'reader'], grants: ['acme.docs.read']),
                ]);
            }
        }]);
        $store = new InMemoryRelationStore();
        $realm = new Realm('acme');
        $ada = new PrincipalId(new UuidV7());
        $permit = new RelationPermit(new Relations($schema, $store, 2));
        $request = static fn (string $doc): Request => Request::forAbility('acme.docs.read', $realm, new ResourceRef('doc', $doc), new Member($realm, $ada), new Origin(OriginKind::Web), new \DateTimeImmutable());

        self::assertSame(['owner', 'reader'], $schema->granting('doc', 'acme.docs.read'));

        $store->add(new RelationTuple($realm, new ResourceRef('doc', '1'), 'reader', Subject::principal($ada)));
        self::assertSame(TruthValue::Holds, $permit->evaluate($request('1'))->value, 'The second one gives it.');

        for ($i = 0; $i < 3; ++$i) {
            $store->add(new RelationTuple($realm, new ResourceRef('doc', 'd'.$i), 'parent', new Subject(new ResourceRef('doc', 'd'.($i + 1)))));
        }

        $deep = $permit->evaluate($request('d0'));
        self::assertSame([TruthValue::Unknown, IndeterminateReason::EvaluationError, 'Their relations to doc:d0 go deeper than they\'re followed.'], [$deep->value, $deep->reason, $deep->detail]);
        self::assertSame(TruthValue::Fails, $permit->evaluate($request('2'))->value);
        self::assertSame('yorvoid.relations', $permit->name());
    }
}
