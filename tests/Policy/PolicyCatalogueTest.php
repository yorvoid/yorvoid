<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Policy\Ability;
use YorvoID\Policy\InvalidRole;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyDeclarations;
use YorvoID\Policy\Role;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Policy\Fixtures\AcmeDeclarations;

final class PolicyCatalogueTest extends TestCase
{
    public function testCollectsTheDeclaredAbilitiesAndRolesInOrder(): void
    {
        $catalogue = PolicyCatalogue::of([new YorvoIDDeclarations(), new AcmeDeclarations()]);

        self::assertSame(['yorvoid.principals.manage', 'yorvoid.principals.recover', 'yorvoid.sessions.manage', 'yorvoid.roles.manage', 'yorvoid.permissions.manage', 'acme.pages.view', 'acme.pages.view_drafts', 'acme.pages.publish', 'acme.pages.delete', 'acme.pages.purge', 'acme.sites.update'], array_keys($catalogue->abilities()));
        self::assertSame(['member', 'editor', 'manager', 'admin'], array_keys($catalogue->roles()));
        self::assertTrue($catalogue->has('acme.pages.view'));
        self::assertFalse($catalogue->has('acme.pages.edit'));
        self::assertNull($catalogue->ability('acme.pages.edit'));
        self::assertSame(['everyone'], $catalogue->ability('acme.pages.view')?->defaultRoles);
        self::assertTrue($catalogue->ability('yorvoid.principals.recover')?->strict, 'Handing an account over takes a grant, even for a super role.');
        self::assertTrue($catalogue->role('admin')?->super);
        self::assertNull($catalogue->role('everyone'));
    }

    public function testRefusesDeclarationsThatDontFit(): void
    {
        $cases = [
            'The role "editor" is declared twice.' => [self::declaring([], [new Role('editor', 'Editor', 'acme')]), new AcmeDeclarations()],
            'The ability "acme.pages.view" is declared twice.' => [self::declaring([new Ability('acme.pages.view', 'View', 'acme')]), new AcmeDeclarations()],
            'The ability "acme.x" is given by default to "ghost", which isn\'t a declared role.' => [self::declaring([new Ability('acme.x', 'X', 'acme', ['ghost'])])],
            'The declared role "helper" has no owner.' => [self::declaring([], [new Role('helper', 'Helper')])],
        ];

        foreach ($cases as $message => $declarations) {
            try {
                PolicyCatalogue::of($declarations);
                self::fail(\sprintf('Built despite: %s', $message));
            } catch (IntegratorError $error) {
                self::assertStringStartsWith($message, $error->getMessage());
            }
        }

        PolicyCatalogue::of([self::declaring([new Ability('acme.x', 'X', 'acme', ['everyone', 'editor'])]), new AcmeDeclarations()]);
        $this->addToAssertionCount(1);
    }

    public function testEveryOperationsAbilityMustBeDeclared(): void
    {
        $operations = new OperationCatalogue([IssueBadge::class]);

        try {
            PolicyCatalogue::of([new AcmeDeclarations()], $operations);
            self::fail('An undeclared ability was taken.');
        } catch (IntegratorError $error) {
            self::assertStringStartsWith('The operation "acme.badge.issue" needs the ability "acme.badges.issue", which isn\'t declared.', $error->getMessage());
        }

        PolicyCatalogue::of([new AcmeDeclarations(), self::declaring([new Ability('acme.badges.issue', 'Issue badges', 'acme')])], $operations);
        $this->addToAssertionCount(1);
    }

    public function testAbilitiesAndRolesAreNamedAndLabelled(): void
    {
        $ability = new Ability('acme-corp.pages.view_drafts', 'View drafts', 'acme', ['editor', 'editor', 'admin']);
        self::assertSame(['editor', 'admin'], $ability->defaultRoles);
        self::assertNull($ability->group);
        self::assertFalse($ability->strict);

        foreach (['acme', 'Acme.pages', 'acme..pages', 'acme.pages.', '.acme', 'acme.pages view'] as $name) {
            try {
                new Ability($name, 'X', 'acme');
                self::fail(\sprintf('"%s" was taken as an ability.', $name));
            } catch (IntegratorError $error) {
                self::assertStringStartsWith(\sprintf('The ability name "%s" is invalid.', $name), $error->getMessage());
            }
        }

        foreach ([['acme.x', ' ', 'acme'], ['acme.x', 'X', ' ']] as [$name, $label, $owner]) {
            try {
                new Ability($name, $label, $owner);
                self::fail('An ability without a label or an owner was taken.');
            } catch (IntegratorError $error) {
                self::assertStringStartsWith('The ability "acme.x" needs a label and an owner.', $error->getMessage());
            }
        }

        $role = new Role('site_manager', '  Site manager ');
        self::assertSame('Site manager', $role->label);
        self::assertFalse($role->declared());
        self::assertFalse($role->super);
        self::assertTrue(new Role('a-b', 'A', 'acme')->declared());
        new Role(str_repeat('a', 32), str_repeat('é', 50));

        foreach (['Editor' => 'is invalid', '1st' => 'is invalid', 'a_' => 'is invalid', str_repeat('a', 33) => 'is invalid', 'everyone' => 'is reserved'] as $name => $why) {
            try {
                new Role($name, 'X');
                self::fail(\sprintf('"%s" was taken as a role.', $name));
            } catch (InvalidRole $error) {
                self::assertSame($name, $error->role);
                self::assertStringContainsString($why, $error->getMessage());
            }
        }

        foreach (['', '   ', str_repeat('é', 51)] as $label) {
            try {
                new Role('helper', $label);
                self::fail('The label was taken.');
            } catch (InvalidRole $error) {
                self::assertSame('The label of the role "helper" must be 1 to 50 characters.', $error->getMessage());
            }
        }
    }

    /**
     * @param list<Ability> $abilities
     * @param list<Role>    $roles
     */
    private static function declaring(array $abilities, array $roles = []): PolicyDeclarations
    {
        return new readonly class($abilities, $roles) implements PolicyDeclarations {
            /**
             * @param list<Ability> $abilities
             * @param list<Role>    $roles
             */
            public function __construct(
                private array $abilities,
                private array $roles,
            ) {
            }

            public function abilities(): iterable
            {
                return $this->abilities;
            }

            public function roles(): iterable
            {
                return $this->roles;
            }
        };
    }
}
