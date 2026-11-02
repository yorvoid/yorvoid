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

    /** A key was added, signed by another */
    public const string KEY_ADDED = 'key_added';

    /** A key was removed */
    public const string KEY_REMOVED = 'key_removed';

    /** A key was added with the bootstrap secret */
    public const string BOOTSTRAPPED = 'bootstrapped';

    /** The host's root created a bootstrap secret: the way to add a key without any other */
    public const string BOOTSTRAP_SECRET_CREATED = 'bootstrap_secret_created';

    /**
     * @param string      $event  One of the constants above
     * @param Intent|null $intent The intent signed, if any
     * @param Key|null    $key    The key that signed, or that was added or removed
     */
    public function __construct(
        public string $event,
        public ?Intent $intent,
        public ?Key $key,
        public \DateTimeImmutable $at,
    ) {
    }
}
