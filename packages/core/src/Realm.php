<?php

declare(strict_types=1);

namespace YorvoID\Core;

use YorvoID\Core\Exception\IntegratorError;

/**
 * An isolation boundary: every principal, credential, grant and log entry belongs to exactly one realm,
 * and nothing is shared between realms (D-14). Most applications have only the default realm;
 * multi-tenant ones have one per tenant.
 *
 * A realm is named like a hostname label: 1 to 63 lowercase letters, digits and hyphens, not starting
 * or ending with a hyphen. The name is stable: it's stored in every row of the realm.
 *
 * @api
 */
final readonly class Realm implements \Stringable
{
    public const string DEFAULT = 'default';

    private const string NAME = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    public function __construct(public string $name)
    {
        if (1 !== preg_match(self::NAME, $name)) {
            throw new IntegratorError(\sprintf('The realm name "%s" is invalid.', $name), 'Use 1 to 63 lowercase letters, digits and hyphens, not starting or ending with a hyphen, e.g. "acme-corp".', 'concepts/realms.md');
        }
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
