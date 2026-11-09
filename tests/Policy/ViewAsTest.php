<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\NotPermitted;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\UnassignRole;
use YorvoID\Policy\ViewAs\BeginViewAs;
use YorvoID\Policy\ViewAs\Beyond;
use YorvoID\Policy\ViewAs\CannotViewAs;
use YorvoID\Policy\ViewAs\ViewAsStart;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;

/**
 * View-as (AUZ-023, G6): beginning it takes "yorvoid.policy.view_as" and says what's beyond the viewer;
 * the view never exceeds the viewer, at any decision, and changes nothing.
 */
final class ViewAsTest extends TestCase
{
    public function testBeginningItTakesTheAbility(): void
    {
        $world = new PolicyWorld();
        $ada = $world->principal('member');
        $bob = $world->principal('manager');

        try {
            $this->begin($world, $bob, $ada);
            self::fail('A view-as began without the ability.');
        } catch (NotPermitted $refused) {
            self::assertSame('yorvoid.view_as.begin', $refused->operation);
        }

        $world->asOperator(new GrantAbilities($world->realm, 'manager', [YorvoIDDeclarations::POLICY_VIEW_AS]));
        $view = $this->begin($world, $bob, $ada);

        self::assertSame($bob, $view->actor->viewer);
        self::assertTrue(self::id($ada)->equals($view->actor->principalId ?? throw new \LogicException()));
        self::assertSame([], $view->beyond, 'A manager has all a member has.');
        self::assertTrue($view->complete());

        $entry = self::logged($world)[0];
        self::assertTrue(self::id($bob)->equals($entry->actor ?? throw new \LogicException()), 'Recorded with the viewer as the actor…');
        self::assertSame(['principal', self::id($ada)->uuid->toRfc4122()], [$entry->subjectType, $entry->subjectId], '…and the target as the subject.');
        self::assertSame([], $entry->details['beyond'] ?? null);
    }

    public function testSaysWhatIsBeyondTheViewer(): void
    {
        $world = new PolicyWorld();
        $world->asOperator(new GrantAbilities($world->realm, 'manager', [YorvoIDDeclarations::POLICY_VIEW_AS]));
        $world->asOperator(new GrantAbilities($world->realm, 'editor', ['acme.pages.purge']));
        $bob = $world->principal('manager');
        $eve = $world->principal('editor');
        $page = new ResourceRef('page', '9');
        $cy = $world->principal();
        $world->asOperator(new AssignRole($world->realm, self::id($cy), 'editor', $page));
        $world->asOperator(new AssignRole($world->realm, self::id($cy), 'manager', new ResourceRef('site', '2')));
        $other = new ResourceRef('page', '10');
        $world->asOperator(new AssignRole($world->realm, self::id($cy), 'editor', $other));

        $view = $this->begin($world, $bob, $eve);
        self::assertEquals([new Beyond('acme.pages.view_drafts'), new Beyond('acme.pages.publish'), new Beyond('acme.pages.delete'), new Beyond('acme.pages.purge')], $view->beyond, 'An editor\'s abilities, in the whole realm.');
        self::assertFalse($view->complete());
        self::assertSame(['acme.pages.view_drafts', 'acme.pages.publish', 'acme.pages.delete', 'acme.pages.purge'], self::logged($world)[0]->details['beyond'] ?? null);

        $scoped = $this->begin($world, $bob, $cy);
        $editing = static fn (ResourceRef $on): array => [new Beyond('acme.pages.view_drafts', $on), new Beyond('acme.pages.publish', $on), new Beyond('acme.pages.delete', $on), new Beyond('acme.pages.purge', $on)];
        self::assertEqualsCanonicalizing([...$editing($page), ...$editing($other)], $scoped->beyond, 'On each page they edit; the site they manage is the viewer\'s too.');
        self::assertContains('acme.pages.view_drafts on page:9', (array) (self::logged($world)[1]->details['beyond'] ?? []));

        $world->asOperator(new AssignRole($world->realm, self::id($bob), 'editor', $page));
        self::assertEquals($editing($other), $this->begin($world, $bob, $cy)->beyond, 'The viewer edits that page too.');
        $world->asOperator(new AssignRole($world->realm, self::id($eve), 'editor', new ResourceRef('page', '3')));
        self::assertEquals([new Beyond('acme.pages.view_drafts'), new Beyond('acme.pages.publish'), new Beyond('acme.pages.delete'), new Beyond('acme.pages.purge')], $this->begin($world, $bob, $eve)->beyond, 'Editing a page isn\'t editing the realm; and what\'s beyond in the whole realm isn\'t listed again for a page.');

        $admin = $world->principal('admin');
        $world->asOperator(new GrantAbilities($world->realm, 'admin', [YorvoIDDeclarations::POLICY_VIEW_AS]));
        self::assertEquals([new Beyond('acme.pages.purge')], $this->begin($world, $admin, $eve)->beyond, 'A super role has all but what\'s strict.');
    }

