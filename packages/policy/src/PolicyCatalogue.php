<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Exception\IntegratorError;

/**
 * Every declared ability and role, checked when YorvoID is built (PLT-004): names are unique, and default
 * roles are declared. The role permit checks that every operation's ability is declared.
 *
 * @api
 */
final readonly class PolicyCatalogue
{
    /**
     * @param array<string, Ability> $abilities By name, in the order declared
     * @param array<string, Role>    $roles     By name, in the order declared
     */
    private function __construct(
        private array $abilities,
        private array $roles,
    ) {
    }

    /**
     * @param iterable<PolicyDeclarations> $declarations
     *
     * @throws IntegratorError
     */
    public static function of(iterable $declarations): self
    {
        $abilities = [];
        $roles = [];
        $all = [];

        foreach ($declarations as $declaration) {
            $all[] = $declaration;
        }

        foreach ($all as $declaration) {
            foreach ($declaration->roles() as $role) {
                if (!$role->declared()) {
                    throw new IntegratorError(\sprintf('The declared role "%s" has no owner.', $role->name), 'Name who declares it, e.g. new Role(\'manager\', \'Manager\', \'acme\'): roles without one are created in a realm.', 'concepts/authorization.md#roles');
                }

                if (isset($roles[$role->name])) {
                    throw new IntegratorError(\sprintf('The role "%s" is declared twice.', $role->name), 'Declare each role once; give an extension\'s roles names of their own.', 'concepts/authorization.md#roles');
                }

                $roles[$role->name] = $role;
            }
        }

        foreach ($all as $declaration) {
            foreach ($declaration->abilities() as $ability) {
                if (isset($abilities[$ability->name])) {
                    throw new IntegratorError(\sprintf('The ability "%s" is declared twice.', $ability->name), 'Declare each ability once, in its owner\'s namespace.', 'concepts/authorization.md#abilities');
                }

                foreach ($ability->defaultRoles as $role) {
                    if (Role::EVERYONE !== $role && !isset($roles[$role])) {
                        throw new IntegratorError(\sprintf('The ability "%s" is given by default to "%s", which isn\'t a declared role.', $ability->name, $role), 'Declare the role, or give the ability to "everyone" or to declared roles only.', 'concepts/authorization.md#abilities');
                    }
                }

                $abilities[$ability->name] = $ability;
            }
        }

        return new self($abilities, $roles);
    }

    /**
     * @return array<string, Ability> By name, in the order declared
     */
    public function abilities(): array
    {
        return $this->abilities;
    }

    public function ability(string $name): ?Ability
    {
        return $this->abilities[$name] ?? null;
    }

    public function has(string $ability): bool
    {
        return isset($this->abilities[$ability]);
    }

    /**
     * @return array<string, Role> By name, in the order declared
     */
    public function roles(): array
    {
        return $this->roles;
    }

    public function role(string $name): ?Role
    {
        return $this->roles[$name] ?? null;
    }
}
