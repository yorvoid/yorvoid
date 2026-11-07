<?php

declare(strict_types=1);

namespace Acme\Storage;

use YorvoID\Flows\FlowState;
use YorvoID\Flows\FlowStore;

/**
 * Flows kept in a table instead of the PHP session, for one browser: whoever has its ID.
 */
final readonly class PdoFlowStore implements FlowStore
{
    public function __construct(
        private \PDO $pdo,
    ) {
    }

    public static function createTable(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE acme_flow (id TEXT PRIMARY KEY, state TEXT NOT NULL)');
    }

    public function get(string $id): ?FlowState
    {
        $statement = $this->pdo->prepare('SELECT state FROM acme_flow WHERE id = ?');
        $statement->execute([$id]);
        $state = $statement->fetchColumn();

        return \is_string($state) ? FlowState::fromArray(json_decode($state, true, flags: \JSON_THROW_ON_ERROR)) : null;
    }

    public function save(FlowState $state): void
    {
        $this->pdo->prepare('INSERT INTO acme_flow (id, state) VALUES (?, ?) ON CONFLICT (id) DO UPDATE SET state = excluded.state')
            ->execute([$state->id, json_encode($state->toArray(), \JSON_THROW_ON_ERROR)]);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM acme_flow WHERE id = ?')->execute([$id]);
    }
}
