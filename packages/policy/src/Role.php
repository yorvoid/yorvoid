<?php

declare(strict_types=1);

namespace YorvoID\Policy;

/**
 * A role: declared by its owner, or created in a realm at runtime (AUZ-003). A super role has every
 * ability that isn't strict (AUZ-004).
 *
 * @api
 */
final readonly class Role
{
    /** A letter, then lowercase letters and digits, words joined by "-" or "_", e.g. "site_manager" */
    public const string NAME = '/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/D';

    /** The pseudo-role every actor has, signed in or not; never assigned */
    public const string EVERYONE = 'everyone';

    public const int MAX_NAME = 32;

    public const int MAX_LABEL = 50;

    public string $label;

    /**
     * @param string|null $owner Who declares it; null for a role created in a realm
     *
     * @throws InvalidRole When the name or the label isn't valid, or the name is "everyone"
     */
    public function __construct(
        public string $name,
        string $label,
        public ?string $owner = null,
        public bool $super = false,
    ) {
        if (1 !== preg_match(self::NAME, $name) || \strlen($name) > self::MAX_NAME) {
            throw new InvalidRole($name, \sprintf('The role name "%s" is invalid: use up to %d lowercase letters, digits, "-" and "_", starting with a letter.', $name, self::MAX_NAME));
        }

        if (self::EVERYONE === $name) {
            throw new InvalidRole($name, \sprintf('"%s" is reserved: every actor has it.', self::EVERYONE));
        }

        $this->label = trim($label);
        $length = mb_strlen($this->label);

        if ($length < 1 || $length > self::MAX_LABEL) {
            throw new InvalidRole($name, \sprintf('The label of the role "%s" must be 1 to %d characters.', $name, self::MAX_LABEL));
        }
    }

    /**
     * Whether its owner declares it, as opposed to a realm creating it: only those can be relabelled or deleted.
     */
    public function declared(): bool
    {
        return null !== $this->owner;
    }
}
