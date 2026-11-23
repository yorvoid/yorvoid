<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\DecisionLog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\ReadSet;
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
use YorvoID\Testing\Ids;
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
        $ana = new PrincipalId(Ids::next());
        $request = Request::forAbility('acme.pages.edit', new Realm('acme'), new ResourceRef('page', '42'), new Member(new Realm('acme'), $ana), new Origin(OriginKind::Web, 'pages.edit', 'request-1'), new \DateTimeImmutable('2026-11-08 19:00:00.5 +01:00'));
        $decision = Decision::deny('No permit applies: ana@example.com isn\'t an editor.', [
            new Reason('yorvoid.policy.roles', 'permit', Truth::fails('ana@example.com has no role with it.')),
            new Reason('authority', 'authority', Truth::holds('Active.')),
        ], [new StepUpRemedy(Duration::minutes(5))])->identified('d-1', $request->at, new ReadSet(['relations' => 2, 'policy' => 7], ['relations']));

        $record = DecisionRecord::of($request, $decision);

        self::assertSame([
            'format' => 4,
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
            'viewer' => null,
            'details' => [],
            'read' => ['sources' => ['policy' => 7, 'relations' => 2], 'moved' => ['relations'], 'attributes' => [], 'delegation' => null],
            'parent' => null,
        ], $record->toArray(), 'The elements\' texts aren\'t kept.');
        self::assertSame(hash('sha256', $record->json()), $record->hash());
        self::assertSame($record->json(), DecisionRecord::fromJson($record->json())->json());

        // A consequence names the decision of the operation that caused it (D-50).
        $caused = DecisionRecord::of($request, $decision, 'd-0');
        self::assertSame('d-0', $caused->toArray()['parent']);
        self::assertSame('d-0', DecisionRecord::fromJson($caused->json())->parent);
        self::assertNotSame($record->hash(), $caused->hash(), 'The parent is hashed.');

        // What moved while it judged is hashed too (D-44).
        $settled = DecisionRecord::of($request, Decision::deny('No permit applies: ana@example.com isn\'t an editor.', $decision->reasons, $decision->remedies)->identified('d-1', $request->at, new ReadSet(['relations' => 2, 'policy' => 7])));
        self::assertNotSame($record->hash(), $settled->hash(), 'What moved is hashed.');
        self::assertSame(['relations'], DecisionRecord::fromJson($record->json())->moved);
    }

    /**
     * A record written in format 3, before the read set was recorded in full, still verifies: it's read,
     * and hashed, exactly as it was written.
     */
    public function testAFormatThreeRecordStillVerifies(): void
    {
        $json = '{"format":3,"id":"d-3","realm":"acme","decided_at":"2026-11-20T18:00:00.000000Z","kind":"operation","name":"acme.badge.issue","actor":"019a6e2c-8a00-7000-8000-000000000001","resource":null,"verdict":"permit","basis":"ability","reason":"Permitted.","indeterminate":[],"remedies":[],"elements":[["yorvoid.policy.roles","permit","holds"],["authority","authority","holds"]],"versions":{"policy":7,"relations":2},"origin":["web","r"],"viewer":null,"details":[],"read":{"sources":{"policy":7,"relations":2}},"parent":"d-2"}';
        $record = DecisionRecord::fromJson($json);

        self::assertSame([3, 'd-2', [], [], null], [$record->format, $record->parent, $record->moved, $record->attributes, $record->delegation]);
        self::assertSame($json, $record->json(), 'Written back exactly as it was.');
        self::assertSame(hash('sha256', $json), $record->hash(), 'So its hash, which its digest and citations hold, is the same.');
    }

    /**
     * Format 4's read set is read as written, and refused when it isn't one.
     */
    public function testAFormatFourReadSetIsReadAsWritten(): void
    {
        $record = new DecisionRecord('d-4', new Realm('acme'), new \DateTimeImmutable('2026-11-23 18:00:00', new \DateTimeZone('UTC')), 'ability', 'acme.pages.edit', null, null, 'deny', null, 'No.', [], [], [], ['policy' => 1], ['web', 'r'], moved: ['policy'], attributes: ['b' => '2', 'a' => '1'], delegation: ['links' => ['x'], 'root' => 'p']);
        $read = DecisionRecord::fromJson($record->json());

        self::assertSame([['policy'], ['a' => '1', 'b' => '2'], ['links' => ['x'], 'root' => 'p']], [$read->moved, $read->attributes, $read->delegation], 'The attributes sorted by name.');
        self::assertSame($record->hash(), $read->hash());
        self::assertSame([], DecisionRecord::fromJson((new DecisionRecord('d-5', new Realm('acme'), new \DateTimeImmutable('2026-11-23 18:00:00', new \DateTimeZone('UTC')), 'ability', 'n', null, null, 'deny', null, 'No.', [], [], [], [], ['web', 'r'], delegation: []))->json())->delegation, 'An empty chain stays empty, not none.');
    }

    /**
     * A record written before format 3 reads, and hashes as it was written: without a parent.
     */
    public function testAFormatTwoRecordStillReadsAndHashesAsWritten(): void
    {
        $two = new DecisionRecord('d-2', new Realm('acme'), new \DateTimeImmutable('2026-11-18 18:00:00', new \DateTimeZone('UTC')), 'attempt', 'yorvoid.code.check', null, null, 'failed', null, '', [], [], [], [], ['web', 'r'], null, ['tries' => 1], format: 2);
        $json = $two->json();

        self::assertArrayNotHasKey('parent', $two->toArray());
        $read = DecisionRecord::fromJson($json);
        self::assertSame([2, null, ['tries' => 1]], [$read->format, $read->parent, $read->details]);
        self::assertSame($two->hash(), $read->hash());
    }

    /**
     * A record written before 0.5 (format 1) reads, and hashes as it was written: over exactly what it
     * held, so the chain's citations of it still match (D-42).
     */
    public function testAFormatOneRecordStillReadsAndHashesAsWritten(): void
    {
        $json = json_encode(['format' => 1, 'id' => 'd-1', 'realm' => 'acme', 'decided_at' => '2026-11-08T18:00:00.000000Z', 'kind' => 'operation', 'name' => 'acme.badge.issue', 'actor' => null, 'resource' => null, 'verdict' => 'deny', 'basis' => null, 'reason' => 'No.', 'indeterminate' => [], 'remedies' => [], 'elements' => [], 'versions' => ['policy' => 1], 'origin' => ['web', 'r'], 'viewer' => null], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $record = DecisionRecord::fromJson($json);

        self::assertSame(1, $record->format);
        self::assertSame($json, $record->json());
        self::assertSame(hash('sha256', $json), $record->hash());
    }

    /**
     * A failed attempt (D-42): its outcome as the verdict, its subject as the resource, its details kept
     * and read back.
     */
    public function testAnAttemptKeepsItsDetails(): void
    {
        $record = DecisionRecord::attempt('a-1', new Realm('acme'), new \DateTimeImmutable('2026-11-17 18:00:00 UTC'), 'yorvoid.recovery_code.use', 'failed', null, 'credential', 'c-1', new Origin(OriginKind::Web, correlationId: 'r'), ['principal' => 'p-1']);

        self::assertSame(['attempt', 'yorvoid.recovery_code.use', 'failed', ['credential', 'c-1'], ['principal' => 'p-1']], [$record->kind, $record->name, $record->verdict, $record->toArray()['resource'], $record->details]);
        self::assertSame($record->json(), DecisionRecord::fromJson($record->json())->json());
        self::assertNull(DecisionRecord::attempt('a-2', new Realm('acme'), new \DateTimeImmutable(), 'yorvoid.setup.claim', 'failed', null, null, null, new Origin(OriginKind::Web), [])->resource);
    }

    public function testAnOperationsRecordNamesItAndItsBasis(): void
    {
        $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Operator], null, null, IssueBadge::class, []);
        $request = Request::forOperation($operation, new IssueBadge(new Realm('acme'), new PrincipalId(Ids::next())), new Operator(), new Origin(OriginKind::Console, correlationId: 'job-1'), new \DateTimeImmutable('2026-11-08 18:00:00 UTC'));
        $unknown = Decision::indeterminate([IndeterminateReason::StoreUnreachable], 'Can\'t tell.')->identified('d-2', $request->at, new ReadSet());
        $permit = \YorvoID\Tests\Support\Decisions::identified(\YorvoID\Tests\Support\Decisions::permit(AuthorizedBy::Operator, 'The operator.'), 'd-3', $request->at);

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
        yield 'another format' => [$with(['format' => 5]), 'A stored decision has an unknown format.'];
        yield 'format 4 without its read set' => [$with(['format' => 4, 'details' => []]), 'A stored decision\'s read set is malformed.'];
        yield 'format 4 with attributes that aren\'t text' => [$with(['format' => 4, 'details' => [], 'read' => ['sources' => [], 'moved' => [], 'attributes' => ['a' => 1], 'delegation' => null]]), 'A stored decision\'s attributes are malformed.'];
        yield 'format 4 with a chain that isn\'t a map' => [$with(['format' => 4, 'details' => [], 'read' => ['sources' => [], 'moved' => [], 'attributes' => [], 'delegation' => 'x']]), 'A stored decision\'s read set is malformed.'];
        yield 'format 4 with a chain keyed by number' => [$with(['format' => 4, 'details' => [], 'read' => ['sources' => [], 'moved' => [], 'attributes' => [], 'delegation' => ['x']]]), 'A stored decision\'s delegation is malformed.'];
        yield 'format 4 with moved sources that aren\'t a list' => [$with(['format' => 4, 'details' => [], 'read' => ['sources' => [], 'moved' => 'policy', 'attributes' => [], 'delegation' => null]]), 'A stored decision\'s "moved" isn\'t a list.'];
        yield 'an attempt in format 1' => [$with(['kind' => 'attempt']), 'A stored decision is malformed.'];
        yield 'details that are no object' => [$with(['format' => 2, 'details' => 'a']), 'A stored decision\'s details are malformed.'];
        yield 'details that are a list' => [$with(['format' => 2, 'details' => ['a']]), 'A stored decision\'s details are malformed.'];
        yield 'details with a number for a name' => [$with(['format' => 2, 'details' => ['a' => 1, 7 => 'b']]), 'A stored decision\'s details are malformed.'];
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

        self::assertStringContainsString('["page","ünï/1"]', DecisionRecord::of($request, Decision::deny('No.')->identified('d-1', $request->at, new ReadSet()))->json(), 'Neither slashes nor letters are escaped.');
    }

    public function testADecisionWithoutItsTimeIsntRecorded(): void
    {
        $request = Request::forAbility('acme.pages.edit', new Realm('acme'), null, new Operator(), new Origin(OriginKind::Web), new \DateTimeImmutable());

        $this->expectException(\YorvoID\Core\Exception\IntegratorError::class);

        DecisionRecord::of($request, \YorvoID\Tests\Support\Decisions::of(\YorvoID\Core\Operation\Verdict::Deny, null, 'No.', id: 'd-1'));
    }
}
