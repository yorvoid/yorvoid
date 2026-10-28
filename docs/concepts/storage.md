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

Its tables are named for what one row is, in the singular, starting with the concept they belong to: `principal`, `principal_identifier`, `authentication_session`, `one_time_token`, `realm_setup`, `security_log`. The names stand on their own without the prefix, and none is a word that SQL or your framework's own tables use (such as `session`).

Your own schema tools (e.g. Doctrine Migrations' diff) should leave YorvoID's tables alone. Give them `StorageLocation::owns()` as the schema assets filter:

```php
$connection->getConfiguration()->setSchemaAssetsFilter(static fn (string $name): bool => !$location->owns($name));
```

Without a prefix, only a schema of YorvoID's own tells its tables apart from yours.

## Transactions

YorvoID's changes go through `Transactions`: a change, its security log entry and its "-ing" events happen together or not at all, joining your transaction when one is open. "-ed" events run only once the outermost transaction committed, so a listener never hears about a change that was rolled back.

To know when your transaction commits, YorvoID needs its middleware on the connection. DBAL applies middlewares when it creates the connection, so add it first:

```php
use YorvoID\Core\Storage\Dbal\DbalTransactions;
use YorvoID\Core\Storage\Dbal\TransactionMiddleware;

$middleware = new TransactionMiddleware();
$configuration->setMiddlewares([...$configuration->getMiddlewares(), $middleware]);
$connection = DriverManager::getConnection($params, $configuration);
$transactions = new DbalTransactions($connection, $middleware);
```

Use one middleware per connection. The Symfony bridge does all of this for you. Without the middleware, YorvoID refuses to queue "-ed" events inside a transaction, rather than fire them before the commit.

If part of the work fails inside your transaction (a savepoint rolls back) and you carry on, the events of that part are dropped with it.

Listeners of "-ed" events may write in transactions of their own, e.g. a [notification](notifications.md) that issues a link. When the outermost transaction is YorvoID's (`$transactions->run()`), they run once DBAL has finished committing. When you commit a transaction yourself with `$connection->commit()`, they run during that commit, while DBAL still counts the transaction as open: such listeners can't start transactions then. So run work that YorvoID joins through `$transactions->run()`.

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
