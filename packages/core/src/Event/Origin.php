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
final readonly class Origin
{
    private const string CORRELATION_ID = '/^[A-Za-z0-9._:-]{1,128}$/D';

    public string $correlationId;

    public ?string $detail;

    /**
     * @param string|null $detail        What ran, e.g. a route name or "yorvoid:principal:suspend"; never a URL
     *                                   with a query string, which can hold tokens
     * @param string|null $correlationId The application's ID for the request, command or job; a random one by default
     */
    public function __construct(
        public OriginKind $kind,
        ?string $detail = null,
        ?string $correlationId = null,
    ) {
        // What ran can come from a request (a route, a path): it's recorded, so it's made valid UTF-8.
        $this->detail = null === $detail ? null : Text::utf8($detail);

        $correlationId ??= bin2hex(random_bytes(16));

        if (1 !== preg_match(self::CORRELATION_ID, $correlationId)) {
            throw new IntegratorError(\sprintf('The correlation ID "%s" is invalid.', $correlationId), 'Use 1 to 128 letters, digits, dots, underscores, colons or hyphens, e.g. a request ID or a UUID.', 'concepts/operations-and-events.md#origins');
        }

        $this->correlationId = $correlationId;
    }
}
