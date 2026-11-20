# Storage

YorvoID keeps its records in your application's own SQL database, through the Doctrine DBAL connection you give it. Its writes therefore join your transactions: when you suspend a user and update your own tables in one transaction, either both happen or neither does.

```php
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\StorageLocation;

$database = new Database($connection);                                    // tables "yorvoid_…"
$database = new Database($connection, new StorageLocation('yorvoid_', 'auth'));  // in the schema "auth"
```

## Databases

| Database | Status |
|----------|--------|
| PostgreSQL 16, 17, 18 | Supported; every test runs against each version |
| SQLite | For development and tests |
| MySQL 8.4, MariaDB 11 | Planned. Until they pass YorvoID's storage tests, YorvoID refuses them rather than half-supporting them |

## Where the tables live

A `StorageLocation` says where YorvoID's tables are:

- **a prefix** for its table and index names, `yorvoid_` by default: up to 20 lowercase letters, digits and underscores, or none;
- **a schema**, on PostgreSQL only: lowercase letters, digits and underscores, not starting with `pg_`. YorvoID creates it if it's missing.

YorvoID creates and uses only tables of its location. It never reads or changes your tables.

Its tables are named for what one row is, in the singular, starting with the concept they belong to: `principal`, `principal_identifier`, `authentication_session`, `one_time_token`, `realm_setup`, `security_log`. One table holds a single row: `installation`, the installation's random ID, which a shared cache tells installations apart by ([what a request reads](authorization.md#what-a-request-reads)). The names stand on their own without the prefix, and none is a word that SQL or your framework's own tables use (such as `session`).

Your own schema tools (e.g. Doctrine Migrations' diff) should leave YorvoID's tables alone. Give them `StorageLocation::owns()` as the schema assets filter:

```php
$connection->getConfiguration()->setSchemaAssetsFilter(static fn (string $name): bool => !$location->owns($name));
```

Without a prefix, only a schema of YorvoID's own tells its tables apart from yours.

## Transactions

YorvoID's changes go through `Transactions`: a change, its security log entry and its "-ing" events happen together or not at all, joining your transaction when one is open. "-ed" events run only once the outermost transaction committed, so a listener never hears about a change that was rolled back.

To know when your transaction commits, YorvoID needs its middleware on the connection. DBAL applies middlewares when it creates the connection, so add it first:

```php
use Doctrine\DBAL\DriverManager;
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;

$middleware = new TransactionMiddleware();
$configuration->setMiddlewares([...$configuration->getMiddlewares(), $middleware]);
$connection = DriverManager::getConnection($params, $configuration);
$transactions = new DbalTransactions($connection, $middleware);
```

Use one middleware per connection. The Symfony bridge does all of this for you. Without the middleware, YorvoID refuses to queue "-ed" events inside a transaction, rather than fire them before the commit.

If part of the work fails inside your transaction (a savepoint rolls back) and you carry on, the events of that part are dropped with it.

Listeners of "-ed" events may write in transactions of their own, e.g. a [notification](notifications.md) that issues a link. That works whoever commits: `$transactions->run()`, your own `$connection->commit()` or `transactional()`, Doctrine's `wrapInTransaction()`, or Messenger's `doctrine_transaction` middleware. After your commit, DBAL still counts your transaction as open while YorvoID's listeners run, so each runs in a transaction of its own on the connection. What a listener writes is committed when it returns, and undone if it fails.

A listener that fails after the commit doesn't fail the commit: your data is committed, and an exception would say otherwise and invite a retry. YorvoID logs the failure at error and runs the remaining listeners. What waits for the end of a transaction, e.g. disabling a copied passkey, raises the audit alert instead (`AuditFailed`, `after_end`), as does a [security notification](notifications.md) that couldn't be sent. A listener must end the transactions it begins: one it leaves open is rolled back and reported, and the connection stays usable. A listener that ends the transaction it runs in (a `commit()` or `rollBack()` with no transaction of its own open) ends only its own, and is reported too; the other listeners still run. If rolling back what a failed listener left fails as well, YorvoID closes the connection, and DBAL reconnects on its next use.

### When transactions conflict

Two transactions can get in each other's way: a deadlock, or a serialization failure under `REPEATABLE READ` or `SERIALIZABLE`. The database rolls one back, and YorvoID throws `RetryableConflict` (with the database's exception as its previous): nothing of that transaction is committed, and running the same work again usually succeeds.

- **YorvoID retries its own operations.** `Operations::run()` runs an operation, its decision included, up to 3 times, a few tens of milliseconds apart, when its transaction conflicts: each attempt is decided again, on what's committed then, so a permission taken away in the meantime refuses it. What a conflicted attempt left for after its commit or its end goes with it, since the next attempt leaves it again, and what the request read from the caches is forgotten. After the last attempt, or a failure that isn't a conflict, what waits for the end runs as after any rollback. Give `Operations` the `Transactions` (`transactions:`); the Symfony bundle does.
- **Inside your transaction, you retry.** When YorvoID's work runs in a transaction you opened, `transactional()`, `wrapInTransaction()` or Messenger's `doctrine_transaction`, the conflict rolls back all of it, your own changes too, so it's yours to run again: YorvoID throws `RetryableConflict` at once. Messenger's retry does that for a message. In Symfony, a request that ends with one gets 503 with `Retry-After`.
- **Work of your own** that should be retried the same way runs in `$transactions->retrying(fn () => ...)`. It does nothing outside the database except through `afterCommit()` and `afterEnd()`, or what's fine done twice: whatever else it does, it does again.

