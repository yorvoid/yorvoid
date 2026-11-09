<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Remedy;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\SuspendPrincipal;
use YorvoID\Policy\Attribute\SetAttribute;
use YorvoID\Policy\Change\AddGroupMember;
use YorvoID\Policy\Change\AssignGroupRole;
use YorvoID\Policy\Change\AssignRole;
use YorvoID\Policy\Change\CreateGroup;
use YorvoID\Relations\Change\AddRelation;
use YorvoID\Relations\Subject;

/**
 * Runs policy cases (AUZ-043): each on the policy with nothing stored, set up through its operations as
 * the host's operator, then decided as the application decides.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class PolicyTests
{
    /**
     * @param iterable<PolicyCase> $cases
     *
     * @return list<PolicyResult> One for each, in order
     *
     * @throws IntegratorError When two cases have the same description
     */
    public function run(PolicyUnderTest $policy, iterable $cases): array
    {
        return array_map(fn (PolicyCase $case): PolicyResult => $this->one($policy, $case), array_values(self::named($cases)));
    }

    /**
     * The cases by their descriptions, which name them in the report and in PHPUnit.
     *
     * @param iterable<PolicyCase> $cases
     *
     * @return array<string, PolicyCase>
     *
     * @throws IntegratorError When two have the same
     */
    public static function named(iterable $cases): array
    {
        $named = [];

        foreach ($cases as $case) {
            if (isset($named[$case->description])) {
                throw new IntegratorError(\sprintf('Two policy cases are described as "%s".', $case->description), 'Describe each case differently: the description names it in the report.', 'how-to/test-your-policy.md#cases');
            }

            $named[$case->description] = $case;
        }

        return $named;
    }

    /**
     * @throws IntegratorError When the case asks nothing or expects nothing
     */
    public function one(PolicyUnderTest $policy, PolicyCase $case): PolicyResult
    {
        if (null === $case->ability || null === $case->verdict) {
            throw new IntegratorError(\sprintf('The policy case "%s" doesn\'t say what\'s asked and what\'s expected.', $case->description), 'Finish it with asks(...) and permits(), denies() or indeterminate().', 'how-to/test-your-policy.md#cases');
        }

        $expected = self::verdict($case->verdict);
        $services = $policy->fresh();

        try {
            $actor = self::setUp($services, $case);
        } catch (IntegratorError $error) {
            throw $error;
        } catch (\Throwable $e) {
            return new PolicyResult($case, $expected, null, \sprintf('The case couldn\'t be set up: %s: %s', $e::class, $e->getMessage()));
        }

        $decision = $services->policy->decide($actor, $case->ability, $services->realm, $case->resource, new Origin(OriginKind::Web), $case->subject);

        return new PolicyResult($case, $expected, $decision, self::difference($case, $expected, $decision));
    }

    private static function setUp(PolicyServices $services, PolicyCase $case): Actor
    {
        $realm = $services->realm;
        $operator = static fn (Command $command): mixed => self::asOperator($services, $command);

        foreach ($case->before as $command) {
            $operator($command instanceof Command ? $command : $command($realm));
        }

        if ($case->anonymous) {
            return new Anonymous();
        }

        $principal = self::asOperator($services, new CreatePrincipal($realm, PrincipalKind::Human, PrincipalStatus::Invited === $case->status ? PrincipalStatus::Invited : PrincipalStatus::Active));
        $id = $principal->id;

        foreach ($case->roles as $role) {
            $operator(new AssignRole($realm, $id, $role));
        }

        foreach ($case->on as [$role, $resource]) {
            $operator(new AssignRole($realm, $id, $role, $resource));
        }

        foreach ($case->groups as $group => $roles) {
            $operator(new CreateGroup($realm, $group, $group));
            $operator(new AddGroupMember($realm, $group, Subject::principal($id)));

            foreach ($roles as $role) {
                $operator(\is_array($role) ? new AssignGroupRole($realm, $group, $role[0], $role[1]) : new AssignGroupRole($realm, $group, $role));
            }
        }

        foreach ($case->relations as [$resource, $relation]) {
            $operator(new AddRelation($realm, $resource, $relation, Subject::principal($id)));
        }

        foreach ($case->attributes as $name => $value) {
            $operator(new SetAttribute($realm, $id, $name, $value));
        }

        match ($case->status) {
            PrincipalStatus::Suspended => $operator(new SuspendPrincipal($realm, $id)),
            PrincipalStatus::Deleted => $operator(new DeletePrincipal($realm, $id)),
            PrincipalStatus::Active, PrincipalStatus::Invited => null,
        };

        return new ActingPrincipal($realm, $id);
    }

    /**
     * @template T
     *
     * @param Command<T> $command
     *
     * @return T
     */
    private static function asOperator(PolicyServices $services, Command $command): mixed
    {
        return $services->operations->run($command, new Operator(), new Origin(OriginKind::Console));
    }

    /**
     * Each verdict is its own: Permit-offline isn't a Permit.
     */
    private static function verdict(Verdict $verdict): string
    {
        return match ($verdict) {
            Verdict::Permit => 'permit',
            Verdict::PermitOffline => 'permit_offline',
            Verdict::Deny => 'deny',
            Verdict::Indeterminate => 'indeterminate',
        };
    }

    /**
     * How the decision differs from what the case states, if it does.
     */
    private static function difference(PolicyCase $case, string $expected, Decision $decision): ?string
    {
        $differences = [];

        $got = self::verdict($decision->verdict);

        if ($expected !== $got) {
            $differences[] = \sprintf('expected %s, got %s', $expected, $got);
        }

        $remedies = array_map(static fn (Remedy $remedy): string => $remedy->name(), $decision->remedies);

        if (null !== $case->remedies && $case->remedies !== $remedies) {
            $differences[] = \sprintf('expected the remedies [%s], got [%s]', implode(', ', $case->remedies), implode(', ', $remedies));
        }

        if (null !== $case->precedence && $case->precedence !== $decision->precedence) {
            $differences[] = \sprintf('expected the precedence row %s, got %s', $case->precedence->name, $decision->precedence->name ?? 'none');
        }

        $reasons = array_map(static fn (IndeterminateReason $reason): string => $reason->value, $decision->indeterminate);

        if (null !== $case->reasons && array_map(static fn (IndeterminateReason $reason): string => $reason->value, $case->reasons) !== $reasons) {
            $differences[] = \sprintf('expected it couldn\'t decide for [%s], got [%s]', implode(', ', array_map(static fn (IndeterminateReason $reason): string => $reason->value, $case->reasons)), implode(', ', $reasons));
        }

        return [] === $differences ? null : \sprintf('%s: %s (%s)', ucfirst(implode('; ', $differences)), $decision->reason, $case->description);
    }
}
