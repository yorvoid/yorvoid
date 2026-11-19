<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Operation\Command;

/**
 * A command that changes who may do what: a role, an ability, a group, a relation, an attribute, a
 * delegation. Its effect, what it gives and takes, must be declared by a DeclaresEffects (D-51), and
 * the forbids that weigh changes are Indeterminate for one whose effect isn't declared: a change of
 * authority is never taken for one that gives nothing.
 *
 * @template T
 *
 * @extends Command<T>
 *
 * @api
 *
 * @experimental Outside the 1.0 promise while delegation (M3) shapes it: it may change in a minor
 *               release ([compatibility](docs/compatibility.md)).
 */
interface ChangesAuthority extends Command
{
}
