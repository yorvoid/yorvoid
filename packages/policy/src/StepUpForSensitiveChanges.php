<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\StepUpCheck;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Duration;
use YorvoID\Core\Operation\Operator;
use YorvoID\Policy\Attribute\SetAttribute;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateRole;
use YorvoID\Policy\Change\GrantAbilities;
use YorvoID\Policy\Change\ResetAbilities;
use YorvoID\Policy\Change\SetAbilities;
use YorvoID\Relations\Change\AddRelation;

/**
 * Giving anyone a super role or a strict ability, directly or through what follows from it (Effects),
 * takes a recent proof of who one is (AUZ-052), whatever permits the change: a role, a relation, a rule
 * or an ability policy. So does setting or removing a security attribute: what it gives depends on the
 * rules that read it, which can't be weighed. Without a StepUpCheck, only the host's operator makes these
 * changes. Taking roles and abilities away never waits for a proof.
 *
 * @api
 */
final readonly class StepUpForSensitiveChanges implements ForbidElement
{
    public const string NAME = 'yorvoid.policy.step_up';

    private Duration $window;

    /**
     * @param Duration|null $window How recent the proof must be: 5 minutes if not given
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
        return self::NAME;
    }

    /**
     * The changes that can give, by their command alone: one that only takes reads nothing.
     */
    public function matches(Request $request): bool
    {
        $command = $request->command;

        return !$request->actor instanceof Operator
            && ($command instanceof AssignRole || $command instanceof AssignGroupRole || $command instanceof AddGroupMember || ($command instanceof CreateRole && null !== $command->from)
                || $command instanceof GrantAbilities || $command instanceof SetAbilities || $command instanceof ResetAbilities || $command instanceof AddRelation || $command instanceof SetAttribute);
    }

    public function evaluate(Request $request): Truth
    {
        $sensitive = $this->sensitive($request);

        if (null === $sensitive) {
            return Truth::fails('It gives no super role and no strict ability.');
        }

        if (null === $this->stepUp) {
            return Truth::holds(\sprintf('%s takes a recent proof of who they are, and nothing checks one here.', $sensitive));
        }

        $proof = $this->stepUp->check($request->actor, new StepUpRemedy($this->window, operation: $request->name()));

        return match ($proof->value) {
            TruthValue::Holds => Truth::fails(\sprintf('%s, and %s', $sensitive, lcfirst($proof->detail)))->grounded(...$proof->grounds),
            TruthValue::Fails => Truth::holdsUnless(\sprintf('%s takes a recent proof of who they are: %s', $sensitive, lcfirst($proof->detail)), ...$proof->remedies),
            TruthValue::Unknown => $proof,
        };
    }

    /**
     * What makes the change sensitive, if it is: a security attribute set or removed, a super role given,
     * or a strict ability given, by a role or directly.
     */
    private function sensitive(Request $request): ?string
    {
        $command = $request->command;

        if ($command instanceof SetAttribute) {
            return \sprintf('%s the security attribute "%s"', null === $command->value ? 'Removing' : 'Setting', $command->name);
        }

        foreach (Effects::of($this->roles)->on($request)->gives as $part) {
            $abilities = $part->abilities;

            foreach ($part->roles as $role) {
                if (true === $this->roles->role($request->realm, $role)?->super) {
                    return \sprintf('Giving the super role "%s"', $role);
                }

                array_push($abilities, ...$this->roles->abilitiesOf($request->realm, $role));
            }

            foreach ($abilities as $ability) {
                if (true === $this->roles->ability($ability)?->strict) {
                    return \sprintf('Giving the strict ability "%s"', $ability);
                }
            }
        }

        return null;
    }
}
