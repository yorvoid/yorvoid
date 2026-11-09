<?php

declare(strict_types=1);

namespace YorvoID\Tests\PolicyTesting\Fixtures;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\PolicyTesting\PolicyCase;
use YorvoID\PolicyTesting\PolicyCases;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Subject;
use YorvoID\Tests\Symfony\App\HelpDraft;

/**
 * The sample policy's cases: Acme's abilities and roles (AcmeDeclarations), its locked pages (a forbid)
 * and its public help drafts (a rule), as the Symfony test application has them.
 */
final readonly class AcmeCases implements PolicyCases
{
    public function cases(): iterable
    {
        $page = new ResourceRef('page', '1');

        yield PolicyCase::that('Anyone views pages')->anonymous()->asks('acme.pages.view', $page)->permits();
        yield PolicyCase::that('Someone not signed in publishes nothing')->anonymous()->asks('acme.pages.publish', $page)->denies(remedies: []);
        yield PolicyCase::that('A member doesn\'t publish')->given(roles: ['member'])->asks('acme.pages.publish', $page)->denies(remedies: [], precedence: Precedence::NoPermit);
        yield PolicyCase::that('An editor publishes')->given(roles: ['editor'])->asks('acme.pages.publish', $page)->permits();
        yield PolicyCase::that('An editor of a page publishes it')->given(on: [['editor', $page]])->asks('acme.pages.publish', $page)->permits();
        yield PolicyCase::that('An editor of a page doesn\'t publish another')->given(on: [['editor', $page]])->asks('acme.pages.publish', new ResourceRef('page', '2'))->denies();
        yield PolicyCase::that('Nobody publishes a locked page')->given(roles: ['admin'])->asks('acme.pages.publish', new ResourceRef('page', 'locked'))->denies(precedence: Precedence::ForbidHolds);
        yield PolicyCase::that('A super role doesn\'t purge: it\'s strict')->given(roles: ['admin'])->asks('acme.pages.purge', $page)->denies();
        yield PolicyCase::that('A super role purges once it\'s granted to it')->before(static fn (Realm $realm): Command => new GrantAbilities($realm, 'admin', ['acme.pages.purge']))->given(roles: ['admin'])->asks('acme.pages.purge', $page)->permits();
        yield PolicyCase::that('A site manager updates a site')->given(roles: ['manager'])->asks('acme.sites.update')->permits();
        yield PolicyCase::that('An editor doesn\'t update a site')->given(roles: ['editor'])->asks('acme.sites.update')->denies();
        yield PolicyCase::that('A group\'s editors publish')->given(groups: ['writers' => ['editor']])->asks('acme.pages.publish', $page)->permits();
        yield PolicyCase::that('A suspended editor publishes nothing')->given(roles: ['editor'], status: PrincipalStatus::Suspended)->asks('acme.pages.publish', $page)->denies(precedence: Precedence::AuthorityInvalid);
        yield PolicyCase::that('Help drafts are anyone\'s to view')->given(roles: ['member'])->asks('acme.pages.view_drafts', new ResourceRef('page', 'help'))->permits();
        yield PolicyCase::that('A help draft is, as a subject')->given(roles: ['member'])->asks('acme.pages.view_drafts', null, new HelpDraft())->permits();
        yield PolicyCase::that('Archived help drafts are no one\'s')->given(roles: ['editor'])->asks('acme.pages.view_drafts', new ResourceRef('page', 'help-archived'))->denies(precedence: Precedence::ForbidHolds);
        yield PolicyCase::that('A lost help draft isn\'t shown')->given(roles: ['editor'])->asks('acme.pages.view_drafts', new ResourceRef('page', 'help-lost'))->indeterminate([IndeterminateReason::UnknownForbid, IndeterminateReason::MissingData]);
        yield PolicyCase::that('Other drafts are editors\'')->given(roles: ['member'])->asks('acme.pages.view_drafts', $page)->denies();
        // What each role has, and doesn't.
        yield PolicyCase::that('An editor views drafts')->given(roles: ['editor'])->asks('acme.pages.view_drafts', $page)->permits();
        yield PolicyCase::that('An editor deletes')->given(roles: ['editor'])->asks('acme.pages.delete', $page)->permits();
        yield PolicyCase::that('Someone not signed in deletes nothing')->anonymous()->asks('acme.pages.delete', $page)->denies();
        yield PolicyCase::that('A member doesn\'t delete')->given(roles: ['member'])->asks('acme.pages.delete', $page)->denies();
        yield PolicyCase::that('A member doesn\'t update a site')->given(roles: ['member'])->asks('acme.sites.update')->denies();
        yield PolicyCase::that('A site manager doesn\'t view drafts')->given(roles: ['manager'])->asks('acme.pages.view_drafts', $page)->denies();
        yield PolicyCase::that('A site manager doesn\'t publish')->given(roles: ['manager'])->asks('acme.pages.publish', $page)->denies();
        yield PolicyCase::that('A site manager doesn\'t delete')->given(roles: ['manager'])->asks('acme.pages.delete', $page)->denies();
        foreach (['A member' => 'member', 'An editor' => 'editor', 'A site manager' => 'manager'] as $who => $role) {
            yield PolicyCase::that(\sprintf('%s doesn\'t purge', $who))->given(roles: [$role])->asks('acme.pages.purge', $page)->denies();
        }
        // What isn't known refuses.
        yield PolicyCase::that('A page whose lock can\'t be read isn\'t published')->given(roles: ['editor'])->asks('acme.pages.publish', new ResourceRef('page', 'unreadable'))->indeterminate([IndeterminateReason::UnknownForbid, IndeterminateReason::MissingData]);
        // A site's viewers view its pages' drafts.
        yield PolicyCase::that('A viewer of a site views the drafts of its pages')->before(static fn (Realm $realm): Command => new AddRelation($realm, $page, 'parent', new Subject(new ResourceRef('site', '1'))))->given(relations: [[new ResourceRef('site', '1'), 'viewer']])->asks('acme.pages.view_drafts', $page)->permits();
        yield PolicyCase::that('A role created in the realm has what it\'s granted')->before(
            static fn (Realm $realm): Command => new CreateRole($realm, 'reviewer', 'Reviewer'),
            static fn (Realm $realm): Command => new GrantAbilities($realm, 'reviewer', ['acme.pages.view_drafts']),
        )->given(roles: ['reviewer'])->asks('acme.pages.view_drafts', $page)->permits();
    }
}
