<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Someone seeing the application as another sees it (AUZ-023), e.g. an administrator checking what a
 * member may see. It acts as the target, so its principal and realm are the target's, and every decision
 * is made for both and intersected: it permits only what both may, so it never exceeds the viewer. It's
 * read-only: no operation runs for it.
 *
 * Start one with the "yorvoid.view_as.begin" operation, which checks the viewer may and says what the
 * target may that the viewer may not. Nothing is stored: it lasts as long as the host holds it.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's view-as uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class ViewingAs implements Actor
{
    /** The forbid that refuses every operation to it */
    public const string READ_ONLY = 'yorvoid.view_as.read_only';

    public ?PrincipalId $principalId;

    public ?Realm $realm;

    /**
     * @param Actor $viewer Who looks
     * @param Actor $target Whose view it is
     *
     * @throws IntegratorError When either is a view-as itself
     */
    public function __construct(
        public Actor $viewer,
        public Actor $target,
    ) {
        if ($viewer instanceof self || $target instanceof self) {
            throw new IntegratorError('A view-as was given another view-as.', 'View as the target from the viewer\'s own actor: a view-as of a view-as isn\'t one.', 'concepts/authorization.md#view-as');
        }

        $this->principalId = $target->principalId;
        $this->realm = $target->realm;
    }
}
