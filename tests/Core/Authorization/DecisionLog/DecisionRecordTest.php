<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\DecisionLog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Reason;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;

/**
 * A decision as recorded: its canonical form, which its hash is over, and nothing an element's text
 * could leak.
 */
final class DecisionRecordTest extends TestCase
{
    public function testTheCanonicalFormIsVersionedInUtcAndSorted(): void
    {
        $ana = new PrincipalId(new UuidV7());
        $request = Request::forAbility('acme.pages.edit', new Realm('acme'), new ResourceRef('page', '42'), new Member(new Realm('acme'), $ana), new Origin(OriginKind::Web, 'pages.edit', 'request-1'), new \DateTimeImmutable('2026-11-08 19:00:00.5 +01:00'));
        $decision = Decision::deny('No permit applies: ana@example.com isn\'t an editor.', [
            new Reason('yorvoid.policy.roles', 'permit', Truth::fails('ana@example.com has no role with it.')),
            new Reason('authority', 'authority', Truth::holds('Active.')),
        ], [new StepUpRemedy(Duration::minutes(5))])->identified('d-1', $request->at, ['relations' => 2, 'policy' => 7]);

        $record = DecisionRecord::of($request, $decision);

        self::assertSame([
            'format' => 1,
            'id' => 'd-1',
            'realm' => 'acme',
            'decided_at' => '2026-11-08T18:00:00.500000Z',
            'kind' => 'ability',
            'name' => 'acme.pages.edit',
            'actor' => (string) $ana,
            'resource' => ['page', '42'],
            'verdict' => 'deny',
            'basis' => null,
            'reason' => 'No permit applies: ana@example.com isn\'t an editor.',
            'indeterminate' => [],
            'remedies' => ['step_up'],
            'elements' => [['yorvoid.policy.roles', 'permit', 'fails'], ['authority', 'authority', 'holds']],
            'versions' => ['policy' => 7, 'relations' => 2],
            'origin' => ['web', 'request-1'],
        ], $record->toArray(), 'The elements\' texts aren\'t kept.');
        self::assertSame(hash('sha256', $record->json()), $record->hash());
        self::assertSame($record->json(), DecisionRecord::fromJson($record->json())->json());
    }

