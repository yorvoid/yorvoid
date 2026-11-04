<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use YorvoID\IntentVerifier\Answer;
use YorvoID\IntentVerifier\Base64Url;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * The browser's answers, read by hand: the one shape the browser client sends, and nothing else
 * (STATIC-02).
 */
final class AnswerTest extends TestCase
{
    private const string REQUEST = '{"challenge":"Y2hhbGxlbmdlLWNoYWxsZW5nZS0zMg","rpId":"sign.acme.example","allowCredentials":[],"userVerification":"required"}';

    private const string CREATION = '{"rp":{"id":"sign.acme.example","name":""},"user":{"id":"dXNlcg","name":"owner","displayName":"Owner"},"challenge":"Y2hhbGxlbmdlLWNoYWxsZW5nZS0zMg","pubKeyCredParams":[{"type":"public-key","alg":-7}],"attestation":"none"}';

    public function testReadsTheBrowserClientsAnswers(): void
    {
        $authenticator = new VirtualAuthenticator('https://sign.acme.example');
        $registration = $authenticator->register(self::CREATION);
        $created = Answer::attestation($registration, new AttestationStatementSupportManager());
        self::assertInstanceOf(AuthenticatorAttestationResponse::class, $created->response);
        self::assertSame('webauthn.create', $created->response->clientDataJSON->type);

        $signed = Answer::assertion($authenticator->signIn(self::REQUEST));
        self::assertInstanceOf(AuthenticatorAssertionResponse::class, $signed->response);
        self::assertSame($created->rawId, $signed->rawId);
        self::assertSame('https://sign.acme.example', $signed->response->clientDataJSON->origin);
        self::assertTrue($signed->response->authenticatorData->isUserVerified());
    }

    /**
     * @return iterable<string, array{\Closure(array<array-key, mixed>): mixed}>
     */
    public static function changes(): iterable
    {
        yield 'a list' => [static fn (array $answer): array => [$answer]];
        yield 'another type' => [static fn (array $answer): array => ['type' => 'password'] + $answer];
        yield 'an unknown field' => [static fn (array $answer): array => $answer + ['extra' => 1]];
        yield 'no id' => [static fn (array $answer): array => array_diff_key($answer, ['id' => true])];
        yield 'an id other than the raw ID' => [static fn (array $answer): array => ['id' => 'AAAA'] + $answer];
        yield 'a padded raw ID' => [static fn (array $answer): array => ['rawId' => self::text($answer['rawId']).'=', 'id' => self::text($answer['rawId']).'='] + $answer];
        yield 'standard base64' => [static fn (array $answer): array => self::withResponse($answer, ['signature' => '+/+/'])];
        yield 'no signature' => [static fn (array $answer): array => self::withResponse($answer, ['signature' => null])];
        yield 'an unknown response field' => [static fn (array $answer): array => self::withResponse($answer, ['attestationObject' => 'AAAA'])];
        yield 'an empty response' => [static fn (array $answer): array => ['response' => []] + $answer];
        yield 'a response that isn\'t an object' => [static fn (array $answer): array => ['response' => 'text'] + $answer];
        yield 'a response that is a list' => [static fn (array $answer): array => ['response' => ['a']] + $answer];
        yield 'client data that is a list' => [static fn (array $answer): array => self::withResponse($answer, ['clientDataJSON' => Base64Url::encode('[1]')])];
        yield 'client data that isn\'t JSON' => [static fn (array $answer): array => self::withResponse($answer, ['clientDataJSON' => Base64Url::encode('{')])];
        yield 'nested too deep' => [static fn (array $answer): array => ['clientExtensionResults' => ['a' => ['b' => ['c' => ['d' => ['e' => 1]]]]]] + $answer];
        yield 'a user handle that isn\'t base64url' => [static fn (array $answer): array => self::withResponse($answer, ['userHandle' => 7])];
    }

    /**
     * @param \Closure(array<array-key, mixed>): mixed $change
     */
    #[DataProvider('changes')]
    public function testRefusesAnyOtherShape(\Closure $change): void
    {
        $authenticator = new VirtualAuthenticator('https://sign.acme.example');
        $authenticator->register(self::CREATION);
        $json = $authenticator->signIn(self::REQUEST);
        Answer::assertion($json);
        $answer = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        /* @var array<string, mixed> $answer */
        $this->expectException(\InvalidArgumentException::class);

        Answer::assertion(json_encode($change($answer), \JSON_THROW_ON_ERROR));
    }

    public function testRefusesWhatIsntJsonOrIsTooLong(): void
    {
        foreach (['', '{', 'null', '"text"', '{"type":"public-key","response":{}}'] as $json) {
            try {
                Answer::assertion($json);
                self::fail(\sprintf('%s was read.', substr($json, 0, 40)));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAnAnswerHasAtMostTheMaximumLength(): void
    {
        $authenticator = new VirtualAuthenticator('https://sign.acme.example');
        $authenticator->register(self::CREATION);
        $json = $authenticator->signIn(self::REQUEST);
        $longest = $json.str_repeat(' ', Answer::MAX_LENGTH - \strlen($json));

        self::assertSame(Answer::MAX_LENGTH, \strlen($longest));
        Answer::assertion($longest);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The answer is too long.');
        Answer::assertion($longest.' ');
    }

    public function testANewPasskeysAttestationIsStrictBase64Url(): void
    {
        $answer = json_decode(new VirtualAuthenticator('https://sign.acme.example')->register(self::CREATION), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        \assert(\is_array($answer['response']) && \is_string($answer['response']['attestationObject']));
        $padded = $answer['response']['attestationObject'].'=';

        $this->expectException(\InvalidArgumentException::class);
        Answer::attestation(json_encode(self::withResponse($answer, ['attestationObject' => $padded]), \JSON_THROW_ON_ERROR), new AttestationStatementSupportManager());
    }

    public function testANewPasskeysTransportsAreAListOfNames(): void
    {
        $answer = json_decode(new VirtualAuthenticator('https://sign.acme.example')->register(self::CREATION), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        foreach (['usb', ['usb' => 'usb'], [1], [['usb']]] as $transports) {
            try {
                /* @var array<string, mixed> $answer */
                Answer::attestation(json_encode(self::withResponse($answer, ['transports' => $transports]), \JSON_THROW_ON_ERROR), new AttestationStatementSupportManager());
                self::fail('Transports were taken: '.json_encode($transports));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        /** @var array<string, mixed> $answer */
        $created = Answer::attestation(json_encode(self::withResponse($answer, ['transports' => ['usb', 'nfc']]), \JSON_THROW_ON_ERROR), new AttestationStatementSupportManager());
        self::assertInstanceOf(AuthenticatorAttestationResponse::class, $created->response);
        self::assertSame(['usb', 'nfc'], $created->response->transports);
    }

    private static function text(mixed $value): string
    {
        \assert(\is_string($value));

        return $value;
    }

    /**
     * @param array<array-key, mixed> $answer
     * @param array<string, mixed>    $fields Null removes a field
     *
     * @return array<array-key, mixed>
     */
    private static function withResponse(array $answer, array $fields): array
    {
        \assert(\is_array($answer['response']));
        $response = array_filter(array_merge($answer['response'], $fields), static fn (mixed $value): bool => null !== $value);
        $answer['response'] = $response;

        return $answer;
    }
}
