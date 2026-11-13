<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\HttpFoundation\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\ConcernsNoResource;
use YorvoID\Core\Operation\ConcernsResource;

/**
 * What isGranted() was given for a declared ability, as the resource and the subject the policy decides
 * on: only what says which resource it is about, or that it is about none. Anything else is refused,
 * rather than decided with no resource, where a forbid or a rule on the resource wouldn't apply (H-5).
 *
 * @internal
 */
final class SubjectOf
{
    /**
     * @return array{ResourceRef|null, object|null}
     *
     * @throws IntegratorError When it's none of these
     */
    public static function isGranted(mixed $subject, string $ability): array
    {
        return match (true) {
            null === $subject, $subject instanceof Request => [null, null],
            $subject instanceof ResourceRef => [$subject, null],
            $subject instanceof ConcernsResource, $subject instanceof ConcernsNoResource => [null, $subject],
            \is_array($subject) && array_is_list($subject) && 2 === \count($subject) && $subject[0] instanceof ResourceRef && \is_object($subject[1]) => [$subject[0], $subject[1]],
            default => throw new IntegratorError(\sprintf('isGranted("%s") was given %s.', $ability, get_debug_type($subject)), 'Give a ResourceRef, a subject that implements ConcernsResource (or ConcernsNoResource when it concerns none), or [ResourceRef, subject].', 'concepts/authorization.md#the-subject'),
        };
    }
}
