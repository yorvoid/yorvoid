<?php

declare(strict_types=1);

namespace YorvoID\Core\Storage\Dbal;

use YorvoID\Core\Storage\StorageLocation;

/**
 * Checks, in the tests, that each transaction takes YorvoID's locks in the one order that can't
 * deadlock (D-52), from the statements it sends:
 *
 * 1. the realm's lock is the first lock of its transaction, or isn't taken;
 * 2. a version row is written only under the realm's lock;
 * 3. nothing is locked after the security log's head, which is taken at the commit.
 *
 * Every write, and every SELECT ... FOR UPDATE or FOR SHARE, of a YorvoID table is a lock. Under the
 * realm's lock, version rows and other rows may come in either order: only holders of the realm's lock
 * write version rows, and they take turns. That a transaction without it locks the rows of one
 * principal only is the rule too, which this doesn't check: the statements don't say whose rows they
 * are. Nor does it see what the database locks by itself, e.g. a parent row for a foreign key, or a
 * trigger's writes. Only YorvoID's version tables are version rows: an application's own HoldsAuthority
 * store's are ranked as other rows, so rule 2 doesn't cover them. A statement it doesn't know not to
 * lock is ranked by its table, or throws.
 *
 * On with YORVOID_LOCK_ORDER=check, as the test suite sets; production doesn't check.
 *
 * @internal
 */
final class LockOrder
{
    public const string VARIABLE = 'YORVOID_LOCK_ORDER';

    private const string REALM = 'realm';

    private const string VERSION = 'version';

    private const string ROW = 'row';

    private const string HEAD = 'head';

    private const array RANKS = [
        DbalTransactions::REALM_LOCK_TABLE => self::REALM,
        'versions' => self::VERSION,
        'security_log_head' => self::HEAD,
        'security_log' => self::HEAD,
    ];

    /** YorvoID's own bookkeeping, outside the order: written apart, or never inside a unit of work */
    private const array APART = ['migration', 'installation', 'throttle_key'];

    /** @var list<array{bool, bool, bool}> For each transaction open, the outermost first: whether it locked anything, holds the realm's lock, has taken the head */
    private array $transactions = [];

    public function __construct(
        private readonly StorageLocation $location,
    ) {
    }

    public static function fromEnvironment(StorageLocation $location): ?self
    {
        return 'check' === getenv(self::VARIABLE) ? new self($location) : null;
    }

    public function begin(): void
    {
        $this->transactions[] = [false, false, false];
    }

    public function end(): void
    {
        array_pop($this->transactions);
    }

    /**
     * @throws \LogicException When the statement takes a lock out of order, or is a write it can't place
     */
    public function saw(string $sql): void
    {
        if ([] === $this->transactions) {
            return;
        }

        $rank = $this->rank($sql);

        if (null === $rank) {
            return;
        }

        $last = array_key_last($this->transactions);
        [$locked, $realm, $head] = $this->transactions[$last];

        if ($head && self::HEAD !== $rank) {
            throw new \LogicException(\sprintf('A lock after the security log\'s head, which comes last, at the commit (D-52): %s', $sql));
        }

        if (self::REALM === $rank && $locked && !$realm) {
            throw new \LogicException(\sprintf('The realm\'s lock after another lock: it\'s the first lock of its unit of work, or isn\'t taken (D-52): %s', $sql));
        }

        if (self::VERSION === $rank && !$realm) {
            throw new \LogicException(\sprintf('A version row written without the realm\'s lock, which every change of authority takes first (D-52): %s', $sql));
        }

        $this->transactions[$last] = [true, $realm || self::REALM === $rank, $head || self::HEAD === $rank];
    }

    /**
     * What the statement locks: null only for what's known not to lock a row, or for another
     * application's table. Anything else is ranked by the table it writes or locks, or throws.
     *
     * @throws \LogicException When it may lock and the table can't be told
     */
    private function rank(string $sql): ?string
    {
        // Leading comments and parentheses don't change what it does.
        $statement = (string) preg_replace('/^(?:\s+|\(|--[^\n]*(?:\n|$)|\/\*.*?\*\/)+/s', '', $sql);
        $keyword = strtoupper((string) strtok($statement, " \t\n\r("));
        $table = '(?:"[^"]+"|`[^`]+`|[\w$]+)(?:\.(?:"[^"]+"|`[^`]+`|[\w$]+))?';

        if (\in_array($keyword, ['SAVEPOINT', 'RELEASE', 'ROLLBACK', 'COMMIT', 'BEGIN', 'START', 'END', 'SET', 'SHOW', 'RESET', 'PRAGMA', 'CREATE', 'ALTER', 'DROP', 'COMMENT', 'GRANT', 'REVOKE', 'ANALYZE', 'VACUUM'], true)) {
            return null;
        }

        if ('SELECT' === $keyword || 'WITH' === $keyword) {
            $locking = '/\bFOR\s+(?:NO\s+KEY\s+UPDATE|UPDATE|KEY\s+SHARE|SHARE)\b/i';

            // A writable CTE: YorvoID writes none, and the table it writes can't be told reliably.
            if (1 === preg_match('/\b(?:INSERT|UPDATE|DELETE|MERGE)\b/i', (string) preg_replace($locking, '', $statement))) {
                throw new \LogicException(\sprintf('A statement that writes inside a query, which the lock order can\'t place (D-52): %s', $sql));
            }

            if (1 !== preg_match($locking, $statement)) {
                return null;
            }

            $found = preg_match('/\bFROM\s+('.$table.')/i', $statement, $target);
        } else {
            // Any other statement is a write of the table it names first, or can't be placed.
            $found = preg_match('/^(?:INSERT(?:\s+OR\s+\w+)?\s+INTO|UPDATE|DELETE\s+FROM|MERGE\s+INTO|TRUNCATE(?:\s+TABLE)?|LOCK(?:\s+TABLE)?)\s+(?:ONLY\s+)?('.$table.')/i', $statement, $target);
        }

        if (1 !== $found) {
            throw new \LogicException(\sprintf('A statement the lock order can\'t place, which may lock (D-52): %s', $sql));
        }

        $parts = explode('.', $target[1]);
        $name = trim(end($parts), '"`');

        if ('' !== $this->location->prefix && !str_starts_with($name, $this->location->prefix)) {
            return null;
        }

        $name = substr($name, \strlen($this->location->prefix));

        if (\in_array($name, self::APART, true)) {
            return null;
        }

        return self::RANKS[$name] ?? self::ROW;
    }

    /**
     * A new connection has no transaction open: e.g. after a lost one was closed.
     */
    public function reset(): void
    {
        $this->transactions = [];
    }
}
