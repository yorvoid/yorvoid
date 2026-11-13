# Operations and events

Everything that changes YorvoID's state is an **operation**: a command object, handled by a service, inside one transaction. Each operation dispatches events, and is recorded in the security log.

## Operations

A command class is marked with `#[Operation]` and implements `Command`:

```php
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/** @implements Command<Principal> */
#[Operation('yorvoid.principal.suspend', 'Suspend a principal', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage', inverse: 'yorvoid.principal.activate', causes: ['yorvoid.session.end_all'])]
final readonly class SuspendPrincipal implements Command, ConcernsPrincipal
{
    public function __construct(public Realm $realm, public PrincipalId $principalId) {}
}
```

- **The name** is stable and dot-separated, starting with its owner: `yorvoid.…` for YorvoID's own, your prefix for yours.
- **What authorizes it** is one or more of these; the first that permits wins:
  - `Ability`: an actor holding **the ability** on the subject, as your policy says (see [Running an operation](#running-an-operation));
  - `Self`: the actor's own account; the command implements `SelfOperation` and says whether it concerns the actor;
  - `Authentication`: the operation *is* authentication, and its handler checks the secret, token or ceremony itself;
  - `Operator`: the host's operator, from a console command, a job or YorvoID itself;
  - `Consequence`: only as a consequence of another operation, e.g. starting a session after a sign-in.
- **The inverse** is the operation that undoes it, if any.
- **What it causes** (`causes`) are the operations it runs as its consequences, if any (see [permits](#permits)).

`OperationCatalogue` lists every operation with its typed parameters, so your application can build its own action catalogue, admin pages or console commands from it. Parameters marked `#[\SensitiveParameter]` are flagged, so they're never logged, shown or put on a command line. Mistakes fail when the catalogue is built: a command without the attribute, two operations with one name, an inverse that isn't installed, an ability without `AuthorizedBy::Ability` or the other way round, or a `Self` operation that doesn't say whose account it concerns.

## Running an operation

`Operations::run()` is the way into every operation. It asks the `Authorization` whether the actor may run it, records a refusal in the security log, and on a Permit calls the operation's handler:

```php
use YorvoID\Core\Principal\SuspendPrincipal;

$principal = $operations->run(new SuspendPrincipal($realm, $principalId), $session, $origin);
```

- **The actor** is a signed-in `Session`; `Anonymous` for what someone not signed in does, e.g. signing in or accepting an invitation; or the host's `Operator`. The operator acts from a console command or a job only when PHP runs from the command line, so a web request that says it's the console is refused; YorvoID itself (`OriginKind::System`) acts from anywhere. Your code says who the actor is: never take it from the request.
- **The decision** is the evaluator's ([authorization](authorization.md)): Permit, Deny or Indeterminate. Only Permit runs anything; otherwise `run()` throws `NotPermitted`, with the decision: its reasons, and on a Deny what could change it, e.g. a step-up. Indeterminate means something the decision needs couldn't be established, and never permits.
- **Abilities** are decided by roles ([roles](authorization.md#roles)): give the evaluator the role permit (`RolePermit`); the Symfony bundle does. An `AbilityPolicy` of your own can decide them too. Without either, no ability permits anything.

## Permits

A handler takes a `Permit` instead of an actor. Only `Operations::run()` issues one, after a Permit decision, for one command and one run: the handler calls `$permit->use($command)` before it changes anything, which fails for another command or a second use. So a handler can't be called around the decision.

What an operation causes runs on a consequence of its permit, e.g. a recovery ending every session: `$permit->consequence($command)` gives a permit for that command, in the same realm, recorded under the same decision rather than decided again. Only for what the operation declares it causes, `#[Operation(..., causes: ['yorvoid.session.end_all'])]`, and each as many times as it's named there: once, unless it's named twice. A consequence about a principal (a command implementing `ConcernsPrincipal`, e.g. `IssueToken` or `EndSessions`) must be about the operation's own principal: its command's, if that's about one, or the one its handler names with `$permit->about($principalId)` once it knows it, e.g. the principal it created, or whose token it used. Until then, nothing about anyone runs on the permit.

Principals' events carry their operation's permit, so a listener can run with it what the operation causes, about the same principal, and only what the operation hasn't run already. Whatever runs a consequence first uses it up: a second try fails with an `IntegratorError` naming it. Your listeners run your own operations through `Operations::run()`.

Your own operations plug in the same way: a command with `#[Operation]`, and a service implementing `OperationHandler` that maps each command to a method taking the command, a `Permit` and the `Origin`.

## Events

Each operation dispatches PSR-14 events through your application's event dispatcher:

- **"-ing" events** (e.g. *principal suspending*) run inside the transaction, before the change takes effect. Some can be cancelled (`CancellableEvent::cancel($reason)`), which stops the other listeners and rolls the operation back.
- **"-ed" events** (e.g. *principal suspended*) run after the transaction committed. Use them for emails and your own audit log.

## Origins

Every event and log entry records where the operation came from, as an `Origin`:

- **the kind:** `web`, `api`, `console`, `job` or `system`;
- **the detail:** what ran, e.g. a route name or a console command. Never a URL with a query string, which can contain tokens;
- **a correlation ID** shared by everything one request, command or job does, so an audit groups a bulk action into one story. Pass your request ID, or let YorvoID create a random one. It's limited to letters, digits, dots, underscores, colons and hyphens, so it can't forge lines in a log.
