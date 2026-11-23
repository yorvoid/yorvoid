<?php

declare(strict_types=1);

namespace YorvoID\Delegation\Storage;

use Doctrine\DBAL\Types\Types;
use YorvoID\Core\Authorization\KeepsVersionsIn;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Storage\DbalVersionStore;
use YorvoID\Core\Authorization\VersionStore;
use YorvoID\Core\Duration;
use YorvoID\Core\Id\Id;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;
use YorvoID\Core\Storage\StorageException;
use YorvoID\Delegation\Delegation;
use YorvoID\Delegation\DelegationStatus;
use YorvoID\Delegation\DelegationStore;

/**
 * Delegations in one table, their realm's version in core's (yorvoid_versions, "delegation").
 *
 * @api
 *
 * @experimental
 */
final readonly class DbalDelegationStore implements DelegationStore, KeepsVersionsIn
{
    public const string TABLE = 'delegation';

    public const string SOURCE = 'delegation';

    private const array TIMES = ['not_before' => Types::DATETIMETZ_IMMUTABLE, 'expires_at' => Types::DATETIMETZ_IMMUTABLE, 'issued_at' => Types::DATETIMETZ_IMMUTABLE, 'revoked_at' => Types::DATETIMETZ_IMMUTABLE];

    private const string COLUMNS = 'id, delegator, delegate, parent, root, depth, actions, patterns, resources, purpose, not_before, expires_at, redelegable, remaining_depth, execution, step_up, issued_by, issued_at_version, issued_at, status, revoked_at, revoked_by, revoked_why';

    private VersionStore $versions;

    /**
     * @param VersionStore|null $versions Where its version is kept: the one the evaluator's sources read, so a
     *                                    decision reads every version at once; its own on the database, if not given
     */
    public function __construct(
        private Database $database,
        ?VersionStore $versions = null,
    ) {
        $this->versions = $versions ?? new DbalVersionStore($database);
    }

    public function add(Delegation $delegation): void
    {
        // The version first, as every change: what's cached against it is never older than what's stored.
        $this->versions->bump($delegation->realm, self::SOURCE);

        if (!$this->database->insertUnlessExists(self::TABLE, ['realm' => $delegation->realm->name, ...self::row($delegation)], ['id'], self::TIMES)) {
            throw new StorageException(\sprintf('A delegation with the ID %s exists.', $delegation->id));
        }
    }

    public function get(Realm $realm, DelegationId $id): ?Delegation
    {
        $row = $this->database->connection->fetchAssociative(\sprintf('SELECT %s FROM %s WHERE realm = ? AND id = ?', self::COLUMNS, $this->table()), [$realm->name, $id->id->toString()]);

        return false === $row ? null : $this->delegation($realm, $row);
    }

    public function involving(Realm $realm, PrincipalId $principal): array
    {
        $id = $principal->id->toString();
        $delegations = [];

        foreach ($this->database->connection->iterateAssociative(\sprintf('SELECT %s FROM %s WHERE realm = ? AND status = ? AND (delegator = ? OR delegate = ?) ORDER BY issued_at, id', self::COLUMNS, $this->table()), [$realm->name, DelegationStatus::Active->value, $id, $id]) as $row) {
            $delegations[] = $this->delegation($realm, $row);
        }

        return $delegations;
    }

    public function revoke(Delegation $before, Delegation $after): bool
    {
        $this->versions->bump($before->realm, self::SOURCE);

        return 1 === $this->database->connection->update(
            $this->table(),
            ['status' => $after->status->value, 'revoked_at' => $after->revokedAt, 'revoked_by' => $after->revokedBy?->id->toString(), 'revoked_why' => $after->revokedWhy],
            ['realm' => $before->realm->name, 'id' => $before->id->id->toString(), 'status' => DelegationStatus::Active->value],
            self::TIMES,
        );
    }

    public function version(Realm $realm): int
    {
        return $this->versions->versions($realm, [self::SOURCE])[self::SOURCE];
    }

    public function versionStore(): VersionStore
    {
        return $this->versions;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Delegation $delegation): array
    {
        return [
            'id' => $delegation->id->id->toString(),
            'delegator' => $delegation->delegator->id->toString(),
            'delegate' => $delegation->delegate->id->toString(),
            'parent' => $delegation->parent?->id->toString(),
            'root' => $delegation->root->id->toString(),
            'depth' => $delegation->depth,
            'actions' => self::json($delegation->actions),
            'patterns' => self::json($delegation->patterns),
            'resources' => self::json($delegation->resources->toArray()),
            'purpose' => $delegation->purpose,
            'not_before' => $delegation->notBefore,
            'expires_at' => $delegation->expiresAt,
            'redelegable' => self::json($delegation->redelegable),
            'remaining_depth' => $delegation->remainingDepth,
            'execution' => $delegation->execution,
            'step_up' => null === $delegation->stepUp ? null : self::json(['within' => $delegation->stepUp->within->seconds, 'multi_factor' => $delegation->stepUp->multiFactor, 'phishing_resistant' => $delegation->stepUp->phishingResistant]),
            'issued_by' => $delegation->issuedBy,
            'issued_at_version' => $delegation->issuedAtVersion,
            'issued_at' => $delegation->issuedAt,
            'status' => $delegation->status->value,
            'revoked_at' => $delegation->revokedAt,
            'revoked_by' => $delegation->revokedBy?->id->toString(),
            'revoked_why' => $delegation->revokedWhy,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function delegation(Realm $realm, array $row): Delegation
    {
        $principal = static fn (string $column): PrincipalId => new PrincipalId(Id::fromString(Row::string($row, $column)));
        $stepUp = null === $row['step_up'] ? null : self::decode(Row::string($row, 'step_up'));

        return new Delegation(
            new DelegationId(Id::fromString(Row::string($row, 'id'))),
            $realm,
            $principal('delegator'),
            $principal('delegate'),
            null === $row['parent'] ? null : new DelegationId(Id::fromString(Row::string($row, 'parent'))),
            $principal('root'),
            Row::int($row, 'depth'),
            self::strings(Row::string($row, 'actions')),
            self::strings(Row::string($row, 'patterns')),
            Selector::fromArray(self::decode(Row::string($row, 'resources'))),
            null === $row['purpose'] ? null : Row::string($row, 'purpose'),
            $this->time($row['not_before']),
            $this->time($row['expires_at']),
            self::strings(Row::string($row, 'redelegable')),
            Row::int($row, 'remaining_depth'),
            null === $row['execution'] ? null : Row::string($row, 'execution'),
            null === $stepUp ? null : new StepUpRemedy(Duration::seconds(\is_int($stepUp['within'] ?? null) ? $stepUp['within'] : throw new \UnexpectedValueException('A stored delegation\'s step-up is malformed.')), true === ($stepUp['multi_factor'] ?? null), true === ($stepUp['phishing_resistant'] ?? null)),
            Row::string($row, 'issued_by'),
            Row::int($row, 'issued_at_version'),
            $this->time($row['issued_at']),
            DelegationStatus::from(Row::string($row, 'status')),
            null === $row['revoked_at'] ? null : $this->time($row['revoked_at']),
            null === $row['revoked_by'] ? null : $principal('revoked_by'),
            null === $row['revoked_why'] ? null : Row::string($row, 'revoked_why'),
        );
    }

    private function table(): string
    {
        return $this->database->table(self::TABLE);
    }

    private function time(mixed $value): \DateTimeImmutable
    {
        $time = $this->database->connection->convertToPHPValue($value, Types::DATETIMETZ_IMMUTABLE);
        \assert($time instanceof \DateTimeImmutable);

        return $time;
    }

    /**
     * @param array<mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $json): array
    {
        $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);

        return \is_array($data) ? $data : throw new \UnexpectedValueException('A stored delegation is malformed.');
    }

    /**
     * @return list<string>
     */
    private static function strings(string $json): array
    {
        $strings = [];

        foreach (self::decode($json) as $key => $item) {
            $strings[] = \is_int($key) && \is_string($item) ? $item : throw new \UnexpectedValueException('A stored delegation\'s list is malformed.');
        }

        return $strings;
    }
}
