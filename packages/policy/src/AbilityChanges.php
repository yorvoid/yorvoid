<?php

declare(strict_types=1);

namespace YorvoID\Policy;

/**
 * A realm's changes to the declared defaults (AUZ-002): roles given an ability they don't have by
 * default, and roles that lost one they have by default. Only differences are kept: giving a role back
 * an ability it has by default leaves nothing, so a change of the defaults later still reaches every
 * role nobody changed. Lists are sorted, so equal changes are equal however they were made.
 *
 * @api
 */
final readonly class AbilityChanges
{
    /** @var array<string, non-empty-list<string>> Ability => roles given it */
    public array $grant;

    /** @var array<string, non-empty-list<string>> Ability => roles it was taken from */
    public array $revoke;

    /**
     * @param array<string, array<int, string>> $grant
     * @param array<string, array<int, string>> $revoke
     */
    public function __construct(array $grant = [], array $revoke = [])
    {
        $this->grant = self::normal($grant);
        $this->revoke = self::normal($revoke);
    }

    /**
     * Gives the role the ability, or takes it away; recorded only where it differs from the defaults.
     *
     * @param list<string> $defaults The roles that have it by default
     */
    public function change(string $ability, string $role, bool $grant, array $defaults): self
    {
        $grants = $this->grant;
        $revokes = $this->revoke;
        $default = \in_array($role, $defaults, true);

        if ($grant) {
            $revokes[$ability] = self::without($revokes[$ability] ?? [], $role);
            $grants[$ability] = $default ? self::without($grants[$ability] ?? [], $role) : [...$grants[$ability] ?? [], $role];
        } else {
            $grants[$ability] = self::without($grants[$ability] ?? [], $role);
            $revokes[$ability] = $default ? [...$revokes[$ability] ?? [], $role] : self::without($revokes[$ability] ?? [], $role);
        }

        return new self($grants, $revokes);
    }

    /**
     * Without any change for the role, e.g. when it's deleted or created again.
     */
    public function withoutRole(string $role): self
    {
        return new self(self::strip($this->grant, $role), self::strip($this->revoke, $role));
    }

    /**
     * Back to the defaults: for these abilities, or for all.
     *
     * @param list<string>|null $abilities
     */
    public function reset(?array $abilities = null): self
    {
        if (null === $abilities) {
            return new self();
        }

        $drop = array_flip($abilities);

        return new self(array_diff_key($this->grant, $drop), array_diff_key($this->revoke, $drop));
    }

    /**
     * The roles that have the ability: its defaults with what was given, without what was taken.
     *
     * @param list<string> $defaults
     *
     * @return list<string> The defaults first, in their order
     */
    public function rolesWith(string $ability, array $defaults): array
    {
        return array_values(array_unique(array_diff([...$defaults, ...$this->grant[$ability] ?? []], $this->revoke[$ability] ?? [])));
    }

    /**
     * The abilities that differ from their defaults.
     *
     * @return list<string> Sorted
     */
    public function abilities(): array
    {
        $abilities = array_keys($this->grant + $this->revoke);
        sort($abilities, \SORT_STRING);

        return $abilities;
    }

    public function isEmpty(): bool
    {
        return [] === $this->grant && [] === $this->revoke;
    }

    public function equals(self $other): bool
    {
        return $this->grant === $other->grant && $this->revoke === $other->revoke;
    }

    /**
     * @param array<string, array<int, string>> $lists
     *
     * @return array<string, array<int, string>>
     */
    private static function strip(array $lists, string $role): array
    {
        $stripped = [];

        foreach ($lists as $ability => $roles) {
            $stripped[$ability] = self::without($roles, $role);
        }

        return $stripped;
    }

    /**
     * @param array<int, string> $roles
     *
     * @return array<int, string> Normalised by the constructor
     */
    private static function without(array $roles, string $role): array
    {
        return array_filter($roles, static fn (string $other): bool => $other !== $role);
    }

    /**
     * @param array<string, array<int, string>> $lists
     *
     * @return array<string, non-empty-list<string>>
     */
    private static function normal(array $lists): array
    {
        $normal = [];

        foreach ($lists as $ability => $roles) {
            $roles = array_unique($roles);
            sort($roles, \SORT_STRING);

            if ([] !== $roles) {
                $normal[$ability] = $roles;
            }
        }

        ksort($normal, \SORT_STRING);

        return $normal;
    }
}