    public function testAnOperationsRecordNamesItAndItsBasis(): void
    {
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Operator], null, null, IssueBadge::class, []);
        $request = Request::forOperation($operation, new IssueBadge(new Realm('acme'), new PrincipalId(new UuidV7())), new Operator(), new Origin(OriginKind::Console, correlationId: 'job-1'), new \DateTimeImmutable('2026-11-08 18:00:00 UTC'));
        $unknown = Decision::indeterminate([IndeterminateReason::StoreUnreachable], 'Can\'t tell.')->identified('d-2', $request->at, []);
        $permit = Decision::permit(AuthorizedBy::Operator, 'The operator.')->identified('d-3', $request->at, []);

        self::assertSame(['operation', 'acme.badge.issue', null, null, 'indeterminate', ['store_unreachable'], []], [($r = DecisionRecord::of($request, $unknown))->kind, $r->name, $r->actor, $r->resource, $r->verdict, $r->indeterminate, $r->versions]);
        self::assertSame(['permit', 'operator'], [($r = DecisionRecord::of($request, $permit))->verdict, $r->basis]);
        self::assertNotSame(DecisionRecord::of($request, $unknown)->hash(), DecisionRecord::of($request, $permit)->hash());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformed(): iterable
    {
        $valid = ['format' => 1, 'id' => 'd-1', 'realm' => 'acme', 'decided_at' => '2026-11-08T18:00:00.000000Z', 'kind' => 'ability', 'name' => 'acme.pages.edit', 'actor' => null, 'resource' => null, 'verdict' => 'deny', 'basis' => null, 'reason' => 'No.', 'indeterminate' => [], 'remedies' => [], 'elements' => [], 'versions' => [], 'origin' => ['web', 'r']];
        $with = static fn (array $change): string => json_encode([...$valid, ...$change], \JSON_THROW_ON_ERROR);

        yield 'not JSON' => ['{', 'A stored decision isn\'t valid JSON.'];
        yield 'not a map' => ['"text"', 'A stored decision has an unknown format.'];
        yield 'another format' => [$with(['format' => 2]), 'A stored decision has an unknown format.'];
        yield 'no ID' => [$with(['id' => 1]), 'A stored decision\'s "id" isn\'t text.'];
        yield 'a bad time' => [$with(['decided_at' => 'yesterday']), 'A stored decision is malformed.'];
        yield 'another kind' => [$with(['kind' => 'guess']), 'A stored decision is malformed.'];
        yield 'an actor that isn\'t text' => [$with(['actor' => 5]), 'A stored decision\'s "actor" isn\'t text.'];
        yield 'remedies not a list' => [$with(['remedies' => ['a' => 'step_up']]), 'A stored decision\'s "remedies" isn\'t a list.'];
        yield 'a remedy not text' => [$with(['remedies' => [1]]), 'A stored decision\'s "remedies" holds something else than text.'];
        yield 'elements not a list' => [$with(['elements' => 'all']), 'A stored decision\'s elements aren\'t a list.'];
        yield 'a malformed element' => [$with(['elements' => [['a', 'permit']]]), 'A stored decision\'s element is malformed.'];
        yield 'versions not a map' => [$with(['versions' => 'v1']), 'A stored decision\'s versions aren\'t a map.'];
        yield 'a version not a number' => [$with(['versions' => ['policy' => '7']]), 'A stored decision\'s versions are malformed.'];
        yield 'a version without a name' => [$with(['versions' => ['7' => 7]]), 'A stored decision\'s versions are malformed.'];
        yield 'an element not a list' => [$with(['elements' => ['permit']]), 'A stored decision\'s element is malformed.'];
        yield 'an element without a source' => [$with(['elements' => [[1, 'permit', 'holds']]]), 'A stored decision\'s element is malformed.'];
        yield 'an element without an effect' => [$with(['elements' => [['a', 1, 'holds']]]), 'A stored decision\'s element is malformed.'];
        yield 'an element without a truth' => [$with(['elements' => [['a', 'permit', 1]]]), 'A stored decision\'s element is malformed.'];
        yield 'an origin of text' => [$with(['origin' => 'web']), 'A stored decision\'s origin is malformed.'];
        yield 'an origin without a kind' => [$with(['origin' => [1, 'r']]), 'A stored decision\'s origin is malformed.'];
        yield 'a malformed origin' => [$with(['origin' => ['web']]), 'A stored decision\'s origin is malformed.'];
    }

    #[DataProvider('malformed')]
    public function testAMalformedRecordIsRefused(string $json, string $message): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        DecisionRecord::fromJson($json);
    }

    public function testAResourceThatIsntAPairIsNone(): void
    {
        foreach ([['page'], 'page', [1, '2'], ['page', 2]] as $resource) {
            $json = json_encode(['format' => 1, 'id' => 'd-1', 'realm' => 'acme', 'decided_at' => '2026-11-08T18:00:00.000000Z', 'kind' => 'operation', 'name' => 'acme.badge.issue', 'actor' => 'a', 'resource' => $resource, 'verdict' => 'deny', 'basis' => 'self', 'reason' => 'No.', 'indeterminate' => ['a', 'b'], 'remedies' => ['step_up', 'wait'], 'elements' => [], 'versions' => ['policy' => 1], 'origin' => ['web', 'r']], \JSON_THROW_ON_ERROR);
            $record = DecisionRecord::fromJson($json);

            self::assertNull($record->resource, json_encode($resource, \JSON_THROW_ON_ERROR));
            self::assertSame(['a', 'self', ['policy' => 1], ['a', 'b'], ['step_up', 'wait']], [$record->actor, $record->basis, $record->versions, $record->indeterminate, $record->remedies]);
        }
    }

    public function testTheTextIsWrittenAsItIs(): void
    {
        $request = Request::forAbility('acme.pages.edit', new Realm('acme'), new ResourceRef('page', 'ünï/1'), new Operator(), new Origin(OriginKind::Web), new \DateTimeImmutable('2026-11-08 18:00:00 UTC'));

        self::assertStringContainsString('["page","ünï/1"]', DecisionRecord::of($request, Decision::deny('No.')->identified('d-1', $request->at, []))->json(), 'Neither slashes nor letters are escaped.');
    }

    public function testADecisionWithoutItsTimeIsntRecorded(): void
    {
        $request = Request::forAbility('acme.pages.edit', new Realm('acme'), null, new Operator(), new Origin(OriginKind::Web), new \DateTimeImmutable());

        $this->expectException(\YorvoID\Core\Exception\IntegratorError::class);

        DecisionRecord::of($request, new Decision(\YorvoID\Core\Operation\Verdict::Deny, null, 'No.', id: 'd-1'));
    }
}
