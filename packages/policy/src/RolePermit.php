<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ChecksCatalogue;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Preloads;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\StepUpCheck;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\SetAbilities;

/**
 * The permit by roles: a request that needs an ability is let through when a role the actor has, in the
 * realm or on the request's resource, has that ability, or is a super role and the ability isn't strict.
 * Giving a strict ability or a super role also takes a recent proof of who one is (AUZ-052): without a
 * StepUpCheck, only the host's operator gives them.
 *
 * @api
 */
final readonly class RolePermit implements PermitElement, ChecksCatalogue, Preloads
{
    private Duration $window;

    /**
     * @param Duration|null $window How recent the proof must be for a sensitive change: 5 minutes if not given
     */
    public function __construct(
        private Roles $roles,
        private ?StepUpCheck $stepUp = null,
        ?Duration $window = null,
    ) {
        $this->window = $window ?? Duration::minutes(5);
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
        $truth = $this->roles->decide($request);
        $sensitive = $this->sensitive($request);

        if (TruthValue::Holds !== $truth->value || null === $sensitive) {
            return $truth;
        }

        if (null === $this->stepUp) {
            return Truth::fails(\sprintf('%s takes a recent proof of who they are, and nothing checks one here.', $sensitive));
        }

        $proof = $this->stepUp->check($request->actor, new StepUpRemedy($this->window, operation: $request->name()));

        return TruthValue::Holds === $proof->value ? Truth::holds(\sprintf('%s %s', $truth->detail, $proof->detail), ...$truth->grounds, ...$proof->grounds) : $proof;
    }

    /**
     * What makes the change sensitive, if it is: a super role given, or a strict ability given.
     */
    private function sensitive(Request $request): ?string
    {
        $command = $request->command;

        if (($command instanceof AssignRole || $command instanceof AssignGroupRole) && true === $this->roles->role($request->realm, $command->role)?->super) {
            return \sprintf('Giving the super role "%s"', $command->role);
        }

        if ($command instanceof AddGroupMember) {
            foreach ($this->roles->rolesOfGroup($request->realm, $command->group) as $role) {
                if (true === $this->roles->role($request->realm, $role)?->super) {
                    return \sprintf('Giving the super role "%s" through the group "%s"', $role, $command->group);
                }
            }
        }

        $given = match (true) {
            $command instanceof GrantAbilities, $command instanceof SetAbilities => $command->abilities,
            $command instanceof CreateRole && null !== $command->from => $this->roles->abilitiesOf($request->realm, $command->from),
            default => [],
        };

        foreach ($given as $ability) {
            if (true === $this->roles->ability($ability)?->strict) {
                return \sprintf('Giving the strict ability "%s"', $ability);
            }
        }

        return null;
    }
}
