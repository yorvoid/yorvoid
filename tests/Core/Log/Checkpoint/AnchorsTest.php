<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Log\Checkpoint;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use YorvoID\Core\Crypto\CryptoPolicy;
use YorvoID\Core\Crypto\CryptoProvider;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Key\KeyAlgorithm;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\KeyStatus;
use YorvoID\Core\Key\SharedKeyRings;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Log\Checkpoint\Checkpoint;
use YorvoID\Core\Log\Checkpoint\Checkpoints;
use YorvoID\Core\Log\Checkpoint\CheckpointSink;
use YorvoID\Core\Log\Event\AuditFailed;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Tests\Support\RecordingDispatcher;

/**
 * Anchors (AUD-003, CRY-01): moving from Ed25519 to ES256 keys, an anchor signed only once everything
 * before it verifies covers the Ed25519 checkpoints that a FIPS provider without EdDSA can't check. A
 * plain checkpoint never does: with a shared ring, cron's first ES256 checkpoint of a realm would
 * otherwise certify a history nobody checked.
 */
final class AnchorsTest extends TestCase
{
    private Realm $realm;

    private InMemorySecurityLogStore $store;

    private SecurityLog $log;

    private MockClock $clock;

    /** @var list<Checkpoint> */
    private array $kept = [];

    protected function setUp(): void
    {
        $this->realm = new Realm('acme');
        $this->store = new InMemorySecurityLogStore();
        $this->log = new SecurityLog($this->store, new InMemoryTransactions());
        $this->clock = new MockClock('2026-11-10 18:00:00 UTC');
    }

    #[Group('ed25519-keys')]
    public function testOnlyAnAnchorCoversEd25519CheckpointsUnderAProviderWithoutEdDsa(): void
    {
        $old = SigningKey::generate('2026-11-01');
        $this->record(2);
        $this->checkpoints(new KeyRing([$old]))->take($this->realm);
        $this->record(1);
        $this->checkpoints(new KeyRing([$old]))->take($this->realm);

        // Rotated: the ES256 key signs, the Ed25519 one only verifies.
        $ring = new KeyRing([$old, SigningKey::generate('2026-11-10', KeyStatus::VerifyOnly, KeyAlgorithm::ES256)])->activating('2026-11-10');
        $this->record(1);
        $this->checkpoints($ring)->take($this->realm);

        self::assertTrue($this->checkpoints($ring)->verify($this->realm, $this->kept)->isIntact(), 'In the standard mode, every checkpoint is checked.');
        self::assertSame(3, $this->checkpoints($ring)->unanchored($this->realm, $this->kept), 'The Ed25519 checkpoints, up to entry 3, have no anchor.');

        $fips = $this->checkpoints($ring, self::withoutEdDsa())->verify($this->realm, $this->kept);
        self::assertFalse($fips->isIntact(), 'A plain ES256 checkpoint doesn\'t cover them.');
        self::assertSame(3, $fips->brokenAt);
        self::assertSame(2, $fips->uncheckable);
        self::assertStringContainsString('Checkpoints up to entry 3 are signed with keys this OpenSSL can\'t verify, and no anchor after them covers them: verify in the standard crypto mode, or anchor the realm there (yorvoid:log:anchor --realm acme).', (string) $fips->problem);

        try {
            $this->checkpoints($ring, self::withoutEdDsa())->anchor($this->realm, $this->kept);
            self::fail('It anchored what it couldn\'t check.');
        } catch (IntegratorError $e) {
            self::assertSame('The realm "acme" can\'t be anchored: 2 of its checkpoints are signed with keys this OpenSSL can\'t verify.', $e->problem);
            self::assertSame('Anchor in the standard crypto mode, where every checkpoint can be checked.', $e->fix);
        }

        $anchor = $this->checkpoints($ring)->anchor($this->realm, $this->kept);
        self::assertTrue(null !== $anchor && $anchor->anchor && 4 === $anchor->sequence && '2026-11-10' === $anchor->keyId);
        self::assertNull($this->checkpoints($ring)->unanchored($this->realm, $this->kept));

        $covered = $this->checkpoints($ring, self::withoutEdDsa())->verify($this->realm, $this->kept);
        self::assertTrue($covered->isIntact(), (string) $covered->problem);
        self::assertSame([4, 2], [$covered->entriesChecked, $covered->uncheckable]);

        // An Ed25519 checkpoint after the anchor is uncovered again.
        $this->record(1);
        $this->checkpoints(new KeyRing([new SigningKey($old->id, KeyStatus::Active, $old->publicKey, $old->seed)]))->take($this->realm);
        self::assertFalse($this->checkpoints($ring, self::withoutEdDsa())->verify($this->realm, $this->kept)->isIntact());
        self::assertSame(5, $this->checkpoints($ring)->unanchored($this->realm, $this->kept));
    }

