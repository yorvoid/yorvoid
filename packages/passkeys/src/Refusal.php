<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

/**
 * Why a passkey ceremony was refused. Show people the message, not the case: it doesn't help someone
 * guessing which passkeys exist.
 *
 * @api
 */
enum Refusal: string
{
    /** The ceremony expired, was finished already, or never began */
    case CeremonyExpired = 'ceremony_expired';

    /** The browser's answer isn't valid: wrong challenge, origin, signature, or not a passkey response */
    case InvalidResponse = 'invalid_response';

    /** The authenticator didn't check that it was its owner using it, and the policy requires that */
    case UserNotVerified = 'user_not_verified';

    /** This passkey is registered already */
    case AlreadyRegistered = 'already_registered';

    /** No such passkey, or it was removed */
    case UnknownPasskey = 'unknown_passkey';

    /** The passkey is disabled */
    case PasskeyDisabled = 'passkey_disabled';

    /** The passkey's signature counter suggests it was copied */
    case SuspectedCopy = 'suspected_copy';

    /** Its principal can't sign in, e.g. it's suspended */
    case PrincipalInactive = 'principal_inactive';

    /** The principal has no passkey to confirm it's them with */
    case NoPasskey = 'no_passkey';

    /**
     * The message's ID, to translate (domain "yorvoid"): the same for the cases people shouldn't tell
     * apart, as message() is.
     */
    public function messageId(): string
    {
        return match ($this) {
            self::CeremonyExpired => 'yorvoid.passkey.expired',
            self::UserNotVerified => 'yorvoid.passkey.not_verified',
            self::AlreadyRegistered => 'yorvoid.passkey.already_registered',
            self::NoPasskey => 'yorvoid.passkey.no_passkey',
            self::InvalidResponse, self::UnknownPasskey, self::PasskeyDisabled, self::SuspectedCopy, self::PrincipalInactive => 'yorvoid.passkey.not_working',
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::CeremonyExpired => 'That took too long. Please try again.',
            self::UserNotVerified => 'Your device didn\'t confirm it\'s you. Use a passkey protected by a PIN, fingerprint or face.',
            self::AlreadyRegistered => 'This passkey is already on your account.',
            self::NoPasskey => 'There\'s no passkey on your account. Confirm it\'s you another way.',
            self::InvalidResponse, self::UnknownPasskey, self::PasskeyDisabled, self::SuspectedCopy, self::PrincipalInactive => 'That passkey didn\'t work. Try another, or another way to sign in.',
        };
    }
}