### Without a database

In tests, or an application that keeps nothing between runs, YorvoID's stores can live in memory. A failed change must then undo what it did in every store, as a database's rollback does: YorvoID relies on it, e.g. the last way to sign in can't be removed. Build them all at once, registered, with `InMemoryStorage` from `yorvoid/testing`:

```php
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\Principals;
use YorvoID\Testing\InMemoryStorage;

$memory = new InMemoryStorage();
$principals = new Principals($memory->principals, $memory->identifiers, new SecurityLog($memory->log, $memory->transactions), $memory->transactions);
```

A store built on its own and not registered with `InMemoryTransactions::register()` keeps half of a failed change.

## Stores of your own

Each kind of record is kept by a store behind an interface: `PrincipalStore`, `SessionStore`, `SecurityLogStore` and the others. YorvoID has one on DBAL and one in memory; you can write your own, e.g. for another database, as long as it keeps the same promises. `yorvoid/testing` has them as tests, one trait for each store, which YorvoID's own stores pass. A store of your own must pass its store's tests: run them in your test suite, and keep them passing. They're traits, so your test case keeps the base class it has:

```php
use App\Storage\PdoSessionStore;
use App\Storage\PdoTransactions;
use PHPUnit\Framework\TestCase;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Testing\Contract\SessionStoreContract;

final class PdoSessionStoreTest extends TestCase
{
    use SessionStoreContract;

    private \PDO $pdo;

    public static function implementations(): iterable
    {
        yield 'PDO' => ['pdo'];
    }

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');      // empty, new for each test
        PdoSessionStore::createTable($this->pdo);
    }

    protected function sessionStore(string $implementation): SessionStore
    {
        return new PdoSessionStore($this->pdo);
    }

    protected function transactions(string $implementation): Transactions
    {
        return new PdoTransactions($this->pdo);       // the ones the store takes part in
    }
}
```

Every contract but the flow store's runs the store inside the transactions you give it as well:
- a write in a transaction that fails is gone, versions included;
- a write is seen inside its own transaction;
- a write that changes nothing or is refused (a duplicate, a remove of nothing, a conditional write that loses) leaves the transaction usable. On PostgreSQL, a failed statement aborts the whole transaction, so a store must not let one fail on its way to "nothing to do": `INSERT … ON CONFLICT DO NOTHING` on the key it expects, for example.

The throttle's counts are the exception: they outlive a rollback, or the store refuses to count inside a transaction. So are decision records, which a store with a connection of its own writes apart: a digest lists them.

In Symfony, each store is a service under an ID of its own, `yorvoid.store.<name>`: `yorvoid.store.principal`, `yorvoid.store.identifier`, `yorvoid.store.security_log`, `yorvoid.store.session`, `yorvoid.store.known_device`, `yorvoid.store.one_time_token`, `yorvoid.store.setup`, `yorvoid.store.credential`, `yorvoid.store.recovery_code`, `yorvoid.store.cool_down`, `yorvoid.store.passkey`, `yorvoid.store.ceremony`, `yorvoid.store.throttle`, `yorvoid.store.policy`, `yorvoid.store.relation`, `yorvoid.store.attribute`, `yorvoid.store.decision_log` and `yorvoid.store.flow`. Use yours by aliasing its ID:

```yaml
# config/services.yaml
services:
    App\Storage\RedisSessionStore: ~
    yorvoid.store.session: '@App\Storage\RedisSessionStore'
```

