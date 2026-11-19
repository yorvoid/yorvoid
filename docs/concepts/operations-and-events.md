# Operations and events

Everything that changes YorvoID's state is an **operation**: a command object, handled by a service, inside one transaction. Each operation dispatches events, and is recorded in the security log. The rest is recorded on the way, or is bookkeeping: what a sign-in or a step-up writes is logged as part of it, a session's new token too (`Sessions::refresh()`), and the last time a session or a credential was used isn't. Stores are written only through these ([interfaces you implement](../compatibility.md#interfaces-you-implement)).

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

- **The actor** is a signed-in `Session`; `Anonymous` for what someone not signed in does, e.g. signing in or accepting an invitation; or the host's `Operator` ([actors](#actors)). Your code says who the actor is: never take it from the request.
- **The decision** is the evaluator's ([authorization](authorization.md)): Permit, Deny or Indeterminate. Only Permit runs anything; otherwise `run()` throws `NotPermitted`, with the decision: its reasons, and on a Deny what could change it, e.g. a step-up. Indeterminate means something the decision needs couldn't be established, and never permits.
- **Abilities** are decided by roles ([roles](authorization.md#roles)): give the evaluator the role permit (`RolePermit`); the Symfony bundle does. An `AbilityPolicy` of your own can decide them too. Without either, no ability permits anything.

## The decision point

`Operations` asks the `Authorization` it's given everything it needs, and nothing else: `decide()` for an operation, `decideConsequence()` for each of its consequences, and, when it's built, whether the policy has the forbids a handler needs (`requireForbids()`), whether it can decide every operation in the catalogue (`checkCatalogue()`), and whether it decides offline (`decidesOffline()`). So the Evaluator can be decorated, to trace decisions or to refuse more, and keep every check. Extend `AuthorizationDecorator`, and override what you add to:

```php
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizationDecorator;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationDescription;

final class OnlyOnWeekdays extends AuthorizationDecorator
{
    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        $decision = parent::decide($operation, $command, $actor, $origin);

        return $this->weekend() ? Decision::deny('Not on weekends.') : $decision;
    }
}
```

- **A decorator can narrow a decision, never permit.** Only the Evaluator makes a Decision that permits: a decorator returns the Evaluator's decision, or a `Decision::deny()` or `Decision::indeterminate()` of its own. A permit always has a decision the decision log recorded.
- **A decision permits once, for what it was asked about.** The Evaluator makes each decision for the command and the actor it was given, the very objects, and `Operations` issues one permit on it: a decision given back for another command or actor, e.g. from a cache, or a second time, throws an `IntegratorError`. So does a consequence answered with its operation's decision. Only YorvoID puts an ID or a record's hash on a decision that permits, and a `Decision` is never unserialized: what's kept is its record.
- **An `Authorization` of your own** that doesn't decorate the Evaluator implements all five: `requireForbids()` throws, since it can't check forbids, and `decidesOffline()` is false. It can't permit either, so it refuses everything or wraps an Evaluator.
- **One decision point per application.** The bundle builds `Operations`; an application without it builds it once, in its composition root. A second one, over an evaluator that records nothing, would issue permits the decision log never saw: `yorvoid:log:verify --decisions` reports every entry that cites a decision the log doesn't hold as a permit, and the PHPStan rule `yorvoid.decisionPoint` flags `new Operations(...)` in your code ([PHPStan rules](../how-to/phpstan-rules.md)).

## Actors

Every decision proves its actor before it reads it (D-45):

- **A `Session`** is read from its store, for every decision: the decision reads the session as recorded, its authentication factors included, never the object it was given. A session that isn't on record, has ended or has expired, or is another principal's, is refused. The Symfony bridge does this. In plain PHP, give the evaluator `actors: [new StoredSessions($sessionStore)]`.
- **The host's `Operator`** acts from a console command or a job only when PHP runs from the command line, so a web request that says it's the console is refused. Give it a realm (`new Operator(realm: $realm)`) when the work is about one; without, it acts in every realm. Only YorvoID's own work is `OriginKind::System`, e.g. a notification's token: `new Origin(OriginKind::System)` throws, so give your background work the `Job` or `Console` origin.
- **An `ActingPrincipal`** is a principal YorvoID just verified, e.g. through an invitation, a recovery or a setup code, acting in that operation's consequences only. `Operations::run()` refuses it. In a decision of your own, e.g. a policy test, it answers "would this principal be permitted", and proves nothing.
- **An actor class of your own** needs a `ProvesActors` that reads it from where it's recorded, given to the evaluator in `actors:` (in Symfony, a service tagged `yorvoid.actor_prover`). Without one, its decisions are Indeterminate (`unproven_actor`), and the first is logged as a warning.

The origin's kind says where a request came from. The Symfony bridge marks a request `api` when it carries an `Authorization: Bearer` header or its path starts with one of `yorvoid.api.paths`, and `web` otherwise. The request says this itself, so use `origin.kind` in a rule only to narrow what's permitted (refuse, or require a step-up), never to permit.

## Permits

A handler takes a `Permit` instead of an actor. Only `Operations::run()` issues one, after a Permit decision, for one command and one run: the handler calls `$permit->use($command)` before it changes anything, which fails for another command or a second use. So a handler can't be called around the decision.

What an operation causes runs on a consequence of its permit, e.g. a recovery ending every session: `$permit->consequence($command)` gives a permit for that command, in the same realm. The `Authorization` that issued the operation's permit decides the consequence: the operation's decision holds, so its permits aren't asked again, but the actor is proven again, and a forbid that implements `AppliesToConsequences` is evaluated for it. A consequence it refuses throws `NotPermitted`, and the operation fails with it. Each consequence's decision is recorded under the operation's (its record's `parent`). Only for what the operation declares it causes, `#[Operation(..., causes: ['yorvoid.session.end_all'])]`, and each as many times as it's named there: once, unless it's named twice. A consequence about a principal (a command implementing `ConcernsPrincipal`, e.g. `IssueToken` or `EndSessions`) must be about the operation's own principal: its command's, if that's about one, or the one its handler names with `$permit->about($principalId)` once it knows it, e.g. the principal it created, or whose token it used. Until then, nothing about anyone runs on the permit.

A permit holds the decision point that issued it, for its consequences: it's for the request it was issued in, and is never serialized.

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
