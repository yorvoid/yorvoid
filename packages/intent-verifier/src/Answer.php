<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorDataLoader;
use Webauthn\CollectedClientData;
use Webauthn\PublicKeyCredential;

/**
 * The browser's answer to a ceremony, read by hand into the WebAuthn library's objects (STATIC-02).
 * The answer comes through the console, which the verifier doesn't trust: it's decoded with json_decode
 * into the one shape YorvoID's browser client sends, every binary field strict base64url, and only
 * then given to the library's byte-level loaders. Symfony Serializer's reflection-driven denormalizers
 * never see it.
 *
 * @internal
 */
final class Answer
{
    /** A passkey's answer is a few kilobytes; anything much larger isn't one. */
    public const int MAX_LENGTH = 65_536;

    /** The answer, its response, its transports or extension results: nothing deeper. */
    private const int MAX_DEPTH = 6;

    /**
     * A signature: id, rawId, type "public-key", and a response with clientDataJSON, authenticatorData,
     * signature and userHandle (or null).
     *
     * @throws \InvalidArgumentException When it isn't one
     */
    public static function assertion(string $json): PublicKeyCredential
    {
        [$rawId, $response] = self::credential($json, ['clientDataJSON', 'authenticatorData', 'signature'], ['userHandle']);
        $userHandle = $response['userHandle'] ?? null;

        return PublicKeyCredential::create('public-key', $rawId, AuthenticatorAssertionResponse::create(
            self::clientData(self::bytes($response, 'clientDataJSON')),
            AuthenticatorDataLoader::create()->load(self::bytes($response, 'authenticatorData')),
            self::bytes($response, 'signature'),
            null === $userHandle ? null : self::bytes($response, 'userHandle'),
        ));
    }

    /**
     * A new passkey: id, rawId, type "public-key", and a response with clientDataJSON, attestationObject
     * and the authenticator's transports.
     *
     * @throws \InvalidArgumentException When it isn't one
     */
    public static function attestation(string $json, AttestationStatementSupportManager $statements): PublicKeyCredential
    {
        [$rawId, $response] = self::credential($json, ['clientDataJSON', 'attestationObject'], ['transports']);
        $given = $response['transports'] ?? [];
        $transports = [];

        foreach (\is_array($given) && array_is_list($given) ? $given : [null] as $transport) {
            $transports[] = \is_string($transport) ? $transport : throw new \InvalidArgumentException('The transports aren\'t a list of names.');
        }

        self::bytes($response, 'attestationObject');
        \assert(\is_string($response['attestationObject']));

        return PublicKeyCredential::create('public-key', $rawId, AuthenticatorAttestationResponse::create(
            self::clientData(self::bytes($response, 'clientDataJSON')),
            AttestationObjectLoader::create($statements)->load($response['attestationObject']),
            $transports,
        ));
    }

    /**
     * @param list<string> $required The response's fields that must be there
     * @param list<string> $optional Those that may
     *
     * @return array{string, array<string, mixed>} The credential's raw ID, and its response
     */
    private static function credential(string $json, array $required, array $optional): array
    {
        if (\strlen($json) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('The answer is too long.');
        }

        try {
            $answer = json_decode($json, true, self::MAX_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The answer isn\'t JSON.', previous: $exception);
        }

        if (!\is_array($answer) || array_is_list($answer) || 'public-key' !== ($answer['type'] ?? null) || !\is_array($answer['response'] ?? null) || array_is_list($answer['response'])) {
            throw new \InvalidArgumentException('The answer isn\'t a public-key credential.');
        }

        // Only what the browser client sends; extension results and the attachment are left unread.
        self::onlyKeys($answer, ['id', 'rawId', 'type', 'response'], ['clientExtensionResults', 'authenticatorAttachment']);
        $response = $answer['response'];
        self::onlyKeys($response, $required, $optional);
        $rawId = self::bytes($answer, 'rawId');

        if (($answer['id'] ?? null) !== $answer['rawId']) {
            throw new \InvalidArgumentException('The answer\'s id isn\'t its rawId.');
        }

        // Its fields, by name: the ones it may have, and only those.
        $fields = [];

        foreach ([...$required, ...$optional] as $name) {
            if (\array_key_exists($name, $response)) {
                $fields[$name] = $response[$name];
            }
        }

        return [$rawId, $fields];
    }

    /**
     * @param array<array-key, mixed> $object
     * @param list<string>            $required
     * @param list<string>            $optional
     */
    private static function onlyKeys(array $object, array $required, array $optional): void
    {
        $keys = array_keys($object);

        if ([] !== array_diff($required, $keys) || [] !== array_diff($keys, $required, $optional)) {
            throw new \InvalidArgumentException(\sprintf('The answer has the fields %s, not %s.', implode(', ', $keys), implode(', ', $required)));
        }
    }

    /**
     * @param array<array-key, mixed> $object
     */
    private static function bytes(array $object, string $key): string
    {
        $value = $object[$key] ?? null;
        $bytes = \is_string($value) ? Base64Url::decode($value) : null;

        return $bytes ?? throw new \InvalidArgumentException(\sprintf('The answer\'s %s isn\'t base64url.', $key));
    }

    private static function clientData(string $json): CollectedClientData
    {
        try {
            $data = json_decode($json, true, self::MAX_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The client data isn\'t JSON.', previous: $exception);
        }

        if (!\is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('The client data isn\'t an object.');
        }

        /* @var array<string, mixed> $data */
        return CollectedClientData::create($json, $data);
    }
}
