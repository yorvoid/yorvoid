<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Principals' security attributes as data.
 *
 * @api
 */
interface AttributeStore
{
    /**
     * @return array<string, string|int|bool|list<string>> By name, sorted
     */
    public function of(Realm $realm, PrincipalId $principalId): array;

    /**
     * @param string|int|bool|list<string> $value
     */
    public function set(Realm $realm, PrincipalId $principalId, string $name, string|int|bool|array $value): void;

    /**
     * @return bool Whether it had one
     */
    public function remove(Realm $realm, PrincipalId $principalId, string $name): bool;

    /** Every attribute of the principal goes, e.g. when it's deleted */
    public function forget(Realm $realm, PrincipalId $principalId): void;
}
