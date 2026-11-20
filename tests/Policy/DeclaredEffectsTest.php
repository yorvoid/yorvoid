<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\ChangesAuthority;
use YorvoID\Core\Authorization\DeclaredEffects;
use YorvoID\Core\Authorization\DeclaresEffects;
use YorvoID\Core\Authorization\Effect;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Policy\NoEscalation;
use YorvoID\Policy\PolicyEffects;
use YorvoID\Policy\RolePermit;
use YorvoID\Policy\StepUpForSensitiveChanges;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\Support\TestPrincipal;
use YorvoID\Tests\Support\TrustedActors;

/**
 * A change of authority is weighed by what its declared effect gives and takes (D-51): one whose effect
 * nothing declares, or can't be computed, is never taken for one that gives nothing.
 */
final class DeclaredEffectsTest extends TestCase
{
    /**
     * In plain PHP, the forbids built with only the roles know the policy's changes: another package's,
     * e.g. delegation's, is Indeterminate there, and refused when Operations is built.
     */
    public function testAChangeOfAuthorityWhoseEffectNothingDeclaresIsNeverPermitted(): void
    {
        $world = new PolicyWorld();
        $forbids = [new NoEscalation($world->roles), new StepUpForSensitiveChanges($world->roles)];
        $evaluator = Evaluator::builtIn(new StubAuthority(Truth::holds('valid')), elements: [new RolePermit($world->roles), ...$forbids], actors: [new TrustedActors()]);
        $request = new Delegate(new Realm('acme'));

        foreach ($forbids as $forbid) {
            $truth = $forbid->evaluate(Request::forOperation(self::operation(), $request, self::actor(), self::web(), new \DateTimeImmutable()));
            self::assertSame(IndeterminateReason::EvaluationError, $truth->reason, $forbid->name());
        }

        $decision = $evaluator->decide(self::operation(), $request, self::actor(), self::web());
        self::assertSame(Verdict::Indeterminate, $decision->verdict, $decision->reason);
        self::assertStringContainsString('Nothing declares what acme.authority.delegate gives', $decision->reason);

        try {
            new Operations(new OperationCatalogue([Delegate::class]), $evaluator, new SecurityLog(new InMemorySecurityLogStore(), new InMemoryTransactions()), [new Delegations()]);
            self::fail('A change of authority whose effect nothing declares was let in.');
        } catch (IntegratorError $error) {
            self::assertSame('"acme.authority.delegate" changes authority, but nothing declares its effect.', $error->problem);
        }
    }

    /**
     * Its package's effects declared, it's weighed as any other.
     */
    public function testADeclaredChangeOfAuthorityIsWeighed(): void
    {
        $world = new PolicyWorld();
        $effects = new DeclaredEffects([new PolicyEffects($world->roles), new DelegationEffects(Effect::none())]);
        $escalation = new NoEscalation($world->roles, $effects);
        $request = Request::forOperation(self::operation(), new Delegate(new Realm('acme')), self::actor(), self::web(), new \DateTimeImmutable());

        self::assertFalse($escalation->matches($request), 'It gives and takes nothing.');
        $escalation->checkCatalogue(new OperationCatalogue([Delegate::class]));
        new StepUpForSensitiveChanges($world->roles, effects: $effects)->checkCatalogue(new OperationCatalogue([Delegate::class]));
    }

    /**
     * Each forbid that weighs changes checks, by itself, that it knows every change it may meet.
     */
    public function testEachForbidRefusesACatalogueWithAChangeItDoesntKnow(): void
    {
        $world = new PolicyWorld();

        foreach ([new NoEscalation($world->roles), new StepUpForSensitiveChanges($world->roles)] as $forbid) {
            try {
                $forbid->checkCatalogue(new OperationCatalogue([Delegate::class]));
                self::fail(\sprintf('%s let in a change it doesn\'t know.', $forbid->name()));
            } catch (IntegratorError $error) {
                self::assertSame('"acme.authority.delegate" changes authority, but nothing declares its effect.', $error->problem);
            }
        }
    }

    /**
     * An effect that can't be computed, e.g. a store that's down, makes the decision Indeterminate:
     * the failure goes on, never "it gives nothing".
     */
    public function testAnEffectThatCantBeComputedIsNeverNone(): void
    {
        $world = new PolicyWorld();
        $effects = new DeclaredEffects([new PolicyEffects($world->roles), new DelegationEffects(new \RuntimeException('The store is down.'))]);
        $evaluator = Evaluator::builtIn(new StubAuthority(Truth::holds('valid')), elements: [new RolePermit($world->roles), new NoEscalation($world->roles, $effects), new StepUpForSensitiveChanges($world->roles, effects: $effects)], actors: [new TrustedActors()]);

        $decision = $evaluator->decide(self::operation(), new Delegate(new Realm('acme')), self::actor(), self::web());

        self::assertSame(Verdict::Indeterminate, $decision->verdict);
    }

    public function testACommandsEffectIsDeclaredOnce(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The effect of '.Delegate::class.' is declared twice');

        new DeclaredEffects([new DelegationEffects(Effect::none()), new DelegationEffects(Effect::none())]);
    }

    private static function operation(): \YorvoID\Core\Operation\OperationDescription
    {
        return new OperationCatalogue([Delegate::class])->forCommand(Delegate::class);
    }

    private static function actor(): TestPrincipal
    {
        return new TestPrincipal(new Realm('acme'), new PrincipalId(Ids::next()));
    }

    private static function web(): Origin
    {
        return new Origin(OriginKind::Web);
    }
}

/**
 * A change of authority another package would bring, e.g. delegation's.
 *
 * @implements ChangesAuthority<null>
 */
#[Operation('acme.authority.delegate', 'Delegate', AuthorizedBy::Ability, ability: 'acme.pages.publish')]
final readonly class Delegate implements ChangesAuthority
{
    public function __construct(
        public Realm $realm,
    ) {
    }
}

final readonly class Delegations implements OperationHandler
{
    public function operations(): array
    {
        return [Delegate::class => static fn (Delegate $command, Permit $permit): null => null];
    }
}

final readonly class DelegationEffects implements DeclaresEffects
{
    public function __construct(
        private Effect|\Throwable $effect,
    ) {
    }

    public function declares(): array
    {
        return [Delegate::class];
    }

    public function effectOf(Request $request): Effect
    {
        return $this->effect instanceof \Throwable ? throw $this->effect : $this->effect;
    }
}
