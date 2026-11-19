<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ChangesAuthority;
use YorvoID\Core\Authorization\ChecksCatalogue;
use YorvoID\Core\Authorization\DeclaredEffects;
use YorvoID\Core\Authorization\Effect;
use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\StepUpCheck;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Duration;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Operator;

/**
 * Giving anyone a super role or a strict ability, directly or through what follows from it (Effects),
 * takes a recent proof of who one is (AUZ-052), whatever permits the change: a role, a relation, a rule
 * or an ability policy. So does setting or removing a security attribute: what it gives depends on the
 * rules that read it, which can't be weighed. Without a StepUpCheck, only the host's operator makes these
 * changes. Taking roles and abilities away never waits for a proof.
 *
 * @api
 */
final readonly class StepUpForSensitiveChanges implements ForbidElement, ChecksCatalogue
{
    public const string NAME = 'yorvoid.policy.step_up';

    private Duration $window;

    private DeclaredEffects $effects;

    /**
     * @param Duration|null        $window  How recent the proof must be: 5 minutes if not given
     * @param DeclaredEffects|null $effects What the changes give and take: the policy's own if not given
     */
    public function __construct(
        private Roles $roles,
        private ?StepUpCheck $stepUp = null,
        ?Duration $window = null,
        ?DeclaredEffects $effects = null,
    ) {
        $this->window = $window ?? Duration::minutes(5);
        $this->effects = $effects ?? PolicyEffects::shared($roles);
    }

    /**
     * Every change of authority it may meet has its effect declared.
     */
    public function checkCatalogue(OperationCatalogue $catalogue): void
    {
        $this->effects->check($catalogue);
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * A change of authority that can give, or whose effect isn't declared: one that only takes needs no
     * proof.
     */
    public function matches(Request $request): bool
    {
        return !$request->actor instanceof Operator
            && $request->command instanceof ChangesAuthority
            && (!($effect = $this->effects->effectOf($request)) instanceof Effect || $effect->gives());
    }

    public function evaluate(Request $request): Truth
    {
        if (!$request->command instanceof ChangesAuthority) {
            return Truth::fails('It changes no authority.');
        }

        $effect = $this->effects->effectOf($request);

        // A change of authority whose effect nothing declares is never taken for one that gives nothing (D-51).
        if (null === $effect) {
            return Truth::unknown(IndeterminateReason::EvaluationError, \sprintf('Nothing declares what %s gives.', $request->name()));
        }

        $sensitive = $this->sensitive($request, $effect);

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
    private function sensitive(Request $request, Effect $effect): ?string
    {
        if (null !== $effect->attribute) {
            return \sprintf('%s the security attribute "%s"', $effect->attribute->removes ? 'Removing' : 'Setting', $effect->attribute->name);
        }

        foreach ($effect->gives as $part) {
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
