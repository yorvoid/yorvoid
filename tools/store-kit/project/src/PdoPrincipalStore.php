<?php

declare(strict_types=1);

namespace Acme\Storage;

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
        $pdo->exec('CREATE TABLE acme_principal (id TEXT PRIMARY KEY, realm TEXT NOT NULL, kind TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, status_changed_at TEXT NOT NULL, status_version INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX acme_principal_realm_status ON acme_principal (realm, status)');
    }

    public function add(Principal $principal): void
    {
        try {
            $this->pdo->prepare('INSERT INTO acme_principal VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $principal->id->uuid->toRfc4122(),
                $principal->realm->name,
                $principal->kind->value,
                $principal->status->value,
                $principal->createdAt->format(self::TIME),
                $principal->updatedAt->format(self::TIME),
                $principal->statusChangedAt->format(self::TIME),
                $principal->statusVersion,
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
        $statement = $this->pdo->prepare('SELECT kind, status, created_at, updated_at, status_changed_at, status_version FROM acme_principal WHERE realm = ? AND id = ?');
        $statement->execute([$realm->name, $id->uuid->toRfc4122()]);
        /** @var array{kind: string, status: string, created_at: string, updated_at: string, status_changed_at: string, status_version: int}|false $row */
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
        );
    }

    public function hasActive(Realm $realm): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM acme_principal WHERE realm = ? AND status = ? LIMIT 1');
        $statement->execute([$realm->name, PrincipalStatus::Active->value]);

        return false !== $statement->fetchColumn();
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
            $before->id->uuid->toRfc4122(),
            $before->statusVersion,
        ]);

        return 1 === $statement->rowCount();
    }
}
