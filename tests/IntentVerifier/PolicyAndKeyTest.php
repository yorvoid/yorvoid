<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\IntentVerifier\Base64Url;
use YorvoID\IntentVerifier\CanonicalJson;
use YorvoID\IntentVerifier\ConfigurationError;
use YorvoID\IntentVerifier\Key;
use YorvoID\IntentVerifier\Operation;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\ParameterType;
use YorvoID\IntentVerifier\VerifierConfig;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * The operation policy (INT-008), the verifier's configuration, and keys (INT-015).
 */
final class PolicyAndKeyTest extends TestCase
{
    public function testThePolicyHoldsTheVerifiersOwnOperations(): void
    {
        $policy = new OperationPolicy([new Operation('acme.site.remove', ['site' => ParameterType::String])]);

        self::assertSame([OperationPolicy::ADD_KEY, OperationPolicy::REMOVE_KEY, OperationPolicy::CANCEL_KEY_CHANGE, 'acme.site.remove'], array_keys($policy->operations));
        self::assertSame(['credential_id', 'public_key', 'user_handle', 'label'], array_keys($policy->get(OperationPolicy::ADD_KEY)->parameters));
        self::assertSame(['credential_id'], array_keys($policy->get(OperationPolicy::REMOVE_KEY)->parameters));
        self::assertTrue($policy->get(OperationPolicy::ADD_KEY)->signed);
    }

