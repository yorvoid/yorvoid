<?php

declare(strict_types=1);

namespace YorvoID\Core\Log;

use Symfony\Component\Uid\Uuid;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Text;
use YorvoID\Core\Time;

/**
 * One entry of the security log (AUD-001): what happened, to what, by whom, from where, and how it
 * ended, linked to the entry before it by a hash (AUD-002). The hash covers every field and the
 * previous hash, so changing, removing, inserting or reordering an entry breaks the chain.
 *
 * Details are for facts that help reconstruct what happened, never for secrets or copies of personal
 * data (AUD-004): strings, integers, booleans, null, and lists or maps of those. Floats aren't
 * accepted, as their text form isn't stable enough to hash.
 *
 * @api
 */
final readonly class LogEntry
{
    /** The hash algorithms entries may name; anything else fails verification (D-13) */
    public const array ALGORITHMS = ['sha256'];

    public const string ALGORITHM = 'sha256';

    public const int MAX_DETAILS_BYTES = 16_384;

    /**
     * @param array<array-key, mixed> $details Keys are strings when recorded; numeric ones come back from
     *                                         storage as integers, which hash the same
     */
    public function __construct(
        public Realm $realm,
        public int $sequence,
        public Uuid $id,
        public \DateTimeImmutable $recordedAt,
        public string $action,
        public Outcome $outcome,
        public Origin $origin,
        public ?PrincipalId $actor,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $details,
        public string $algorithm,
        public string $previousHash,
        public string $hash,
    ) {
    }

    /**
     * A new entry, its time stored to the second and its hash computed.
     *
     * @param array<string, mixed> $details
     */
    public static function create(
        Realm $realm,
        int $sequence,
        Uuid $id,
        \DateTimeImmutable $recordedAt,
        string $action,
        Outcome $outcome,
        Origin $origin,
        ?PrincipalId $actor,
        ?string $subjectType,
        ?string $subjectId,
        array $details,
        string $previousHash,
    ): self {
        if (1 !== preg_match(Operation::NAME, $action)) {
            throw new IntegratorError(\sprintf('The log action "%s" is invalid.', $action), 'Use an operation name, e.g. "yorvoid.principal.create".', 'concepts/security-log.md#entries');
        }

        if (null !== $subjectType && 1 !== preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $subjectType)) {
            throw new IntegratorError(\sprintf('The log subject type "%s" is invalid.', $subjectType), 'Use up to 63 lowercase letters, digits and underscores, e.g. "principal".', 'concepts/security-log.md#entries');
        }

        $subjectId = null === $subjectId ? null : Text::utf8($subjectId);

        if (null !== $subjectId && ('' === $subjectId || \strlen($subjectId) > 191)) {
            throw new IntegratorError('The log subject ID is empty or longer than 191 characters.', 'Use the subject\'s ID, e.g. a UUID.', 'concepts/security-log.md#entries');
        }

        /** @var array<string, mixed> $details Keys stay strings; only their order changes */
        $details = self::normalized($details);

        if (\strlen(self::json($details)) > self::MAX_DETAILS_BYTES) {
            throw new IntegratorError(\sprintf('The details of a "%s" log entry are larger than %d bytes.', $action, self::MAX_DETAILS_BYTES), 'Keep details to the facts needed to reconstruct what happened; point to records instead of copying them.', 'concepts/security-log.md#entries');
        }

        $recordedAt = Time::stored($recordedAt);
        $hash = new self($realm, $sequence, $id, $recordedAt, $action, $outcome, $origin, $actor, $subjectType, $subjectId, $details, self::ALGORITHM, $previousHash, '')->computeHash();
        \assert(null !== $hash);

        return new self($realm, $sequence, $id, $recordedAt, $action, $outcome, $origin, $actor, $subjectType, $subjectId, $details, self::ALGORITHM, $previousHash, $hash);
    }

    /**
     * The hash this entry's fields give, for verification; null if it names an algorithm that isn't
     * accepted.
     */
    public function computeHash(): ?string
    {
        if (!\in_array($this->algorithm, self::ALGORITHMS, true)) {
            return null;
        }

        return hash($this->algorithm, self::json([
            'format' => 1,
            'realm' => $this->realm->name,
            'sequence' => $this->sequence,
            'id' => $this->id->toRfc4122(),
            'recorded_at' => $this->recordedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'action' => $this->action,
            'outcome' => $this->outcome->value,
            'origin' => [$this->origin->kind->value, $this->origin->detail, $this->origin->correlationId],
            'actor' => null === $this->actor ? null : (string) $this->actor,
            'subject' => [$this->subjectType, $this->subjectId],
            'details' => self::normalized($this->details),
            'previous_hash' => $this->previousHash,
        ]));
    }

    /**
     * Maps sorted by key at every level, so the same details always hash the same; text made valid
     * UTF-8, so that details holding what an attacker typed can always be recorded.
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function normalized(array $value, string $path = 'details'): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            $key = \is_string($key) ? Text::utf8($key) : $key;
            $itemPath = $path.'.'.$key;

            $normalized[$key] = match (true) {
                \is_array($item) => self::normalized($item, $itemPath),
                \is_string($item) => Text::utf8($item),
                \is_int($item), \is_bool($item), null === $item => $item,
                default => throw new IntegratorError(\sprintf('The log detail "%s" is a %s.', $itemPath, get_debug_type($item)), 'Use strings, integers, booleans, null, or lists and maps of those; write numbers with decimals as strings.', 'concepts/security-log.md#entries'),
            };
        }

        if (!array_is_list($normalized)) {
            ksort($normalized, \SORT_STRING);
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
