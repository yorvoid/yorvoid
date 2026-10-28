# Operations and events

Everything that changes YorvoID's state is an **operation**: a command object, handled by a service, inside one transaction. Each operation dispatches events, and is recorded in the security log.

## Operations

A command class is marked with `#[Operation]`:

```php
use YorvoID\Core\Operation\Operation;

#[Operation('yorvoid.principal.suspend', 'Suspend a principal', ability: 'yorvoid.principals.manage', inverse: 'yorvoid.principal.activate')]
final readonly class SuspendPrincipal
{
    public function __construct(public string $principalId) {}
}
```

- **The name** is stable and dot-separated, starting with its owner: `yorvoid.…` for YorvoID's own, your prefix for yours.
- **The ability** is what a principal needs to run it.
- **The inverse** is the operation that undoes it, if any.

`OperationCatalogue` lists every operation with its typed parameters, so your application can build its own action catalogue, admin pages or console commands from it. Parameters marked `#[\SensitiveParameter]` are flagged, so they're never logged, shown or put on a command line. Mistakes fail when the catalogue is built: a command without the attribute, two operations with one name, or an inverse that isn't installed.

## Events

Each operation dispatches PSR-14 events through your application's event dispatcher:

- **"-ing" events** (e.g. *principal suspending*) run inside the transaction, before the change takes effect. Some can be cancelled (`CancellableEvent::cancel($reason)`), which stops the other listeners and rolls the operation back.
- **"-ed" events** (e.g. *principal suspended*) run after the transaction committed. Use them for emails and your own audit log.

## Origins

Every event and log entry records where the operation came from, as an `Origin`:

- **the kind:** `web`, `api`, `console`, `job` or `system`;
- **the detail:** what ran, e.g. a route name or a console command. Never a URL with a query string, which can contain tokens;
- **a correlation ID** shared by everything one request, command or job does, so an audit groups a bulk action into one story. Pass your request ID, or let YorvoID create a random one. It's limited to letters, digits, dots, underscores, colons and hyphens, so it can't forge lines in a log.
