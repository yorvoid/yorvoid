<?php

declare(strict_types=1);

namespace YorvoID\Tests\Properties;

use Innmind\BlackBox\PHPUnit\BlackBox;
use Innmind\BlackBox\Set;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\Selector\IdIn;
use YorvoID\Core\Authorization\Selector\RelatedTo;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Operation\UnderDelegation;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Delegation\Change\IssueDelegation;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\UnassignRole;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\TestPrincipal;

/**
 * I3: whatever the root's roles and relations, the agent's own, what's delegated on which pages, and a
 * role the root loses afterwards, a request under a delegation is permitted only if the same request by
 * the root is, now.
 */
final class DelegationPropertiesTest extends TestCase
{
    use BlackBox;

    private const array ABILITIES = ['acme.pages.publish', 'acme.pages.view_drafts', 'acme.pages.delete'];

    public function testADelegatedRequestIsNeverPermittedMoreThanItsRoot(): void
    {
        self::forAll(
            Set::of(null, 'editor', 'member'),
            Set::of([], ['p-1'], ['p-1', 'p-2']),
            Set::of(null, 'editor', 'manager'),
            Set::of(['acme.pages.publish'], ['acme.pages.view_drafts', 'acme.pages.publish'], self::ABILITIES),
            Set::of('all', 'p-1', 'related'),
            Set::of(...self::ABILITIES),
            Set::of('p-1', 'p-2', 'p-3'),
            Set::of(true, false),
        )->then(function (?string $rootRole, array $editorOf, ?string $agentRole, array $actions, string $pages, string $ability, string $page, bool $loses): void {
            $world = new PolicyWorld(delegations: true);
            $root = null === $rootRole ? $world->principal() : $world->principal($rootRole);

            $actions = array_values(array_filter($actions, \is_string(...)));

            foreach (array_filter($editorOf, \is_string(...)) as $id) {
                $world->asOperator(new AddRelation($world->realm, new ResourceRef('page', $id), 'editor', Subject::principal($root->principalId ?? throw new \LogicException())));
            }

            $agent = new TestPrincipal($world->realm, $world->asOperator(new CreatePrincipal($world->realm, PrincipalKind::Agent, owner: $root->principalId))->id);

            if (null !== $agentRole) {
                $world->asOperator(new AssignRole($world->realm, $agent->principalId ?? throw new \LogicException(), $agentRole));
            }

            $selector = match ($pages) {
                'all' => new Selector('page'),
                'p-1' => new Selector('page', [new IdIn(['p-1'])]),
                default => new Selector('page', [new RelatedTo('editor', new ResourceRef('principal', (string) $root->principalId))]),
            };

            try {
                $delegation = $world->as($root, new IssueDelegation($world->realm, $agent->principalId ?? throw new \LogicException(), $actions, $selector, new \DateTimeImmutable('2026-11-14 20:00:00')));
            } catch (NotPermitted) {
                // Not the root's to give: refused, nothing to use.
                $this->addToAssertionCount(1);

                return;
            }

            $decide = static fn (\YorvoID\Core\Operation\Actor $actor): Verdict => $world->policy->decide($actor, $ability, $world->realm, new ResourceRef('page', $page), new Origin(OriginKind::Web))->verdict;
            $under = new UnderDelegation($agent, $delegation->id);

            foreach ($loses && null !== $rootRole ? [false, true] : [false] as $lost) {
                if ($lost) {
                    $world->asOperator(new UnassignRole($world->realm, $root->principalId ?? throw new \LogicException(), $rootRole ?? throw new \LogicException()));
                }

                if (Verdict::Permit === $decide($under)) {
                    self::assertSame(Verdict::Permit, $decide($root), 'Permitted under the delegation, not to its root.');
                    self::assertContains($ability, $actions, 'Permitted what wasn\'t delegated.');
                } else {
                    $this->addToAssertionCount(1);
                }
            }
        });
    }
}
