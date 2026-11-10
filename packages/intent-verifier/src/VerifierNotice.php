<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * What the verifier did, for its notifier.
 *
 * @api
 */
final readonly class VerifierNotice
{
    /** An operation's signature was verified: it runs now */
    public const string VERIFIED = 'verified';

    /** A change to the keys was signed, and takes effect later unless a key cancels it: tell the owners now */
    public const string KEY_CHANGE_REQUESTED = 'key_change_requested';

    /** A key change was cancelled, signed by a key */
    public const string KEY_CHANGE_CANCELLED = 'key_change_cancelled';

    /** A key was added: a change took effect, or the first key came with the bootstrap secret */
    public const string KEY_ADDED = 'key_added';

    /** A key was removed: a change took effect */
    public const string KEY_REMOVED = 'key_removed';

    /** The host's root created a bootstrap secret: the way to add a key without any other */
    public const string BOOTSTRAP_SECRET_CREATED = 'bootstrap_secret_created';

    /** An entry of the verifier's log couldn't be written, so what it was for was refused (AUD-006): see $error */
    public const string AUDIT_FAILED = 'audit_failed';

    /**
     * @param string         $event  One of the constants above
     * @param Intent|null    $intent The intent signed, if any
     * @param Key|null       $key    The key that signed, or that was added or removed
     * @param KeyChange|null $change The change to the keys, for the key-change events
     * @param string|null    $error  What failed, for "audit_failed"
     */
    public function __construct(
        public string $event,
        public ?Intent $intent,
        public ?Key $key,
        public \DateTimeImmutable $at,
        public ?KeyChange $change = null,
        public ?string $error = null,
    ) {
    }
}