    public function testTheViewNeverExceedsTheViewer(): void
    {
        $world = new PolicyWorld();
        $world->asOperator(new GrantAbilities($world->realm, 'manager', [YorvoIDDeclarations::POLICY_VIEW_AS]));
        $page = new ResourceRef('page', '9');
        $viewers = [$world->principal('manager'), $world->principal('manager', 'editor'), $world->principal('admin')];
        $targets = [$world->principal('member'), $world->principal('editor'), $world->principal('manager'), $world->principal()];
        $world->asOperator(new AssignRole($world->realm, self::id($targets[3]), 'editor', $page));
        $world->asOperator(new GrantAbilities($world->realm, 'admin', [YorvoIDDeclarations::POLICY_VIEW_AS]));

        foreach ($viewers as $viewer) {
            foreach ($targets as $target) {
                $view = $this->begin($world, $viewer, $target)->actor;

                foreach (['acme.pages.view', 'acme.pages.view_drafts', 'acme.pages.publish', 'acme.pages.delete', 'acme.pages.purge', 'acme.sites.update'] as $ability) {
                    foreach ([null, $page, new ResourceRef('page', '1')] as $resource) {
                        $case = \sprintf('%s on %s', $ability, $resource ?? 'the realm');
                        self::assertSame($world->allows($viewer, $ability, $resource) && $world->allows($target, $ability, $resource), $world->allows($view, $ability, $resource), $case);
                    }
                }
            }
        }
    }

    public function testEachDecisionReadsThePolicyAsItIsNow(): void
    {
        $world = new PolicyWorld();
        $world->asOperator(new GrantAbilities($world->realm, 'manager', [YorvoIDDeclarations::POLICY_VIEW_AS]));
        $bob = $world->principal('manager', 'editor');
        $eve = $world->principal('editor');
        $view = $this->begin($world, $bob, $eve)->actor;
        self::assertTrue($world->allows($view, 'acme.pages.publish'));

        $world->asOperator(new UnassignRole($world->realm, self::id($bob), 'editor'));
        self::assertFalse($world->allows($view, 'acme.pages.publish'), 'The viewer lost it during the view.');
        $refused = $world->decide($view, 'acme.pages.publish');
        self::assertSame('Viewing as someone else, the viewer may not: None of their roles has "acme.pages.publish".', $refused->reason);
        self::assertSame($refused->verdict, $refused->viewer?->verdict, 'The viewer\'s side decided.');

        $world->asOperator(new SuspendPrincipal($world->realm, self::id($eve)));
        self::assertFalse($world->allows($view, 'acme.pages.view'), 'A suspended target fails the authority check.');
    }

    public function testRunsNoOperation(): void
    {
        $world = new PolicyWorld();
        $world->asOperator(new GrantAbilities($world->realm, 'admin', [YorvoIDDeclarations::POLICY_VIEW_AS]));
        $admin = $world->principal('admin');
        $ada = $world->principal('member');
        $view = $this->begin($world, $admin, $ada)->actor;

        try {
            $world->as($view, new AssignRole($world->realm, self::id($ada), 'editor'));
            self::fail('An operation ran for a view-as.');
        } catch (NotPermitted $refused) {
            self::assertSame('A forbid applies: A view as someone else is read-only: no operation runs for it.', $refused->decision->reason);
        }

        try {
            $world->as($view, new BeginViewAs($world->realm, self::id($admin)));
            self::fail('A view-as began another.');
        } catch (NotPermitted) {
        }
    }

    public function testRefusesATargetItCantBe(): void
    {
        $world = new PolicyWorld();
        $world->asOperator(new GrantAbilities($world->realm, 'admin', [YorvoIDDeclarations::POLICY_VIEW_AS]));
        $admin = $world->principal('admin');
        $ada = $world->principal('member');
        $world->asOperator(new SuspendPrincipal($world->realm, self::id($ada)));

        foreach (['The principal '.self::id($ada).' is suspended.' => $ada, 'A view as oneself is one\'s own.' => $admin] as $message => $target) {
            try {
                $this->begin($world, $admin, $target);
                self::fail($message);
            } catch (CannotViewAs $refused) {
                self::assertSame($message, $refused->getMessage());
            }
        }

        $this->expectException(UnknownPrincipal::class);
        $this->begin($world, $admin, new ActingPrincipal($world->realm, new PrincipalId(new UuidV7())));
    }

    private function begin(PolicyWorld $world, ActingPrincipal $viewer, ActingPrincipal $target): ViewAsStart
    {
        return $world->as($viewer, new BeginViewAs($world->realm, self::id($target)));
    }

    /**
     * @return list<LogEntry>
     */
    private static function logged(PolicyWorld $world): array
    {
        return array_values(array_filter(iterator_to_array($world->memory->log->entries($world->realm, 1), false), static fn (LogEntry $entry): bool => 'yorvoid.view_as.begin' === $entry->action && 'success' === $entry->outcome->value));
    }

    private static function id(ActingPrincipal $principal): PrincipalId
    {
        return $principal->principalId ?? throw new \LogicException('No principal.');
    }
}
