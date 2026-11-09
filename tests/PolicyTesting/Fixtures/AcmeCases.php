<?php

declare(strict_types=1);

namespace YorvoID\Tests\PolicyTesting\Fixtures;

use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\PolicyTesting\PolicyCase;
use YorvoID\PolicyTesting\PolicyCases;
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
        yield PolicyCase::that('Other drafts are editors\'')->given(roles: ['member'])->asks('acme.pages.view_drafts', $page)->denies();
        yield PolicyCase::that('A role created in the realm has what it\'s granted')->before(
            static fn (Realm $realm): Command => new CreateRole($realm, 'reviewer', 'Reviewer'),
            static fn (Realm $realm): Command => new GrantAbilities($realm, 'reviewer', ['acme.pages.view_drafts']),
        )->given(roles: ['reviewer'])->asks('acme.pages.view_drafts', $page)->permits();
    }
}
