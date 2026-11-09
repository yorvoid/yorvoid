<?php

declare(strict_types=1);

namespace YorvoID\Bench;

use YorvoID\IntentVerifier\CollectingNotifier;
use YorvoID\IntentVerifier\Operation;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\ParameterType;
use YorvoID\IntentVerifier\Storage\FileVerifierLog;
use YorvoID\IntentVerifier\Storage\FileVerifierStore;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\IntentVerifier\VerifierConfig;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * A signed intent checked where the operation runs (Verifier::verify()), on the verifier's own files:
 * its nonce taken, its assertion checked, its log appended. Intents are prepared and signed ten at a
 * time before the iterations that verify them, so only the verifying is timed (and taking the next).
 */
// Five verifications an iteration: the verifier's files make single ones vary too much to compare.
#[Measure(revolutions: 5)]
final class VerifierBench
{
    private static ?Verifier $shared = null;

    private static ?VirtualAuthenticator $owner = null;

    /** @var list<array{string, string}> Nonces and their assertions, ready */
    private static array $signed = [];

    public function setUp(): void
    {
        $verifier = self::$shared ??= self::verifier();

        // Enough for the warm-up and the iteration's revolutions.
        if (\count(self::$signed) < 10) {
            for ($i = 0; $i < 20; ++$i) {
                $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
                self::$signed[] = [$prepared->intent->nonce, self::$owner?->signIn($prepared->options) ?? throw new \LogicException()];
            }
        }
    }

    public function benchVerify(): void
    {
        [$nonce, $assertion] = array_shift(self::$signed) ?? throw new \LogicException('Nothing signed.');
        self::$shared?->verify($nonce, $assertion);
    }

    private static function verifier(): Verifier
    {
        $directory = \sprintf('%s/yorvoid-bench-verifier-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        mkdir($directory);
        $verifier = new Verifier(
            new VerifierConfig('acme-verifier:host-1', 'sign.acme.example', ['https://sign.acme.example'], ['https://acme.example']),
            new OperationPolicy([new Operation('acme.site.remove', ['site' => ParameterType::String])]),
            new FileVerifierStore($directory.'/state.json'),
            new FileVerifierLog($directory.'/log.jsonl'),
            new CollectingNotifier(),
        );
        self::$owner = new VirtualAuthenticator('https://sign.acme.example');
        $secret = $verifier->createBootstrapSecret();
        $registration = $verifier->prepareRegistration('Passkey', true);
        $prepared = $verifier->finishRegistration($registration->id, self::$owner->register($registration->options));
        $verifier->bootstrap($secret, $prepared->intent->nonce, self::$owner->signIn($prepared->options));

        return $verifier;
    }
}
