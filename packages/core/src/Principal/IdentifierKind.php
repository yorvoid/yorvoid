<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

/**
 * The ways a person can be found by what they type (IDN-003). Each is optional, and unique within a
 * realm regardless of case.
 *
 * @api
 */
enum IdentifierKind: string
{
    /** An email address; compared in Unicode NFC, with A–Z in lowercase and the domain in its ASCII form */
    case Email = 'email';

    /**
     * A username: 1 to 64 letters (a–z), digits, dots, underscores or hyphens, starting and ending with
     * a letter or digit; compared in lowercase. Only ASCII, so that no two usernames look alike.
     */
    case Username = 'username';

    private const string USERNAME = '/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/D';

    /**
     * The form identifiers are compared in: two values are the same identifier if their forms are equal.
     *
     * @throws InvalidIdentifier When the value isn't a valid identifier of this kind
     */
    public function normalize(string $value): string
    {
        return match ($this) {
            self::Email => self::email($value),
            self::Username => self::username($value),
        };
    }

    private static function email(string $value): string
    {
        $at = strrpos($value, '@');
        $local = false === $at ? '' : substr($value, 0, $at);
        $domain = false === $at ? '' : substr($value, $at + 1);

        if (
            1 !== preg_match('//u', $value)
            || 1 === preg_match('/[\s\p{C}]/u', $value)
            || \strlen($value) > 254
            || '' === $local
            || \strlen($local) > 64
            || str_contains($local, '@')
            || '' === $domain
            || str_starts_with($domain, '.')
            || str_ends_with($domain, '.')
            || str_contains($domain, '..')
        ) {
            throw new InvalidIdentifier(self::Email, 'An email address looks like name@example.com, with no spaces.');
        }

        // One form for text that is the same: precomposed é and e followed by a combining accent are one
        // address (Unicode NFC). Then the local part in lowercase for A–Z only, as mail servers commonly
        // treat it; beyond ASCII, case is the mail server's to decide, so Á and á stay two addresses. The
        // domain as DNS knows it: its ASCII form (IDNA), lowercase. Mail goes to the address as given.
        $nfc = \Normalizer::normalize($value, \Normalizer::FORM_C);
        $nfc = \is_string($nfc) ? $nfc : '';
        $at = strrpos($nfc, '@');
        $domain = false === $at ? false : substr($nfc, $at + 1);

        if (false !== $domain && 1 === preg_match('/[^\x00-\x7F]/', $domain)) {
            // The result checked too: some characters map to a space or other ASCII that a hostname
            // can't have (¯ to a space and a combining macron), and not every ICU version refuses them.
            $domain = idn_to_ascii($domain, \IDNA_NONTRANSITIONAL_TO_ASCII | \IDNA_CHECK_BIDI, \INTL_IDNA_VARIANT_UTS46);
            $domain = \is_string($domain) && 1 === preg_match('/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\z/', $domain) ? $domain : false;
        }

        if (false === $at || false === $domain) {
            throw new InvalidIdentifier(self::Email, 'An email address looks like name@example.com, with no spaces.');
        }

        $normalized = strtolower(substr($nfc, 0, $at)).'@'.strtolower($domain);

        // The ASCII form of a domain can be longer than what was entered.
        if (\strlen($normalized) > 254) {
            throw new InvalidIdentifier(self::Email, 'An email address has at most 254 characters.');
        }

        return $normalized;
    }

    private static function username(string $value): string
    {
        $normalized = strtolower($value);

        if (1 !== preg_match(self::USERNAME, $normalized)) {
            throw new InvalidIdentifier(self::Username, 'A username has 1 to 64 letters (a–z), digits, dots, underscores or hyphens, and starts and ends with a letter or digit.');
        }

        return $normalized;
    }
}
