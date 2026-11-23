<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\DecisionLog;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Authorization\AttributeMacs;
use YorvoID\Core\Authorization\Confirmation;
use YorvoID\Core\Authorization\DecisionLog\DecisionConfirmations;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Key\KeyAlgorithm;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\MacKey;
use YorvoID\Core\Key\SharedKeyRings;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;

/**
 * Confirming what a decision read (M-9, K4): every confirmation is logged, from code too, with the
 * record, the attribute and the result, and never the value.
 */
final class DecisionConfirmationsTest extends TestCase
{
    public function testEachConfirmationIsLoggedWithoutTheValue(): void
    {
        $realm = new Realm('acme');
        $macs = new AttributeMacs(new SharedKeyRings(new KeyRing([SigningKey::generate('s-1', algorithm: KeyAlgorithm::ES256)], [MacKey::generate('m-1')])));
        $record = new DecisionRecord('d-1', $realm, new \DateTimeImmutable('2026-11-23 18:00:00', new \DateTimeZone('UTC')), 'ability', 'acme.pages.edit', null, null, 'deny', null, 'No.', [], [], [], ['policy' => 1], ['web', 'r'], moved: [], attributes: ['acme.department' => $macs->record($realm, 'acme.department', 'finance', false), 'acme.flagged' => 'v:true']);
        $store = new InMemorySecurityLogStore();
        $transactions = new InMemoryTransactions();
        $transactions->register($store);
        $confirmations = new DecisionConfirmations($macs, new SecurityLog($store, $transactions), $transactions);
        $origin = new Origin(OriginKind::Console, 'review');

        self::assertSame(Confirmation::Matches, $confirmations->confirm($record, 'acme.department', 'finance', new Operator(), $origin));
        self::assertSame(Confirmation::Differs, $confirmations->confirm($record, 'acme.department', 'sales', new Operator(), $origin));
        self::assertSame(Confirmation::Matches, $confirmations->confirm($record, 'acme.flagged', true, new Operator(), $origin));
        self::assertSame(Confirmation::NotRecorded, $confirmations->confirm($record, 'acme.team', 'x', new Operator(), $origin));

        $entries = iterator_to_array($store->entries($realm, 1), false);
        self::assertSame(['matches', 'differs', 'matches', 'not_recorded'], array_map(static fn (LogEntry $entry): mixed => $entry->details['result'] ?? null, $entries));

        foreach ($entries as $entry) {
            self::assertSame(['yorvoid.decision.confirm', 'decision', 'd-1'], [$entry->action, $entry->subjectType, $entry->subjectId]);
            self::assertSame(['attribute', 'result'], array_keys($entry->details), 'Nothing else, the value least of all.');
            self::assertStringNotContainsString('finance', json_encode($entry->details, \JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('sales', json_encode($entry->details, \JSON_THROW_ON_ERROR));
        }
    }
}
