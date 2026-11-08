<?php

declare(strict_types=1);

namespace YorvoID\Tests\Relations\Fixtures;

use YorvoID\Relations\RelationDeclarations;
use YorvoID\Relations\RelationDefinition;
use YorvoID\Relations\ResourceType;

/**
 * Pages within sites within servers: an editor is a viewer, and a parent's viewers view its children.
 */
final readonly class AcmeRelations implements RelationDeclarations
{
    public function types(): iterable
    {
        yield new ResourceType('server', [
            new RelationDefinition('admin', grants: ['acme.sites.update']),
            new RelationDefinition('viewer', impliedBy: ['admin']),
        ]);
        yield new ResourceType('site', [
            new RelationDefinition('parent'),
            new RelationDefinition('viewer', fromParents: ['parent' => 'viewer']),
        ]);
        yield new ResourceType('page', [
            new RelationDefinition('parent'),
            new RelationDefinition('editor', grants: ['acme.pages.publish']),
            new RelationDefinition('viewer', impliedBy: ['editor'], fromParents: ['parent' => 'viewer'], grants: ['acme.pages.view_drafts']),
        ]);
    }
}
