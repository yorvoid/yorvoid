<?php

declare(strict_types=1);

namespace YorvoID\Tests\Passkeys;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Passkeys\ClonePolicy;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\Refusal;
use YorvoID\Passkeys\UserVerification;

final class PasskeyPolicyTest extends TestCase
{
    public function testDefaultsAreStrict(): void
    {
        $policy = new PasskeyPolicy('example.com', ['https://example.com', 'https://app.example.com:8443']);

        self::assertSame(UserVerification::Required, $policy->userVerification);
        self::assertSame(ClonePolicy::RefuseSignIn, $policy->clonePolicy);
        self::assertEquals(Duration::minutes(5), $policy->timeout);
        self::assertSame(300000, $policy->timeoutMilliseconds);
        self::assertSame(1000, new PasskeyPolicy('localhost', ['http://localhost:8000'], timeout: Duration::seconds(1))->timeoutMilliseconds, 'Plain HTTP only on localhost, for development.');
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function mistakes(): iterable
    {
        yield 'a URL as the ID' => ['https://example.com', ['https://example.com'], 'isn\'t a domain'];
        yield 'an ID in capitals' => ['Example.com', ['https://example.com'], 'isn\'t a domain'];
        yield 'an ID with a port' => ['example.com:443', ['https://example.com'], 'isn\'t a domain'];
        yield 'an empty ID' => ['', ['https://example.com'], 'isn\'t a domain'];
        yield 'an ID with a trailing newline' => ["example.com\n", ['https://example.com'], 'isn\'t a domain'];
        yield 'no origins' => ['example.com', [], 'No origins'];
        yield 'plain HTTP' => ['example.com', ['http://example.com'], 'can\'t use passkeys'];
        yield 'another domain' => ['example.com', ['https://example.org'], 'can\'t use passkeys'];
        yield 'a domain that only ends the same' => ['example.com', ['https://badexample.com'], 'can\'t use passkeys'];
        yield 'a path' => ['example.com', ['https://example.com/login'], 'can\'t use passkeys'];
        yield 'credentials' => ['example.com', ['https://user@example.com'], 'can\'t use passkeys'];
        yield 'a query' => ['example.com', ['https://example.com?x=1'], 'can\'t use passkeys'];
        yield 'not a URL' => ['example.com', ['example.com'], 'can\'t use passkeys'];
        yield 'unparseable' => ['example.com', ['https://'], 'can\'t use passkeys'];
    }

    /**
     * @param list<string> $origins
     */
    #[DataProvider('mistakes')]
    public function testRefusesConfigurationMistakes(string $id, array $origins, string $message): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage($message);

        new PasskeyPolicy($id, $origins);
    }

    public function testRefusalsDontTellAGuesserWhichPasskeysExist(): void
    {
        $same = [Refusal::InvalidResponse, Refusal::UnknownPasskey, Refusal::PasskeyDisabled, Refusal::SuspectedCopy, Refusal::PrincipalInactive];

        self::assertCount(1, array_unique(array_map(static fn (Refusal $refusal): string => $refusal->message(), $same)));
        self::assertCount(5, array_unique(array_map(static fn (Refusal $refusal): string => $refusal->message(), Refusal::cases())));
    }
}
