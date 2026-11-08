<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\Explanation;
use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\GroundKind;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\PolicyElement;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\Reason;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\RoleGrant;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;
use YorvoID\Core\Authorization\VersionSource;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Core\Authorization\Fixtures\StubAuthority;
use YorvoID\Tests\Core\Authorization\Fixtures\StubForbid;
use YorvoID\Tests\Core\Authorization\Fixtures\StubPermit;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * Explanations (AUZ-020): the row of the precedence that decided, the elements behind it and what they
 * rest on, the remedies and the versions, without text for people.
 */
final class ExplanationTest extends TestCase
{
    /**
     * @param list<PolicyElement> $elements
     * @param list<string>        $behind   The sources of the elements behind the row
     * @param list<GroundKind>    $grounds  What those rest on
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rows')]
    public function testNamesTheRowThatDecidedAndWhatsBehindIt(array $elements, Truth $authority, Precedence $precedence, int $row, Verdict $verdict, array $behind, array $grounds): void
    {
        $decision = self::decide($elements, $authority);
        $explanation = Explanation::of($decision);

        self::assertSame([$precedence, $row, $verdict], [$explanation->precedence, $explanation->precedence->row(), $explanation->verdict]);
        self::assertSame($precedence, $decision->precedence);
        self::assertSame($behind, array_map(static fn (Reason $reason): string => $reason->source, $explanation->elements));
        self::assertSame($grounds, array_map(static fn (Ground $ground): GroundKind => $ground->kind, $explanation->grounds));
        self::assertSame($decision->id, $explanation->id);
        self::assertSame(['policy' => 3], $explanation->versions);
    }

    /**
     * @return iterable<string, array{list<PolicyElement>, Truth, Precedence, int, Verdict, list<string>, list<GroundKind>}>
     */
    public static function rows(): iterable
    {
        $valid = Truth::holds('valid', Ground::authority('active'));
        $role = Truth::holds('editor', Ground::role('editor', RoleGrant::Default));
        $rule = Truth::holds('locked', Ground::rule('acme.locked'), Ground::attribute('acme.classification'));

        yield 'a forbid holds' => [[new StubForbid('locked', $rule), new StubForbid('quiet', Truth::fails('no')), new StubPermit('roles', $role)], $valid, Precedence::ForbidHolds, 1, Verdict::Deny, ['locked'], [GroundKind::Rule, GroundKind::Attribute]];
        yield 'a forbid is unknown' => [[new StubForbid('locked', Truth::unknown(IndeterminateReason::MissingData, '?')->grounded(Ground::attribute('acme.classification'))), new StubPermit('roles', $role)], $valid, Precedence::ForbidUnknown, 2, Verdict::Indeterminate, ['locked'], [GroundKind::Attribute]];
        yield 'the authority is invalid' => [[new StubPermit('roles', $role)], Truth::fails('suspended')->grounded(Ground::authority('suspended')), Precedence::AuthorityInvalid, 3, Verdict::Deny, ['authority'], [GroundKind::Authority]];
        yield 'the authority is unknown' => [[new StubPermit('roles', $role)], Truth::unknown(IndeterminateReason::MissingData, '?'), Precedence::AuthorityUnknown, 4, Verdict::Indeterminate, ['authority'], []];
        yield 'permits hold' => [[new StubPermit('relations', Truth::holds('editor of', Ground::relation(new ResourceRef('page', '1'), 'editor'))), new StubPermit('roles', $role), new StubPermit('rule', Truth::fails('no'))], $valid, Precedence::PermitHolds, 5, Verdict::Permit, ['relations', 'roles'], [GroundKind::Relation, GroundKind::Role]];
        yield 'a permit is unknown' => [[new StubPermit('roles', new \RuntimeException('down')), new StubPermit('rule', Truth::fails('no'))], $valid, Precedence::PermitUnknown, 6, Verdict::Indeterminate, ['roles'], []];
        yield 'no permit holds' => [[new StubPermit('roles', Truth::fails('no', new StepUpRemedy(Duration::minutes(5)))), new StubForbid('locked', Truth::fails('no'))], $valid, Precedence::NoPermit, 7, Verdict::Deny, ['roles'], []];
        yield 'no permit at all' => [[], $valid, Precedence::NoPermit, 7, Verdict::Deny, [], []];
    }

    public function testCarriesTheRemediesAndTheGroundsOfTruths(): void
    {
        $explanation = Explanation::of(self::decide([new StubPermit('roles', Truth::fails('no', new StepUpRemedy(Duration::minutes(5))))], Truth::holds('valid')));
        self::assertCount(1, $explanation->remedies);

        $truth = Truth::fails('suspended')->grounded(Ground::authority('suspended'))->grounded(Ground::evidence('step_up'));
        self::assertSame([TruthValue::Fails, 'suspended'], [$truth->value, $truth->detail]);
        self::assertSame(['suspended', 'step_up'], array_map(static fn (Ground $ground): string => $ground->name, $truth->grounds), 'Added to what it rested on.');
        $unknown = Truth::unknown(IndeterminateReason::MissingData, '?')->grounded(Ground::attribute('acme.x'));
        self::assertSame(IndeterminateReason::MissingData, $unknown->reason);
        // Named, a variadic's arguments would be keyed by their names: grounds are a list whatever.
        self::assertSame([0], array_keys(Truth::holds('yes', role: Ground::rule('r'))->grounds));
        self::assertSame([0, 1], array_keys(Truth::holds('yes', Ground::rule('r'))->grounded(attribute: Ground::attribute('a'))->grounds));
        $remedied = Truth::fails('no', new StepUpRemedy(Duration::minutes(5)))->grounded();
        self::assertCount(1, $remedied->remedies, 'Its remedies stay.');

        $role = Ground::role('editor', RoleGrant::Granted, new ResourceRef('site', '1'), 'writers', 'App\RoleSource');
        self::assertSame([GroundKind::Role, 'editor', RoleGrant::Granted, 'site:1', 'writers', 'App\RoleSource'], [$role->kind, $role->name, $role->grant, (string) $role->on, $role->group, $role->source]);
        $relation = Ground::relation(new ResourceRef('page', '1'), 'editor');
        self::assertSame([GroundKind::Relation, 'editor', null, 'page:1'], [$relation->kind, $relation->name, $relation->grant, (string) $relation->on]);

        foreach ([[Ground::rule('r'), GroundKind::Rule], [Ground::attribute('a'), GroundKind::Attribute], [Ground::evidence('e'), GroundKind::Evidence], [Ground::authority('s'), GroundKind::Authority]] as [$ground, $kind]) {
            self::assertSame([$kind, null, null, null, null], [$ground->kind, $ground->grant, $ground->on, $ground->group, $ground->source]);
        }
    }

    public function testOnlyTheEvaluatorsDecisionsAreExplained(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('Only a decision the evaluator made can be explained.');
        Explanation::of(Decision::deny('Made up.'));
    }

    /**
     * @param list<PolicyElement> $elements
     */
    private static function decide(array $elements, Truth $authority): Decision
    {
        $versions = new class implements VersionSource {
            public function name(): string
            {
                return 'policy';
            }

            public function version(Realm $realm): int
            {
                return 3;
            }
        };

        return new Evaluator($elements, new StubAuthority($authority), versions: [$versions])->decideAbility(new Member(new Realm('acme'), new PrincipalId(new UuidV7())), 'acme.pages.edit', new Realm('acme'), null, new Origin(OriginKind::Web));
    }
}