Stores are for YorvoID's services, not yours ([interfaces you implement](../compatibility.md#interfaces-you-implement)): a service of yours that asks for `SessionStore` doesn't get one. `#[Autowire(service: 'yorvoid.store.session')]` would still reach it, which is why [YorvoID's PHPStan rules](../how-to/phpstan-rules.md) flag a call to a store.

There's one for each store (`AttributeStoreContract`, `CeremonyStoreContract`, `CoolDownStoreContract`, `CredentialStoreContract`, `DecisionLogStoreContract`, `FlowStoreContract`, `IdentifierStoreContract`, `KnownDeviceStoreContract`, `OneTimeTokenStoreContract`, `PasskeyStoreContract`, `PolicyStoreContract`, `RelationStoreContract`, `PrincipalStoreContract`, `RecoveryCodeStoreContract`, `SecurityLogStoreContract`, `SessionStoreContract`, `SetupStoreContract`, `ThrottleStoreContract`), and `TransactionsContract` for your own `Transactions`. Each asks for the transactions the store takes part in (`transactions()`): the same instance within a test. A `CredentialStore` writes only what each change is about, so that a credential disabled since someone read it stays disabled: `CredentialStoreContract` checks it with a second instance (`sameCredentialStore()`). A `PolicyStore` or `RelationStore` reads its `version()` from what it stores, never a counter of the process's own, and every change to relations increments the realm's relations version: `PolicyStoreContract` and `RelationStoreContract` check it with a second instance over the same data (`samePolicyStore()`, `sameRelationStore()`). Each of these helpers gives the same instance unless you override it; override it for a store processes share. Use one in each test case: their helpers have the same names. A store that doesn't pass them isn't one YorvoID can rely on: what YorvoID promises, e.g. one use of a token among requests at the same moment, rests on what the store does.

When your store can't reach what it keeps its records in (the connection is refused or lost, a request times out, a failover is in progress), it throws `YorvoID\Core\Storage\StoreUnreachable`, so a decision that needed it is Indeterminate for that reason, and not for an error ([authorization](authorization.md#when-a-store-cant-be-reached)). The contracts can't make your store unreachable, so this one is yours to keep.

### Requests at the same moment

What a store keeps among many requests at once (one use of a token, one claim of a realm, a log that never forks) has a contract of its own, run in several processes: `OneTimeTokenStoreConcurrencyContract`, `RecoveryCodeStoreConcurrencyContract`, `SetupStoreConcurrencyContract`, `CeremonyStoreConcurrencyContract`, `ThrottleStoreConcurrencyContract`, `PrincipalStoreConcurrencyContract`, `SessionStoreConcurrencyContract`, `SecurityLogStoreConcurrencyContract`, and `VerifierStoreConcurrencyContract` for the [verifier](signed-intents.md). A store of your own that has one must pass it too. Its store contract fails until the test case uses it, names the test case that does with `#[ConcurrencyTestedIn(...)]`, or says its stores are in memory (`#[InMemoryStores]`): those are one process's alone.

The processes get their stores from a factory file of yours, which `YorvoID\Testing\Race` runs in each:

```php
// tests/race-stores.php
use App\Storage\PdoSessionStore;
use YorvoID\Authn\Session\SessionStore;

return static function (string $type, string $implementation, string $test): object {
    // The same $test reaches the same records, in every process; a new one starts empty.
    $pdo = new \PDO('pgsql:host=db;dbname=test_'.$test);

    return match ($type) {
        SessionStore::class => new PdoSessionStore($pdo),
        // ... and Transactions::class: the transactions the stores take part in, on the same connection
    };
};
```

```php
use PHPUnit\Framework\TestCase;
use YorvoID\Testing\Contract\SessionStoreConcurrencyContract;

final class PdoStoreConcurrencyTest extends TestCase
{
    use SessionStoreConcurrencyContract;

    public static function implementations(): iterable
    {
        yield 'PDO on PostgreSQL' => ['pgsql'];
    }

    protected function storeFactory(): string
    {
        return __DIR__.'/race-stores.php';
    }
}
```

Each race runs 20 rounds. In each round, every process first does what comes before the race (e.g. finds the token), then all act at once. A race of two calls is forced that way: a store that finds and then deletes, without the delete being the check, fails every round. A race of one call (use a code, claim a realm) is decided by the database, so a store that checks and then acts fails it often, not always. Making that act one atomic statement is the store's duty.

## Migrations

YorvoID's tables are created and changed by its own migrations, separately from your application's. Their history is kept in YorvoID's location (`yorvoid_migration`).

```php
use YorvoID\Core\Storage\Migration\Migrator;

$migrator = new Migrator($database, $migrations);
$migrator->status();    // every migration: pending, executed, or executed by a newer version
$migrator->sql();       // the SQL that would run, for a database administrator to review or run by hand
$migrator->migrate();   // runs what's pending
```

- **One process at a time:** on PostgreSQL, a lock of the location makes a second process wait (one minute by default) and then give up, so two deployments can't migrate at once. YorvoID installations in different locations don't wait for each other.
- **All or nothing per migration:** each runs in a transaction with its history row, so a failed migration leaves nothing behind.
- **Forward only:** a mistake is fixed by the next migration. Rolling a schema back under a running application loses data or breaks the previous version.
- **Not inside a transaction:** run migrations from a deployment step, not from application code inside a transaction.
- **Versions** are the time a migration was written plus what it does, e.g. `20261027180000_core_security_log`, at most 191 characters; descriptions at most 255. Migrations of all packages run in the order of their versions.

## Upgrading without downtime

Every migration declares what it does to tables in use:

- **Online:** nothing blocks reads or writes for more than an instant (creating tables, adding nullable columns, inserting rows). Upgrades run while the previous version keeps serving requests.
- **Blocking:** it blocks a table in use while it runs, e.g. rewriting a large table. `migrate()` refuses to run it, and runs nothing at all, unless you allow it explicitly (`migrate(allowBlocking: true)`) in a maintenance window.

YorvoID's own migrations aim to be online. A blocking one is announced in the release notes with how long it may take.
