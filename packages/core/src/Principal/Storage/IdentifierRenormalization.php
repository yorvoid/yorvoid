<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal\Storage;

use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\InvalidIdentifier;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Row;

/**
 * Brings the stored email addresses' compared forms up to the rules of this version, once after an upgrade
 * that changed them: 0.2 compares email addresses in one Unicode form, with only A–Z folded and the
 * domain in its ASCII form, where 0.1 folded case in every script. Only addresses beyond ASCII change.
 *
 * Two identifiers that the new rules make one, e.g. the same address written in two Unicode forms by
 * two people, are left as they are and reported: which of them keeps it is a person's decision.
 *
 * @api
 */
final readonly class IdentifierRenormalization
{
    public function __construct(
        private Database $database,
    ) {
    }

    /**
     * @param bool $dryRun Only report what would change
     *
     * @return array{changed: list<array{realm: string, from: string, to: string}>, conflicts: list<array{realm: string, value: string, with: string}>, invalid: list<array{realm: string, value: string}>}
     */
    public function run(bool $dryRun = false): array
    {
        $report = ['changed' => [], 'conflicts' => [], 'invalid' => []];
        $table = $this->database->table(DbalIdentifierStore::TABLE);
        $connection = $this->database->connection;

        // Only email addresses: usernames are compared as before.
        $connection->transactional(static function () use ($connection, $table, $dryRun, &$report): void {
            $rows = $connection->fetchAllAssociative(\sprintf('SELECT realm, normalized, value FROM %s WHERE kind = ? ORDER BY realm, created_at', $table), [IdentifierKind::Email->value]);
            /** @var array<string, array<string, int>> $taken Each realm's compared forms, as they'll be */
            $taken = [];

            foreach ($rows as $i => $row) {
                $taken[Row::string($row, 'realm')][Row::string($row, 'normalized')] = $i;
            }

            foreach ($rows as $i => $row) {
                $realm = Row::string($row, 'realm');
                $from = Row::string($row, 'normalized');
                $value = Row::string($row, 'value');

                try {
                    $to = IdentifierKind::Email->normalize($value);
                } catch (InvalidIdentifier) {
                    $report['invalid'][] = ['realm' => $realm, 'value' => $value];

                    continue;
                }

                if ($to === $from) {
                    continue;
                }

                if (isset($taken[$realm][$to])) {
                    $report['conflicts'][] = ['realm' => $realm, 'value' => $value, 'with' => $to];

                    continue;
                }

                unset($taken[$realm][$from]);
                $taken[$realm][$to] = $i;
                $report['changed'][] = ['realm' => $realm, 'from' => $from, 'to' => $to];

                if (!$dryRun) {
                    $connection->update($table, ['normalized' => $to], ['realm' => $realm, 'kind' => IdentifierKind::Email->value, 'normalized' => $from]);
                }
            }
        });

        return $report;
    }
}