    #[Group('ed25519-keys')]
    public function testAnAnchorCoversOnlyWhatsAtOrBeforeIt(): void
    {
        $old = SigningKey::generate('old');
        $ring = new KeyRing([$old, SigningKey::generate('new', KeyStatus::VerifyOnly, KeyAlgorithm::ES256)])->activating('new');
        $this->record(3);
        $head = $this->store->head($this->realm);
        \assert(null !== $head);
        $at = $this->clock->now();
        $signer = new SigningKey('old', KeyStatus::Active, $old->publicKey, $old->seed);
        $entries = iterator_to_array($this->store->entries($this->realm, 1), false);

        // Out of order: an Ed25519 checkpoint of entry 3, then one of entry 2, then an anchor of entry 2.
        foreach ([3, 2] as $sequence) {
            $this->kept[] = new Checkpoint($this->realm, $sequence, $entries[$sequence - 1]->hash, $at, 'old', $signer->sign(Checkpoint::message($this->realm, $sequence, $entries[$sequence - 1]->hash, $at)));
        }

        $this->kept[] = new Checkpoint($this->realm, 2, $entries[1]->hash, $at, 'new', $ring->signer()->sign(Checkpoint::message($this->realm, 2, $entries[1]->hash, $at, true)), true);

        self::assertSame(3, $this->checkpoints($ring)->unanchored($this->realm, $this->kept));
        self::assertSame(3, $this->checkpoints($ring, self::withoutEdDsa())->verify($this->realm, $this->kept)->brokenAt, 'Entry 3\'s checkpoint is after the anchor.');
        self::assertSame(3, $head['sequence']);
    }

    #[Group('ed25519-keys')]
    public function testAnAnchorIsntSignedOverALogThatDoesntVerify(): void
    {
        $ring = new KeyRing([SigningKey::generate('k')]);
        $this->record(2);
        $taken = $this->checkpoints($ring)->take($this->realm);
        \assert(null !== $taken);
        $forged = new Checkpoint($this->realm, 2, str_repeat('0', 64), $taken->takenAt, 'k', $ring->signer()->sign(Checkpoint::message($this->realm, 2, str_repeat('0', 64), $taken->takenAt)));

        try {
            $this->checkpoints($ring)->anchor($this->realm, [$forged]);
            self::fail('It anchored a rewritten log.');
        } catch (IntegratorError $e) {
            self::assertStringStartsWith('The realm "acme" can\'t be anchored: Entry 2 isn\'t what was checkpointed at', $e->problem);
            self::assertSame('Find out why the log doesn\'t verify before anchoring it: an anchor would certify it as it is.', $e->fix);
        }

        self::assertCount(1, $this->kept, 'Nothing was signed.');
    }

    public function testAnAnchorsSignatureIsItsOwn(): void
    {
        $ring = new KeyRing([SigningKey::generate('es', algorithm: KeyAlgorithm::ES256)]);
        $this->record(1);
        $anchor = $this->checkpoints($ring)->anchor($this->realm, []);
        $checkpoint = $this->checkpoints($ring)->take($this->realm);
        \assert(null !== $anchor && null !== $checkpoint);

        self::assertTrue($anchor->isSignedBy($ring) && $checkpoint->isSignedBy($ring));
        self::assertFalse(new Checkpoint($anchor->realm, $anchor->sequence, $anchor->hash, $anchor->takenAt, $anchor->keyId, $anchor->signature)->isSignedBy($ring), 'An anchor isn\'t a checkpoint.');
        self::assertFalse(new Checkpoint($checkpoint->realm, $checkpoint->sequence, $checkpoint->hash, $checkpoint->takenAt, $checkpoint->keyId, $checkpoint->signature, true)->isSignedBy($ring), 'Nor is a checkpoint an anchor.');

        self::assertArrayNotHasKey('anchor', $checkpoint->toArray(), 'A checkpoint is kept as it always was.');
        self::assertTrue($anchor->toArray()['anchor'] ?? false);
        self::assertEquals($anchor, Checkpoint::fromArray($anchor->toArray()));
        self::assertEquals($checkpoint, Checkpoint::fromArray($checkpoint->toArray()));
        self::assertNull(Checkpoint::fromArray(['anchor' => 'yes'] + $anchor->toArray()));
    }

    public function testEs256KeysCheckpointAndAnchorUnderAProviderWithoutEdDsa(): void
    {
        $ring = new KeyRing([SigningKey::generate('es', algorithm: KeyAlgorithm::ES256)]);
        $fips = $this->checkpoints($ring, self::withoutEdDsa());
        $this->record(2);

        self::assertNull($fips->anchor(new Realm('empty'), []), 'An empty log has nothing to anchor.');
        $fips->take($this->realm);
        $fips->anchor($this->realm, $this->kept);
        $this->record(1);
        $fips->take($this->realm);

        $verification = $fips->verify($this->realm, $this->kept);
        self::assertTrue($verification->isIntact(), (string) $verification->problem);
        self::assertSame(0, $verification->uncheckable);
        self::assertNull($fips->unanchored($this->realm, $this->kept), 'No Ed25519 checkpoint to cover.');
    }

