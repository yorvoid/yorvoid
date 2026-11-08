<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Exception\IntegratorError;

/**
 * An ability as its owner declares it (AUZ-001): what it's called, which roles have it unless the realm
 * changes that, and whether it's strict, i.e. a super role has it only when it's granted to that role.
 *
 * ```php
 * new Ability('acme.badges.issue', 'Issue badges', 'acme', defaultRoles: ['manager'], group: 'badges');
 * ```
 *
 * @api
 */
final readonly class Ability
{
    /** Dot-separated, its owner's namespace first, e.g. "acme.badges.issue" (AUZ-008) */
    public const string NAME = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:[-_][a-z0-9]+)*)+$/D';

    /** @var list<string> */
    public array $defaultRoles;

    /**
     * @param string       $owner          Who declares it: an application, an extension, "yorvoid"
     * @param list<string> $defaultRoles   The roles that have it by default; "everyone" for every actor
     * @param bool         $strict         Whether a super role has it only when it's granted to that role
     * @param bool         $recordsPermits Whether the decision log records its permits whatever it's configured to, e.g.
     *                                     for explaining someone else's access: their own refusals are recorded anyway
     *
     * @throws IntegratorError When the name or the label isn't valid
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $owner,
        array $defaultRoles = [],
        public ?string $group = null,
        public bool $strict = false,
        public bool $recordsPermits = false,
    ) {
        if (1 !== preg_match(self::NAME, $name)) {
            throw new IntegratorError(\sprintf('The ability name "%s" is invalid.', $name), 'Use lowercase words separated by dots, the owner\'s namespace first, e.g. "acme.badges.issue".', 'concepts/authorization.md#abilities');
        }

        if ('' === trim($label) || '' === trim($owner)) {
            throw new IntegratorError(\sprintf('The ability "%s" needs a label and an owner.', $name), 'Give it a label people read, e.g. "Issue badges", and the owner that declares it.', 'concepts/authorization.md#abilities');
        }

        $this->defaultRoles = array_values(array_unique($defaultRoles));
    }
}
