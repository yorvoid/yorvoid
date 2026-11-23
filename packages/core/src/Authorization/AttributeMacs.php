<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\KeyRings;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\ForgetsWhatItRead;

/**
 * How a decision records the attributes it read (M-9): a value a resolver declared public, and a boolean,
 * as it is; any other as a MAC under the realm's MAC key, so a value given can be confirmed against the
 * record and the record alone discloses none. A realm without a MAC key records it as withheld.
 *
 * A MAC is over its realm, the attribute's name and the value, with a domain of its own: it can't be moved
 * to another realm or attribute, and no JWS or other HMAC is ever one. It's the same for the same value,
 * so a reader of the records sees which decisions read equal values, but not the value.
 *
 * Recorded forms: "v:" and the value's JSON, "m:<key ID>:<MAC>" (base64url), and "w:". Text that isn't
 * valid UTF-8 is withheld: it has no one JSON form, so no MAC or value of it could prove which bytes
 * were read.
 *
 * @api
 */
final class AttributeMacs implements ForgetsWhatItRead
{
    private const string DOMAIN = 'yorvoid-attribute-mac/1';

    /** @var array<string, KeyRing|null> By realm, as read in this request: null when it has none */
    private array $rings = [];

    public function __construct(
        private readonly ?KeyRings $keys = null,
    ) {
    }

    /**
     * The attribute as a decision record holds it.
     *
     * @param string|int|bool|list<string> $value
     * @param bool                         $public Whether its resolver says it isn't personal data
     *
     * @internal The evaluator records them
     */
    public function record(Realm $realm, string $name, string|int|bool|array $value, bool $public): string
    {
        if (!self::utf8($value)) {
            return 'w:';
        }

        if ($public || \is_bool($value)) {
            return 'v:'.self::json($value);
        }

        $key = $this->ring($realm)?->mac();

        return null === $key ? 'w:' : \sprintf('m:%s:%s', $key->id, self::base64url($key->mac(self::message($realm, $name, $value))));
    }

    /**
     * Whether what a record holds for an attribute is that value.
     *
     * @param string|null                  $recorded What it holds for it, if anything
     * @param string|int|bool|list<string> $value
     *
     * @internal Confirm through DecisionConfirmations, which logs each confirmation: an unlogged one is a
     *           brute force nothing sees
     */
    public function confirm(Realm $realm, string $name, ?string $recorded, string|int|bool|array $value): Confirmation
    {
        if (null === $recorded) {
            return Confirmation::NotRecorded;
        }

        if (!self::utf8($value)) {
            // Never recorded as such: it's withheld wherever it was read.
            return 'w:' === $recorded ? Confirmation::NotRecorded : Confirmation::Differs;
        }

        if ('w:' === $recorded) {
            return Confirmation::Withheld;
        }

        if (str_starts_with($recorded, 'v:')) {
            return hash_equals($recorded, 'v:'.self::json($value)) ? Confirmation::Matches : Confirmation::Differs;
        }

        if (1 !== preg_match('/^m:([A-Za-z0-9._-]{1,64}):([A-Za-z0-9_-]{43})$/D', $recorded, $parts)) {
            return Confirmation::NotRecorded;
        }

        $key = $this->ring($realm)?->macKey($parts[1]);

        if (null === $key) {
            return Confirmation::UnknownKey;
        }

        return hash_equals($parts[2], self::base64url($key->mac(self::message($realm, $name, $value)))) ? Confirmation::Matches : Confirmation::Differs;
    }

    public function reset(): void
    {
        $this->rings = [];
    }

    private function ring(Realm $realm): ?KeyRing
    {
        if (\array_key_exists($realm->name, $this->rings)) {
            return $this->rings[$realm->name];
        }

        try {
            $ring = $this->keys?->forRealm($realm);
        } catch (IntegratorError) {
            // A realm without its key file has no MAC key: its attributes are withheld, and the doctor
            // reports it.
            $ring = null;
        }

        return $this->rings[$realm->name] = $ring;
    }

    /**
     * Each field length-prefixed, so no field's content can be read as another's.
     *
     * @param string|int|bool|list<string> $value
     */
    private static function message(Realm $realm, string $name, string|int|bool|array $value): string
    {
        return implode('', array_map(static fn (string $field): string => \strlen($field).':'.$field, [self::DOMAIN, $realm->name, $name, self::json($value)]));
    }

    /**
     * @param string|int|bool|list<string> $value
     */
    private static function utf8(string|int|bool|array $value): bool
    {
        foreach (\is_array($value) ? $value : [$value] as $one) {
            if (\is_string($one) && !mb_check_encoding($one, 'UTF-8')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string|int|bool|list<string> $value
     */
    private static function json(string|int|bool|array $value): string
    {
        // Valid UTF-8 only (utf8()): one form for one value.
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