    /**
     * As a FIPS deployment has it after moving to ES256: the old Ed25519 key, verify-only, next to the
     * active ES256 one. The old checkpoints are signed with libsodium, so that this runs under the FIPS
     * provider too, where OpenSSL has no Ed25519.
     */
    public function testAnAnchorCoversWhatTheProviderCantCheck(): void
    {
        $seed = random_bytes(32);
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $old = new SigningKey('old', KeyStatus::VerifyOnly, sodium_crypto_sign_publickey($pair), Secret::fromString($seed));
        $ring = new KeyRing([$old, SigningKey::generate('new', algorithm: KeyAlgorithm::ES256)]);
        // A FIPS provider may have EdDSA (FIPS 186-5), as Red Hat's does; this is about one without it.
        $fips = self::fips() && !CryptoPolicy::fips()->allowsEdDsa();
        $crypto = $fips ? CryptoPolicy::fips() : self::withoutEdDsa();
        self::assertFalse($crypto->allowsEdDsa());

        $this->record(2);
        $head = $this->store->head($this->realm);
        \assert(null !== $head);
        $at = $this->clock->now();
        $this->kept[] = new Checkpoint($this->realm, $head['sequence'], $head['hash'], $at, 'old', sodium_crypto_sign_detached(Checkpoint::message($this->realm, $head['sequence'], $head['hash'], $at), sodium_crypto_sign_secretkey($pair)));

        self::assertFalse($this->checkpoints($ring, $crypto)->verify($this->realm, $this->kept)->isIntact(), 'Nothing covers the old checkpoint yet.');

        // Anchored where it could be checked (the standard mode), then verified under the provider.
        $this->checkpoints($ring, $fips ? null : CryptoPolicy::standard())->anchor($this->realm, $fips ? [] : $this->kept);
        $this->record(1);
        $this->checkpoints($ring, $crypto)->take($this->realm);

        $verification = $this->checkpoints($ring, $crypto)->verify($this->realm, $this->kept);
        self::assertTrue($verification->isIntact(), (string) $verification->problem);
        self::assertSame([3, 1], [$verification->entriesChecked, $verification->uncheckable]);
    }

    public function testACheckpointThatCantBeSignedOrKeptRaisesTheAlert(): void
    {
        $events = new RecordingDispatcher();
        $failing = new class implements CheckpointSink {
            public function write(Checkpoint $checkpoint): void
            {
                throw new \RuntimeException('The disk is full.');
            }
        };
        $this->record(1);
        $checkpoints = new Checkpoints($this->store, $this->log, new SharedKeyRings(new KeyRing([SigningKey::generate('es', algorithm: KeyAlgorithm::ES256)])), [$failing], $this->clock, events: $events);

        foreach ([static fn () => $checkpoints->take(new Realm('acme')), static fn () => $checkpoints->anchor(new Realm('acme'), [])] as $attempt) {
            try {
                $attempt();
                self::fail('It went on.');
            } catch (\RuntimeException $e) {
                self::assertSame('The disk is full.', $e->getMessage());
            }
        }

        self::assertSame([[AuditFailed::CHECKPOINTS, 'acme'], [AuditFailed::CHECKPOINTS, 'acme']], array_map(static fn (AuditFailed $alert): array => [$alert->log, $alert->realm?->name], $events->of(AuditFailed::class)));
    }

    private function checkpoints(KeyRing $ring, ?CryptoPolicy $crypto = null): Checkpoints
    {
        $sink = new readonly class(function (Checkpoint $checkpoint): void {
            $this->kept[] = $checkpoint;
        }) implements CheckpointSink {
            /**
             * @param \Closure(Checkpoint): void $keep
             */
            public function __construct(
                private \Closure $keep,
            ) {
            }

            public function write(Checkpoint $checkpoint): void
            {
                ($this->keep)($checkpoint);
            }
        };

        return new Checkpoints($this->store, $this->log, new SharedKeyRings($ring), [$sink], $this->clock, $crypto);
    }

    private function record(int $entries): void
    {
        for ($i = 0; $i < $entries; ++$i) {
            $this->log->record($this->realm, 'yorvoid.principal.create', Outcome::Success, new Origin(OriginKind::Console));
        }
    }

    /**
     * Whether the tests run in the FIPS-configured image (docker/fips), where OpenSSL can't check Ed25519.
     */
    private static function fips(): bool
    {
        return '1' === getenv('YORVOID_TEST_FIPS');
    }

    /**
     * The FIPS mode, under a provider without EdDSA, as OpenSSL 3.1.2's.
     */
    private static function withoutEdDsa(): CryptoPolicy
    {
        return CryptoPolicy::fips(new CryptoProvider('OpenSSL 3.5.7', true, true, false, null));
    }
}
