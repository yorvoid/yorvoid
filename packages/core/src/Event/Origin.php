<?php

declare(strict_types=1);

namespace YorvoID\Core\Event;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Text;

/**
 * Where an operation came from: the kind of entry point, what ran there (a route, a command, a job), and
 * a correlation ID shared by everything one request, command or job does, so an audit can group a bulk
 * action into one story (PLT-042, AUD-001). The application provides it; YorvoID records it with every
 * event and log entry.
 *
 * @api
 */
final class Origin
{
    private const string CORRELATION_ID = '/^[A-Za-z0-9._:-]{1,128}$/D';

    public readonly OriginKind $kind;

    public readonly string $correlationId;

    public readonly ?string $detail;

    /** @var \WeakMap<self, true>|null Origins read back from a record: for reading, never for deciding (D-45); kept apart, so a restored origin equals the one recorded */
    private static ?\WeakMap $restored = null;

    /** Whether YorvoID's own factory is making it, which alone may make a System origin */
    private static bool $internal = false;

    /**
     * @param string|null $detail        What ran, e.g. a route name or "yorvoid:principal:suspend"; never a URL
     *                                   with a query string, which can hold tokens
     * @param string|null $correlationId The application's ID for the request, command or job; a random one by default
     *
     * @throws IntegratorError When it's System, which only YorvoID's own paths are (D-45, ARC-0016)
     */
    public function __construct(
        OriginKind $kind,
        ?string $detail = null,
        ?string $correlationId = null,
    ) {
        if (OriginKind::System === $kind && !self::$internal) {
            throw new IntegratorError('An origin of the System kind was made outside YorvoID.', 'Only YorvoID\'s own work is the System, e.g. a notification\'s token: give your background work the Job or Console origin, which the command line proves.', 'concepts/operations-and-events.md#origins');
        }

        $this->kind = $kind;
        // What ran can come from a request (a route, a path): it's recorded, so it's made valid UTF-8, and
        // kept to the 255 characters the log has room for.
        $this->detail = null === $detail ? null : mb_substr(Text::utf8($detail), 0, 255);

        $correlationId ??= bin2hex(random_bytes(16));

        if (1 !== preg_match(self::CORRELATION_ID, $correlationId)) {
            throw new IntegratorError(\sprintf('The correlation ID "%s" is invalid.', $correlationId), 'Use 1 to 128 letters, digits, dots, underscores, colons or hyphens, e.g. a request ID or a UUID.', 'concepts/operations-and-events.md#origins');
        }

        $this->correlationId = $correlationId;
    }

    /**
     * YorvoID's own work, e.g. a security notification's token, a digest, throttling.
     *
     * @internal
     */
    public static function system(string $detail, ?string $correlationId = null): self
    {
        return self::made(OriginKind::System, $detail, $correlationId);
    }

    /**
     * An origin as a store recorded it, System included: for reading only, since nothing that decides
     * accepts it (isRestored()).
     *
     * @internal
     */
    public static function restored(OriginKind $kind, ?string $detail, ?string $correlationId): self
    {
        $origin = self::made($kind, $detail, $correlationId);
        self::$restored ??= new \WeakMap();
        self::$restored[$origin] = true;

        return $origin;
    }

    /**
     * Immutable: nothing needs a copy, and a copy would lose the restored mark.
     */
    public function __clone(): void
    {
        throw new \LogicException('An Origin isn\'t cloned: it\'s immutable, use it as it is.');
    }

    /**
     * @return array{kind: string, detail: ?string, correlationId: string}
     */
    public function __serialize(): array
    {
        return ['kind' => $this->kind->value, 'detail' => $this->detail, 'correlationId' => $this->correlationId];
    }

    /**
     * An origin unserialized, e.g. from a queued message, is read back from a record: it decides nothing.
     *
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $kind = OriginKind::tryFrom(\is_string($data['kind'] ?? null) ? $data['kind'] : '') ?? throw new \UnexpectedValueException('A serialized origin has no kind.');
        $detail = $data['detail'] ?? null;
        $correlationId = $data['correlationId'] ?? null;

        if ((null !== $detail && !\is_string($detail)) || !\is_string($correlationId) || 1 !== preg_match(self::CORRELATION_ID, $correlationId)) {
            throw new \UnexpectedValueException('A serialized origin is malformed.');
        }

        $this->kind = $kind;
        $this->detail = null === $detail ? null : mb_substr(Text::utf8($detail), 0, 255);
        $this->correlationId = $correlationId;
        self::$restored ??= new \WeakMap();
        self::$restored[$this] = true;
    }

    /**
     * Whether it was read back from a record, rather than made for what runs now: nothing that
     * decides takes it as the request's origin.
     */
    public function isRestored(): bool
    {
        return true === (self::$restored[$this] ?? null);
    }

    private static function made(OriginKind $kind, ?string $detail, ?string $correlationId): self
    {
        self::$internal = true;

        try {
            return new self($kind, $detail, $correlationId);
        } finally {
            self::$internal = false;
        }
    }
}
