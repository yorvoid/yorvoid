<?php

declare(strict_types=1);

namespace YorvoID\Testing\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\IntentVerifier\VerifierLog;

/**
 * What every VerifierLog keeps (INT-010): a nonce counts as used by any entry with it but its
 * "prepared" one, at or after the time asked for, also for another instance over the same log. Entries
 * before that time may be ignored, so the contract doesn't ask about them.
 *
 * ```php
 * final class MyVerifierLogTest extends TestCase
 * {
 *     use VerifierLogContract;
 *
 *     public static function implementations(): iterable
 *     {
 *         yield 'mine' => ['mine'];
 *     }
 *
 *     protected function verifierLog(string $implementation): VerifierLog
 *     {
 *         return new MyVerifierLog(...); // empty, new for each test
 *     }
 * }
 * ```
 *
 * Needs yorvoid/intent-verifier, which yorvoid/testing suggests.
 *
 * @api
 */
trait VerifierLogContract
{
    /**
     * The log to check, empty: a new one for each test.
     */
    abstract protected function verifierLog(string $implementation): VerifierLog;

    /**
     * Another instance over the same log, as after a restart: the same one unless overridden.
     */
    protected function sameVerifierLog(string $implementation, VerifierLog $log): VerifierLog
    {
        return $log;
    }

    #[DataProvider('implementations')]
    public function testVerifierLogTellsANonceUsedSinceATime(string $implementation): void
    {
        $log = $this->verifierLog($implementation);
        $log->record('prepared', ['nonce' => 'a'], new \DateTimeImmutable('@1793210400'));
        $log->record('verified', ['nonce' => 'a'], new \DateTimeImmutable('@1793210460'));
        $log->record('prepared', ['nonce' => 'b'], new \DateTimeImmutable('@1793210460'));
        $log->record('refused', ['nonce' => 'c'], new \DateTimeImmutable('@1793210500'));

        foreach ([$log, $this->sameVerifierLog($implementation, $log)] as $instance) {
            self::assertTrue($instance->usedNonce('a', new \DateTimeImmutable('@1793210400')));
            self::assertTrue($instance->usedNonce('a', new \DateTimeImmutable('@1793210460')), 'An entry at the time asked for didn\'t count.');
            self::assertTrue($instance->usedNonce('c', new \DateTimeImmutable('@1793210400')), 'A refusal with the nonce didn\'t count.');
            self::assertFalse($instance->usedNonce('b', new \DateTimeImmutable('@1793210400')), 'Preparing a nonce used it.');
            self::assertFalse($instance->usedNonce('d', new \DateTimeImmutable('@1793210400')), 'A nonce nobody used counted.');
        }
    }
}
