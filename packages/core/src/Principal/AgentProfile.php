<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Exception\IntegratorError;

/**
 * What an agent is (AGT-001): its name, the software and version it runs, and who operates it. It's
 * description, not proof: nothing decides on it, and it's kept with the principal for people to read.
 *
 * @api
 *
 * @experimental
 */
final readonly class AgentProfile
{
    private const int MAX = 200;

    /**
     * @param string      $name     What people call it, e.g. "Release notes assistant"
     * @param string|null $software What it runs, e.g. "acme-agent"
     * @param string|null $version  Its version, e.g. "2.4.1"
     * @param string|null $operator Who runs it, e.g. a team or a vendor
     *
     * @throws IntegratorError When a field is empty, too long or not text
     */
    public function __construct(
        public string $name,
        public ?string $software = null,
        public ?string $version = null,
        public ?string $operator = null,
    ) {
        foreach (['name' => $name, 'software' => $software, 'version' => $version, 'operator' => $operator] as $field => $value) {
            if (null !== $value && ('' === trim($value) || mb_strlen($value) > self::MAX || !mb_check_encoding($value, 'UTF-8'))) {
                throw new IntegratorError(\sprintf('An agent\'s %s must be text of 1 to %d characters.', $field, self::MAX), 'Give it a short description, or leave the optional fields out.', 'concepts/principals.md#agents');
            }
        }
    }

    /**
     * @return array{name: string, software: string|null, version: string|null, operator: string|null}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'software' => $this->software, 'version' => $this->version, 'operator' => $this->operator];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \UnexpectedValueException When it isn't one
     */
    public static function fromArray(array $data): self
    {
        $text = static fn (string $key): ?string => null === ($data[$key] ?? null) ? null : (\is_string($data[$key]) ? $data[$key] : throw new \UnexpectedValueException(\sprintf('A stored agent profile\'s "%s" isn\'t text.', $key)));

        return new self($text('name') ?? throw new \UnexpectedValueException('A stored agent profile has no name.'), $text('software'), $text('version'), $text('operator'));
    }
}
