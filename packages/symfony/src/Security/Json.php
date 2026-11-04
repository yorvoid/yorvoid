<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * The JSON body of YorvoID's endpoints. They accept only JSON: a form on another site can't send it
 * without the browser asking this one first (CORS), so they can't be posted to from elsewhere.
 *
 * @internal
 */
final class Json
{
    /**
     * @return array<mixed>|null Null if the request isn't JSON, or isn't an object
     */
    public static function body(Request $request): ?array
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return null;
        }

        $body = json_decode($request->getContent());

        return $body instanceof \stdClass && self::isFinite($body) ? get_object_vars($body) : null;
    }

    /**
     * Whether every number in decoded JSON is finite: 1e999 decodes to INF, which can't be encoded again
     * for the WebAuthn library, and is no answer a browser gives.
     */
    public static function isFinite(mixed $decoded): bool
    {
        if (\is_float($decoded)) {
            return is_finite($decoded);
        }

        if (\is_array($decoded) || $decoded instanceof \stdClass) {
            foreach (\is_array($decoded) ? $decoded : get_object_vars($decoded) as $value) {
                if (!self::isFinite($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<mixed>|null $body
     */
    public static function string(?array $body, string $field): ?string
    {
        $value = $body[$field] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * What the person would recognise in a list of their devices: their browser's description of
     * itself, as much as fits.
     */
    public static function device(Request $request): ?string
    {
        $agent = $request->headers->get('User-Agent');

        return null === $agent || '' === $agent ? null : mb_substr($agent, 0, 255);
    }
}
