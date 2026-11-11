<?php

declare(strict_types=1);

namespace YorvoID\E2e;

use YorvoID\IntentVerifier\CollectingNotifier;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\Operation;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\ParameterType;
use YorvoID\IntentVerifier\SigningPages;
use YorvoID\IntentVerifier\Storage\FileVerifierLog;
use YorvoID\IntentVerifier\Storage\FileVerifierStore;
use YorvoID\IntentVerifier\VerifiedIntent;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\IntentVerifier\VerifierConfig;

/**
 * The privileged side, as a host would run it: the verifier, and its signing pages at their own origin,
 * sign.localhost. The operation it runs writes what it did to a file, for the tests to read.
 */
final class Signing
{
    public const string ORIGIN = 'http://sign.localhost:8000';

    public static function config(): VerifierConfig
    {
        return new VerifierConfig('e2e-verifier', 'sign.localhost', [self::ORIGIN], [Kernel::origin()]);
    }

    public static function verifier(): Verifier
    {
        return new Verifier(
            self::config(),
            new OperationPolicy([new Operation('acme.site.remove', ['site' => ParameterType::String])]),
            new FileVerifierStore(Kernel::data().'/verifier.json'),
            new FileVerifierLog(Kernel::data().'/verifier.jsonl'),
            new CollectingNotifier(),
        );
    }

    public static function pages(): SigningPages
    {
        return new SigningPages(
            self::verifier(),
            static function (VerifiedIntent $verified): void {
                file_put_contents(self::ran(), \sprintf('Ran %s: site=%s', $verified->intent->action, json_encode($verified->intent->parameters['site'] ?? null)));
            },
            Kernel::origin().'/signed',
            static fn (Intent $intent): ?string => 'acme.site.remove' === $intent->action && \is_string($intent->parameters['site'] ?? null) ? \sprintf('Remove the site %s', $intent->parameters['site']) : null,
        );
    }

    /**
     * Where the operation writes what it did.
     */
    public static function ran(): string
    {
        return Kernel::data().'/ran.txt';
    }
}
