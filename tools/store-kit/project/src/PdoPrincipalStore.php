<?php

declare(strict_types=1);

namespace Acme\Storage;

use YorvoID\Core\Id\Id;
use YorvoID\Core\Principal\AgentProfile;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageException;

final readonly class PdoPrincipalStore implements PrincipalStore
{
    private const string TIME = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private \PDO $pdo,
    ) {
    }

    public static function createTable(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE acme_principal (id TEXT PRIMARY KEY, realm TEXT NOT NULL, kind TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, status_changed_at TEXT NOT NULL, status_version INTEGER NOT NULL, owner_id TEXT NULL, profile TEXT NULL)');
        $pdo->exec('CREATE INDEX acme_principal_realm_status ON acme_principal (realm, status)');
        $pdo->exec('CREATE INDEX acme_principal_owner ON acme_principal (realm, owner_id)');
    }

    public function add(Principal $principal): void
    {
        try {
            $this->pdo->prepare('INSERT INTO acme_principal VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $principal->id->id->toString(),
                $principal->realm->name,
                $principal->kind->value,
                $principal->status->value,
                $principal->createdAt->format(self::TIME),
                $principal->updatedAt->format(self::TIME),
                $principal->statusChangedAt->format(self::TIME),
                $principal->statusVersion,
                $principal->owner?->id->toString(),
                null === $principal->profile ? null : json_encode($principal->profile->toArray(), \JSON_THROW_ON_ERROR),
            ]);
        } catch (\PDOException $exception) {
            if ('23000' !== $exception->getCode()) {
                throw $exception;
            }

            throw new StorageException(\sprintf('A principal with the ID %s exists.', $principal->id), previous: $exception);
        }
    }

    public function get(Realm $realm, PrincipalId $id): ?Principal
    {
        $statement = $this->pdo->prepare('SELECT kind, status, created_at, updated_at, status_changed_at, status_version, owner_id, profile FROM acme_principal WHERE realm = ? AND id = ?');
        $statement->execute([$realm->name, $id->id->toString()]);
        /** @var array{kind: string, status: string, created_at: string, updated_at: string, status_changed_at: string, status_version: int, owner_id: string|null, profile: string|null}|false $row */
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (false === $row) {
            return null;
        }

        return new Principal(
            $id,
            $realm,
            PrincipalKind::from($row['kind']),
            PrincipalStatus::from($row['status']),
            new \DateTimeImmutable($row['created_at']),
            new \DateTimeImmutable($row['updated_at']),
            new \DateTimeImmutable($row['status_changed_at']),
            $row['status_version'],
            null === $row['owner_id'] ? null : new PrincipalId(Id::fromString($row['owner_id'])),
            null === $row['profile'] ? null : AgentProfile::fromArray((array) json_decode($row['profile'], true, 3, \JSON_THROW_ON_ERROR)),
        );
    }

    public function ownsAgents(Realm $realm, PrincipalId $owner): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM acme_principal WHERE realm = ? AND owner_id = ? AND status <> ? LIMIT 1');
        $statement->execute([$realm->name, $owner->id->toString(), PrincipalStatus::Deleted->value]);

        return false !== $statement->fetchColumn();
    }

    public function changeOwner(Principal $before, Principal $after): bool
    {
        if (null === $before->owner || null === $after->owner) {
            return false;
        }

        $statement = $this->pdo->prepare('UPDATE acme_principal SET owner_id = ?, updated_at = ? WHERE realm = ? AND id = ? AND owner_id = ?');
        $statement->execute([$after->owner->id->toString(), $after->updatedAt->format(self::TIME), $before->realm->name, $before->id->id->toString(), $before->owner->id->toString()]);

        return 1 === $statement->rowCount();
    }

    public function hasActive(Realm $realm): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM acme_principal WHERE realm = ? AND status = ? LIMIT 1');
        $statement->execute([$realm->name, PrincipalStatus::Active->value]);

        return false !== $statement->fetchColumn();
    }

    /**
     * A write, so that it's a lock under any isolation: SQLite writes one transaction at a time, and an
     * update takes its lock. On a database with row locks, update a counter column of the principal's,
     * not SELECT ... FOR UPDATE, which under REPEATABLE READ lets a waiter go on with an older snapshot.
     */
    public function lock(Realm $realm, PrincipalId $id): void
    {
        $this->pdo->prepare('UPDATE acme_principal SET status_version = status_version WHERE realm = ? AND id = ?')->execute([$realm->name, $id->id->toString()]);
    }

    public function changeStatus(Principal $before, Principal $after): bool
    {
        $statement = $this->pdo->prepare('UPDATE acme_principal SET status = ?, updated_at = ?, status_changed_at = ?, status_version = ? WHERE realm = ? AND id = ? AND status_version = ?');
        $statement->execute([
            $after->status->value,
            $after->updatedAt->format(self::TIME),
            $after->statusChangedAt->format(self::TIME),
            $after->statusVersion,
            $before->realm->name,
            $before->id->id->toString(),
            $before->statusVersion,
        ]);

        return 1 === $statement->rowCount();
    }
}
