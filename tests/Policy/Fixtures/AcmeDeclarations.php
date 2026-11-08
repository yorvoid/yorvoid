<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy\Fixtures;

use YorvoID\Policy\Ability;
use YorvoID\Policy\PolicyDeclarations;
use YorvoID\Policy\Role;

/**
 * A site's roles and abilities, as Quire declares its own: everyone views, editors publish, admins are
 * a super role, and purging is strict.
 */
final readonly class AcmeDeclarations implements PolicyDeclarations
{
    public function abilities(): iterable
    {
        yield new Ability('acme.pages.view', 'View pages', 'acme', ['everyone'], 'pages');
        yield new Ability('acme.pages.view_drafts', 'View drafts', 'acme', ['editor'], 'pages');
        yield new Ability('acme.pages.publish', 'Publish pages', 'acme', ['editor'], 'pages');
        yield new Ability('acme.pages.delete', 'Delete pages', 'acme', ['editor'], 'pages');
        yield new Ability('acme.pages.purge', 'Purge pages for good', 'acme', [], 'pages', strict: true);
        yield new Ability('acme.sites.update', 'Update a site', 'acme', ['manager'], 'sites');
    }

    public function roles(): iterable
    {
        yield new Role('member', 'Member', 'acme');
        yield new Role('editor', 'Editor', 'acme');
        yield new Role('manager', 'Site manager', 'acme');
        yield new Role('admin', 'Admin', 'acme', super: true);
    }
}
