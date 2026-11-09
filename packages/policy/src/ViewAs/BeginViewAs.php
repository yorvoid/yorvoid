<?php

declare(strict_types=1);

namespace YorvoID\Policy\ViewAs;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\YorvoIDDeclarations;

/**
 * Starts seeing the application as a principal of the realm sees it: the actor that runs it is the
 * viewer. It changes nothing; it's recorded, with both principals.
 *
 * ```php
 * $view = $operations->run(new BeginViewAs($realm, $member), $session, $origin);
 * if ([] !== $view->beyond) { ... refuse, or warn that the view shows more than the viewer may do ... }
 * $policy->allows($view->actor, 'acme.pages.edit', $realm, $page, $origin);
 * ```
 *
 * @implements Command<ViewAsStart>
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's view-as uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
#[Operation('yorvoid.view_as.begin', 'See the application as someone else sees it', AuthorizedBy::Ability, ability: YorvoIDDeclarations::POLICY_VIEW_AS)]
final readonly class BeginViewAs implements Command
{
    /**
     * @param PrincipalId $target Whose view it is
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $target,
    ) {
    }
}
