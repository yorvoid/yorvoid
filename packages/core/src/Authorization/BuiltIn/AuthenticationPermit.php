<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\BuiltIn;

use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;

/**
 * Authentication, e.g. signing in or using a recovery code: anyone may try, and the handler checks the
 * secret they bring before anything happens.
 *
 * @internal
 */
final readonly class AuthenticationPermit implements PermitElement
{
    public function name(): string
    {
        return 'yorvoid.authentication';
    }

    public function basis(): AuthorizedBy
    {
        return AuthorizedBy::Authentication;
    }

    public function matches(Request $request): bool
    {
        return true;
    }

    public function evaluate(Request $request): Truth
    {
        return Truth::holds('Authentication: the handler checks it.');
    }
}
