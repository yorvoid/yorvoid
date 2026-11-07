# Authorization

Every operation runs through `Operations::run()` ([operations](operations-and-events.md#running-an-operation)), which asks the evaluator whether the actor may. The evaluator weighs the policy's elements and the actor's authority, and gives one of three outcomes:

- **Permit:** the operation runs.
- **Deny:** YorvoID knows it isn't allowed now. When only evidence is missing, e.g. a recent step-up, the decision says what would satisfy it (a remedy).
- **Indeterminate:** something the decision needs couldn't be established, e.g. a record is missing or a store is unreachable. It never permits, and it's logged as a warning: someone should look.

A fourth, **Permit-offline**, exists only where you've set it up for a store that can't be reached ([offline](#offline)).

A refusal throws `NotPermitted`, which carries the decision, and is recorded in the security log with its verdict, reason, remedies and what couldn't be established.

## How it decides

The policy is a set of **elements**: permits, which let a request through, and forbids, which refuse it. Each says which requests it's about (its target) and whether it applies to this one (its condition): holds, fails or unknown. The **authority** check says whether the actor may act at all: valid, invalid or unknown.

The first row that applies decides:

| # | When | Outcome |
|---|---|---|
| 1 | A forbid holds | Deny |
| 2 | A forbid is unknown | Indeterminate: a forbid can't be ruled out |
| 3 | The authority is invalid | Deny |
| 4 | The authority is unknown | Indeterminate |
| 5 | A permit holds | Permit |
| 6 | A permit is unknown | Indeterminate |
| 7 | Otherwise | Deny, with the remedies of the permits that failed |

Every element that's about the request is evaluated, so neither the outcome nor its reasons depend on the order of the elements. An element that throws counts as unknown (`evaluation_error`), never as holding. The table is checked against [the formal model](https://github.com/yorvoid/yorvoid/blob/main/spec/decisions/precedence.als).

Row 2 gives Indeterminate even when nothing could permit the request: an unknown forbid is reported, and the reason says the request would be refused regardless.

## The built-in permits

YorvoID's ways to authorize, which each operation names in `#[Operation(authorizedBy: ...)]`, are permits:

| Permit | Holds when |
|---|---|
| `Authentication` | Always: the handler checks the secret the person brings, e.g. a passkey or a recovery code |
| `Operator` | The host's `Operator`, from a console command or a job on the command line, or YorvoID itself |
| `Self` | The actor's own account, in its realm, with what the self-service policy asks, e.g. a recent step-up (`StepUpSelfService`) |
| `Ability` | The actor holds the operation's ability, as the `AbilityPolicy` says. Without an ability policy, there's no ability permit |

A permit speaks only for the operations that name its way to authorize: an ability never permits an operation only the host's operator runs. When several permits hold, the decision's basis is the first the operation names.

## Authority

`PrincipalAuthority` checks the actor's own authority: a principal that exists, is active and acts in the command's realm. A suspended or deleted principal is denied whatever the permits say; one that isn't on record is Indeterminate (`missing_data`). The host's operator and someone not signed in claim no authority, so there's nothing to invalidate.

## Remedies

A Deny caused only by missing evidence carries remedies: what could satisfy the requirement, never a permission by itself. Satisfying one creates evidence, and the next request is a new decision.

| Remedy | Asks for |
|---|---|
| `StepUpRemedy` | A recent proof of who you are: within a time, and possibly two factors or a phishing-resistant one |
| `WaitRemedy` | Trying again after a time, e.g. after the cool-down that follows an account recovery |

```php
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Operation\NotPermitted;

try {
    $operations->run($command, $session, $origin);
} catch (NotPermitted $refused) {
    foreach ($refused->decision->remedies as $remedy) {
        if ($remedy instanceof StepUpRemedy) {
            // Ask for a passkey again, then retry.
        }
    }
}
```

## Why it couldn't decide

Every Indeterminate names what couldn't be established (`IndeterminateReason`): `store_unreachable`, `unknown_forbid`, `unverifiable_signature`, `missing_data`, `conflicting_records` or `evaluation_error`. New reasons can come in a minor release, so a `match` of yours on them needs a default arm.

## When a store can't be reached

A store that can't be reached makes the decision Indeterminate with the reason `store_unreachable`, never a Permit: the connection was refused or lost, a statement timed out, or the server is starting, failing over or shutting down. That's told apart from other failures (`evaluation_error`) and from records that aren't there (`missing_data`). Waiting for a lock is contention, not unreachability.

YorvoID's DBAL stores report it through DBAL's exceptions, which `StoreUnreachable::of()` recognises; a store of your own throws `StoreUnreachable` itself ([stores of your own](storage.md#stores-of-your-own)).

## Offline

Some deployments must keep a few operations going while the store they decide from is out of reach, e.g. a door controller whose link home is down. That's off by default, and stays off unless both sides say so when YorvoID is built:

- **An `OfflineDecider`**, given to the evaluator (`Evaluator::builtIn(..., offline: $decider)`; in Symfony, a service with that interface). It's asked only when a decision is Indeterminate and every reason is `store_unreachable`, never for a forbid that couldn't be ruled out, and only where a permit could have applied.
- **The operations that accept it**, `#[Operation(..., acceptsOffline: true)]`: only these run on what the decider allows. There's no switch for all of them.

What the decider allows is **Permit-offline**, a verdict of its own, not a Permit: `Decision::permits()` is false for it, and only an operation that accepts it runs. Its permit says so (`$permit->offline`, as do its consequences), the security log records `yorvoid.decision.offline`, and it's logged as a warning. A decider without such an operation, or such an operation without a decider, fails when `Operations` is built.

## Policy elements

Your own rules are elements: a class implementing `PermitElement` (with the way it authorizes by) or `ForbidElement`, with a stable name for reasons and the log.

```php
use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;

final readonly class NoBadgesAtNight implements ForbidElement
{
    public function name(): string
    {
        return 'acme.badges.no_night_issue';
    }

    public function matches(Request $request): bool
    {
        return 'acme.badge.issue' === $request->operation?->name;
    }

    public function evaluate(Request $request): Truth
    {
        $hour = (int) $request->at->format('G');

        return $hour < 6 ? Truth::holds('Badges aren\'t issued at night.') : Truth::fails('It\'s daytime.');
    }
}
```

Read the time from `$request->at`: one moment for the whole decision. In Symfony, a service implementing `PolicyElement` is added to the evaluator by itself; in plain PHP, pass it to `Evaluator::builtIn(..., elements: [new NoBadgesAtNight()])`. Two elements with one name, or an element that's both a permit and a forbid, fail when the evaluator is built.
