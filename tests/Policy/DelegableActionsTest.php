<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\Actions;
use YorvoID\Core\Authorization\Delegable;
use YorvoID\Core\Authorization\Request;
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

        self::assertSame(Delegable::Everyone, $actions->ability('acme.pages.edit'), 'Not strict: anyone.');
        self::assertSame(Delegable::NotToAgents, $actions->ability('acme.pages.delete'), 'Strict: not to agents.');
        self::assertSame(Delegable::Nobody, $actions->ability('acme.billing.pay'), 'As it declares.');
        self::assertSame(Delegable::Everyone, $actions->operation('acme.page.publish'), 'An operation, as its ability.');
        self::assertSame(Delegable::Nobody, $actions->operation('acme.page.archive'), 'As it declares, though its ability is delegable.');
        self::assertSame(Delegable::NotToAgents, $actions->operation('acme.page.purge'), 'No ability: not to agents.');
        self::assertSame(Delegable::NotToAgents, $actions->operation('yorvoid.principal.create'), 'YorvoID\'s own: not to agents.');
        self::assertNull($actions->ability('acme.page.publish'), 'An operation isn\'t an ability.');
        self::assertNull($actions->operation('acme.pages.edit'), 'Nor an ability an operation.');
        self::assertSame(['acme.billing.pay', 'acme.pages.delete', 'acme.pages.edit'], $actions->abilities());
        self::assertSame(['acme.page.archive', 'acme.page.publish', 'acme.page.purge', 'yorvoid.principal.create', 'yorvoid.principal.delete'], $actions->operations());
    }

    /**
     * An operation named like a strict ability, declared delegable to anyone, leaves the ability as it
     * is: an ability request asks the ability's rule, an operation request the operation's.
     */
    public function testAnOperationNamedLikeAnAbilityChangesNothingOfIt(): void
    {
        $actions = new Actions(new OperationCatalogue([DeletePagesNow::class]), [new AbilityActions(PolicyCatalogue::of([new Declarations()]))]);
        $realm = new Realm('acme');
        $actor = new \YorvoID\Core\Operation\Operator();
        $origin = new \YorvoID\Core\Event\Origin(\YorvoID\Core\Event\OriginKind::Console);
        $now = new \DateTimeImmutable();

        self::assertSame(Delegable::NotToAgents, $actions->ability('acme.pages.delete'));
        self::assertSame(Delegable::Everyone, $actions->operation('acme.pages.delete'));
        self::assertSame(Delegable::NotToAgents, $actions->forRequest(Request::forAbility('acme.pages.delete', $realm, null, $actor, $origin, $now)), 'isGranted() of the ability.');
        self::assertSame(Delegable::Everyone, $actions->forRequest(Request::forOperation(new OperationCatalogue([DeletePagesNow::class])->get('acme.pages.delete'), new DeletePagesNow($realm), $actor, $origin, $now)), 'The operation, as it declares.');
    }

    public function testTheAbilitiesOfEveryCatalogueAreListed(): void
    {
        $actions = new Actions(new OperationCatalogue([]), [self::listing(['acme.pages.edit' => Delegable::Everyone]), self::listing(['acme.billing.pay' => Delegable::Nobody])]);

        self::assertSame(['acme.billing.pay', 'acme.pages.edit'], $actions->abilities());
    }

    public function testYorvoIDsOwnOperationIsNeverDelegatedToAnAgentWhateverItsAbility(): void
    {
        $actions = new Actions(new OperationCatalogue([NamedLikeYorvoIDs::class]), [new AbilityActions(PolicyCatalogue::of([new Declarations()]))]);

        self::assertSame(Delegable::Everyone, $actions->ability('acme.pages.edit'));
        self::assertSame(Delegable::NotToAgents, $actions->operation('yorvoid.page.publish'));
    }

    public function testTwoCataloguesThatDisagreeOnAnAbilityAreRefused(): void
    {
        $nobody = new class implements \YorvoID\Core\Authorization\ListsActions {
            public function actions(): array
            {
                return ['acme.pages.edit' => Delegable::Nobody];
            }
        };
        $agreeing = new class implements \YorvoID\Core\Authorization\ListsActions {
            public function actions(): array
            {
                return ['acme.pages.edit' => Delegable::Everyone];
            }
        };
        $abilities = new AbilityActions(PolicyCatalogue::of([new Declarations()]));

        self::assertSame(Delegable::Everyone, new Actions(new OperationCatalogue([]), [$abilities, $agreeing])->ability('acme.pages.edit'), 'The same rule twice is one.');

        $this->expectException(\YorvoID\Core\Exception\IntegratorError::class);
        $this->expectExceptionMessage('Two catalogues list the ability "acme.pages.edit", one delegable to everyone, the other to nobody.');
        new Actions(new OperationCatalogue([]), [$abilities, $nobody]);
    }

    public function testDelegableSaysWhoToByKind(): void
    {
        foreach (PrincipalKind::cases() as $kind) {
            self::assertTrue(Delegable::Everyone->to($kind));
            self::assertSame(PrincipalKind::Agent !== $kind, Delegable::NotToAgents->to($kind), $kind->value);
            self::assertFalse(Delegable::Nobody->to($kind));
        }
    }

    /**
     * @param array<string, Delegable> $actions
     */
    private static function listing(array $actions): \YorvoID\Core\Authorization\ListsActions
    {
        return new class($actions) implements \YorvoID\Core\Authorization\ListsActions {
            /**
             * @param array<string, Delegable> $listed
             */
            public function __construct(private readonly array $listed)
            {
            }

            public function actions(): array
            {
                return $this->listed;
            }
        };
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
#[Operation('acme.pages.delete', 'Delete pages now', AuthorizedBy::Operator, delegable: Delegable::Everyone)]
final readonly class DeletePagesNow implements Command
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

/**
 * @implements Command<null>
 */
#[Operation('yorvoid.page.publish', 'Publish a page, named as YorvoID\'s', AuthorizedBy::Ability, ability: 'acme.pages.edit')]
final readonly class NamedLikeYorvoIDs implements Command
{
    public function __construct(public Realm $realm)
    {
    }
}
