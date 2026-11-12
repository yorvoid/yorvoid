<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ChecksCatalogue;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Preloads;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\RequiresForbids;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\OperationCatalogue;

/**
 * The permit by roles: a request that needs an ability is let through when a role the actor has, in the
 * realm or on the request's resource, has that ability, or is a super role and the ability isn't strict.
 * The evaluator needs NoEscalation and StepUpForSensitiveChanges beside it.
 *
 * @api
 */
final readonly class RolePermit implements PermitElement, ChecksCatalogue, Preloads, RequiresForbids
{
    /** What every permit that lets policy changes through needs beside it */
    public const array SAFETY = [
        NoEscalation::NAME => 'Add new NoEscalation($roles) to the evaluator\'s elements: nobody gives or takes what they don\'t hold.',
        StepUpForSensitiveChanges::NAME => 'Add new StepUpForSensitiveChanges($roles, $stepUpCheck) to the evaluator\'s elements: giving a super role or a strict ability takes a recent proof.',
    ];

    public function __construct(
        private Roles $roles,
    ) {
    }

    public function name(): string
    {
        return 'yorvoid.roles';
    }

    public function basis(): AuthorizedBy
    {
        return AuthorizedBy::Ability;
    }

    /**
     * Every ability an operation needs must be declared.
     */
    public function checkCatalogue(OperationCatalogue $catalogue): void
    {
        foreach ($catalogue->all() as $operation) {
            if (null !== $operation->ability && null === $this->roles->ability($operation->ability)) {
                throw new IntegratorError(\sprintf('The operation "%s" needs the ability "%s", which isn\'t declared.', $operation->name, $operation->ability), 'Declare it in a PolicyDeclarations of its owner.', 'concepts/authorization.md#abilities');
            }
        }
    }

    public function matches(Request $request): bool
    {
        return null !== $request->ability;
    }

    /**
     * The roles of a batch's requests, read at once.
     */
    public function preload(array $requests): void
    {
        $this->roles->preload($requests);
    }

    public function evaluate(Request $request): Truth
    {
        return $this->roles->decide($request);
    }

    /**
     * Roles let changes through that no-escalation and the step-up weigh (C1).
     */
    public function requiredForbids(): array
    {
        return self::SAFETY;
    }
}