    /**
     * @return iterable<string, array{\Closure(): mixed, string}>
     */
    public static function mistakes(): iterable
    {
        yield 'the verifier\'s own name' => [static fn () => new OperationPolicy([new Operation('yorvoid.verifier.reset')]), 'The operation yorvoid.verifier.reset is the verifier\'s own'];
        yield 'twice' => [static fn () => new OperationPolicy([new Operation('acme.site.remove'), new Operation('acme.site.remove')]), 'The operation acme.site.remove is listed twice.'];
        yield 'a name of two segments' => [static fn () => new Operation('acme.remove'), 'The operation "acme.remove" is invalid'];
        yield 'a name too long' => [static fn () => new Operation('acme.'.str_repeat('a', 185).'.x'), 'is invalid'];
        yield 'a parameter name' => [static fn () => new Operation('acme.site.remove', ['Site' => ParameterType::String]), 'The parameter "Site" of acme.site.remove is invalid'];
        yield 'a parameter name with a hyphen' => [static fn () => new Operation('acme.site.remove', ['site-name' => ParameterType::String]), 'The parameter "site-name" of acme.site.remove is invalid'];
        yield 'a parameter name with a line break' => [static fn () => new Operation('acme.site.remove', ["site\n" => ParameterType::String]), 'of acme.site.remove is invalid'];
        $console = ['https://acme.example'];
        yield 'no audience' => [static fn () => new VerifierConfig(' ', 'sign.acme.example', ['https://sign.acme.example'], $console), 'The verifier needs an audience'];
        yield 'no origin' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', [], $console), 'at least one signing origin and one console origin'];
        yield 'no console origin' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['https://sign.acme.example'], []), 'at least one signing origin and one console origin'];
        yield 'too short a lifetime' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['https://sign.acme.example'], $console, 9), 'an intent lifetime between 10 seconds and an hour'];
        yield 'too long a lifetime' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['https://sign.acme.example'], $console, 3601), 'an intent lifetime between 10 seconds and an hour'];
        yield 'plain http' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['http://sign.acme.example'], $console), 'The origin "http://sign.acme.example" isn\'t an https:// origin at or under sign.acme.example, with nothing after the host and port.'];
        yield 'another site' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['https://evilsign.acme.example'], $console), 'isn\'t an https:// origin at or under sign.acme.example'];
        yield 'not an origin' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['sign.acme.example'], $console), 'isn\'t an https:// origin'];
        yield 'a console origin that isn\'t one' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['https://sign.acme.example'], ['acme.example']), 'The console origin "acme.example" isn\'t an https:// origin, with nothing after the host and port.'];
        yield 'an origin with a path' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['https://sign.acme.example/sign'], $console), 'with nothing after the host and port'];
        yield 'an origin with userinfo' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['https://ana@sign.acme.example'], $console), 'with nothing after the host and port'];
        yield 'an origin with a query' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['https://sign.acme.example?x'], $console), 'with nothing after the host and port'];
        yield 'an origin in capitals' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['HTTPS://sign.acme.example'], $console), 'with nothing after the host and port'];
        yield 'signing at the console\'s own relying party' => [static fn () => new VerifierConfig('acme', 'acme.example', ['https://acme.example'], $console), 'The console at "https://acme.example" could ask for signatures for acme.example itself'];
        yield 'a console under the signing relying party' => [static fn () => new VerifierConfig('acme', 'acme.example', ['https://sign.acme.example'], ['https://console.acme.example']), 'could ask for signatures for acme.example itself'];
        yield 'a console at a signing origin' => [static fn () => new VerifierConfig('acme', 'sign.acme.example', ['https://sign.acme.example'], ['https://sign.acme.example:8443']), 'could ask for signatures'];
    }

    /**
     * @param \Closure(): mixed $mistake
     */
    #[DataProvider('mistakes')]
    public function testRefusesMistakes(\Closure $mistake, string $message): void
    {
        $this->expectException(ConfigurationError::class);
        $this->expectExceptionMessage($message);

        $mistake();
    }

    public function testAcceptsTheLongestOperationName(): void
    {
        $name = 'acme.'.str_repeat('a', 184).'.x';
        self::assertSame(191, \strlen($name));
        self::assertSame($name, new Operation($name)->action);
    }

    public function testAcceptsOriginsUnderTheRelyingPartyAndLocalDevelopment(): void
    {
        self::assertCount(3, new VerifierConfig('acme', 'sign.acme.example', ['https://sign.acme.example', 'https://eu.sign.acme.example:8443', 'https://sign.acme.example'], ['https://acme.example'])->origins);
        // The console may be the relying party's parent: a parent's pages can't ask for its subdomain's passkeys.
        self::assertSame(['https://acme.example', 'https://console.acme.example'], new VerifierConfig('acme', 'sign.acme.example', ['https://sign.acme.example'], ['https://acme.example', 'https://console.acme.example'])->consoleOrigins);
        self::assertSame(['http://sign.localhost:8001'], new VerifierConfig('acme', 'sign.localhost', ['http://sign.localhost:8001'], ['http://localhost:8000'], 10)->origins);
        self::assertSame(3600, new VerifierConfig('acme', 'sign.acme.example', ['https://sign.acme.example'], ['https://acme.example'], 3600)->intentLifetime);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function parameters(): iterable
    {
        yield 'exactly' => [['site' => 'jane', 'days' => 30, 'force' => false], true];
        yield 'in another order' => [['force' => true, 'site' => 'jane', 'days' => -1], true];
        yield 'the largest exact integers' => [['site' => 'jane', 'days' => CanonicalJson::MAX_INTEGER, 'force' => false], true];
        yield 'the smallest exact integers' => [['site' => 'jane', 'days' => -CanonicalJson::MAX_INTEGER, 'force' => false], true];
        yield 'one missing' => [['site' => 'jane', 'days' => 30], false];
        yield 'one more' => [['site' => 'jane', 'days' => 30, 'force' => false, 'x' => 1], false];
        yield 'a number as text' => [['site' => 'jane', 'days' => '30', 'force' => false], false];
        yield 'too large a number' => [['site' => 'jane', 'days' => \PHP_INT_MAX, 'force' => false], false];
        yield 'text not UTF-8' => [['site' => "\xc3\x28", 'days' => 30, 'force' => false], false];
        yield 'a boolean as a number' => [['site' => 'jane', 'days' => 30, 'force' => 0], false];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('parameters')]
    public function testAnOperationTakesExactlyItsParameters(array $parameters, bool $accepted): void
    {
        self::assertSame($accepted, new Operation('acme.site.remove', ['site' => ParameterType::String, 'days' => ParameterType::Integer, 'force' => ParameterType::Boolean])->accepts($parameters));
    }

    public function testAKeyRecordsItsAlgorithm(): void
    {
        $es256 = new VirtualAuthenticator();
        $eddsa = new VirtualAuthenticator(edwards: true);
        $es256->create();
        $eddsa->create();

        self::assertSame(-7, self::key($es256)?->algorithm);
        self::assertSame(-8, self::key($eddsa)?->algorithm);
        self::assertSame(Base64Url::encode($es256->credentials[0]['id']), self::key($es256)?->id());
    }

    public function testRefusesWhatIsntAKey(): void
    {
        $authenticator = new VirtualAuthenticator();
        $authenticator->create();
        $passkey = $authenticator->credentials[0];
        $good = ['credential_id' => Base64Url::encode($passkey['id']), 'public_key' => Base64Url::encode($passkey['publicKey']), 'user_handle' => Base64Url::encode($passkey['userHandle']), 'label' => 'Key'];
        $at = new \DateTimeImmutable('@0');

        foreach ([
            'no credential ID' => ['credential_id' => ''],
            'a credential ID not base64url' => ['credential_id' => 'a+b'],
            'no user handle' => ['user_handle' => ''],
            'a public key not base64url' => ['public_key' => '***'],
            'a public key not CBOR' => ['public_key' => Base64Url::encode('not cbor')],
            'a public key not a map' => ['public_key' => Base64Url::encode("\x01")],
            'a public key with bytes after it' => ['public_key' => Base64Url::encode($passkey['publicKey']."\x00")],
            'an algorithm not accepted' => ['public_key' => Base64Url::encode("\xa1\x03\x38\x22")],
            'no algorithm' => ['public_key' => Base64Url::encode("\xa1\x01\x02")],
            'a label not text' => ['label' => 5],
        ] as $what => $change) {
            self::assertNull(Key::fromParameters([...$good, ...$change], $at), \sprintf('A key with %s was accepted.', $what));
        }

        self::assertNotNull(Key::fromParameters($good, $at));
    }

    public function testBase64UrlIsStrict(): void
    {
        self::assertSame("\xfb\xff", Base64Url::decode('-_8'));
        self::assertSame('', Base64Url::decode(''));

        foreach (['-_8=', '+/8', 'a', 'ab c', 'AB'] as $invalid) {
            self::assertNull(Base64Url::decode($invalid), \sprintf('"%s" was decoded.', $invalid));
        }
    }

    private static function key(VirtualAuthenticator $authenticator): ?Key
    {
        $passkey = $authenticator->credentials[0];

        return Key::fromParameters(['credential_id' => Base64Url::encode($passkey['id']), 'public_key' => Base64Url::encode($passkey['publicKey']), 'user_handle' => Base64Url::encode($passkey['userHandle']), 'label' => 'Key'], new \DateTimeImmutable('@0'));
    }
}
