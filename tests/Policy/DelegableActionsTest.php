<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\Actions;
use YorvoID\Core\Authorization\Delegable;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Realm;
use YorvoID\Policy\Ability;
use YorvoID\Policy\AbilityActions;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyDeclarations;

/**
 * To whom each action may be delegated (06 §5.4): an ability as it declares, or by its strictness; an
 * operation as it declares, or as its ability, and never to an agent without one or for YorvoID's own.
 * An operation is its own action: a delegable ability doesn't make its operation delegable.
 */
final class DelegableActionsTest extends TestCase
{
    public function testEachActionSaysToWhomItMayBeDelegated(): void
    {
        $actions = new Actions(new OperationCatalogue([CreatePrincipal::class, \YorvoID\Core\Principal\DeletePrincipal::class, PublishPage::class, ArchivePage::class, PurgePages::class]), [new AbilityActions(PolicyCatalogue::of([new Declarations()]))]);

        self::assertSame(Delegable::Everyone, $actions->delegable('acme.pages.edit'), 'Not strict: anyone.');
        self::assertSame(Delegable::NotToAgents, $actions->delegable('acme.pages.delete'), 'Strict: not to agents.');
        self::assertSame(Delegable::Nobody, $actions->delegable('acme.billing.pay'), 'As it declares.');
        self::assertSame(Delegable::Everyone, $actions->delegable('acme.page.publish'), 'An operation, as its ability.');
        self::assertSame(Delegable::Nobody, $actions->delegable('acme.page.archive'), 'As it declares, though its ability is delegable.');
        self::assertSame(Delegable::NotToAgents, $actions->delegable('acme.page.purge'), 'No ability: not to agents.');
        self::assertSame(Delegable::NotToAgents, $actions->delegable('yorvoid.principal.create'), 'YorvoID\'s own: not to agents.');
        self::assertNull($actions->delegable('acme.unknown'));
        self::assertSame(['acme.billing.pay', 'acme.page.archive', 'acme.page.publish', 'acme.page.purge', 'acme.pages.delete', 'acme.pages.edit', 'yorvoid.principal.create', 'yorvoid.principal.delete'], $actions->names());
    }

    public function testDelegableSaysWhoToByKind(): void
    {
        foreach (PrincipalKind::cases() as $kind) {
            self::assertTrue(Delegable::Everyone->to($kind));
            self::assertSame(PrincipalKind::Agent !== $kind, Delegable::NotToAgents->to($kind), $kind->value);
            self::assertFalse(Delegable::Nobody->to($kind));
        }
    }
}

final readonly class Declarations implements PolicyDeclarations
{
    public function abilities(): iterable
    {
        return [
            new Ability('acme.pages.edit', 'Edit pages', 'acme'),
            new Ability('acme.pages.delete', 'Delete pages', 'acme', strict: true),
            new Ability('acme.billing.pay', 'Pay invoices', 'acme', delegable: Delegable::Nobody),
        ];
    }

    public function roles(): iterable
    {
        return [];
    }
}

/**
 * @implements Command<null>
 */
#[Operation('acme.page.publish', 'Publish a page', AuthorizedBy::Ability, ability: 'acme.pages.edit')]
final readonly class PublishPage implements Command
{
    public function __construct(public Realm $realm)
    {
    }
}

/**
 * @implements Command<null>
 */
#[Operation('acme.page.archive', 'Archive a page', AuthorizedBy::Ability, ability: 'acme.pages.edit', delegable: Delegable::Nobody)]
final readonly class ArchivePage implements Command
{
    public function __construct(public Realm $realm)
    {
    }
}

/**
 * @implements Command<null>
 */
#[Operation('acme.page.purge', 'Purge pages', AuthorizedBy::Operator)]
final readonly class PurgePages implements Command
{
    public function __construct(public Realm $realm)
    {
    }
}
