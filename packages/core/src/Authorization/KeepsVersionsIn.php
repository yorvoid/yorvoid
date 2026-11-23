<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * A version source, or a store, whose version is kept in a VersionStore: the evaluator reads every
 * source of one VersionStore in one statement (D-44). A source without one is read on its own.
 *
 * @api
 */
interface KeepsVersionsIn
{
    /**
     * The VersionStore its version is kept in, if any.
     */
    public function versionStore(): ?VersionStore;
}
