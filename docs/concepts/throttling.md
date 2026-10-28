# Throttling

Throttling limits how often something may be tried: at most so many attempts per period, per key. Every server shares the counts through YorvoID's database, so no cache server is needed, and each attempt is counted in one atomic step, so concurrent requests can't slip past a limit. It's the `yorvoid/throttle` package, which `yorvoid/authn` uses for its forms; you can use it for your own.

```php
use YorvoID\Throttle\AddressKey;
use YorvoID\Throttle\Limit;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;

try {
    $throttle->hit('contact_form:'.AddressKey::of($request->getClientIp()), new Limit(5, Duration::hours(1)));
} catch (Throttled $throttled) {
    return new Response($throttled->getMessage(), 429, ['Retry-After' => $throttled->seconds]);
}
```

## Limits

`new Limit(10, Duration::minutes(15))` allows 10 attempts per 15 minutes. All 10 may come at once; after that, one more is allowed every 90 seconds (the period shared out), not all of them again after 15 minutes. So a limit **slows attempts down** rather than locking anyone out for long (ATN-041).

- `hit()` counts an attempt, or throws `Throttled` without counting it. `Throttled::$retryAfter` and `$seconds` say when the next attempt is allowed.
- `check()` tells whether an attempt would be allowed, without counting one.
- `reset()` forgets a key's attempts, e.g. after a success.
- `purge()` removes the state of keys that are clear again; run it daily.

It's the generic cell rate algorithm (GCRA): a key keeps one moment, when it will be clear of the attempts counted, and each attempt moves it on by one interval.

## Keys

A key is any string that says what is counted, e.g. `"sign_in:{account}:{address}"`. Keys are stored only as their SHA-256 hashes, since they hold addresses and account IDs.

For addresses, use `AddressKey::of($ip)`: an IPv4 address counts on its own (also when written as IPv6, `::ffff:192.0.2.1`), and an IPv6 address counts with its /64 network, since one client usually has a whole /64 to choose from.

## YorvoID's forms

`yorvoid/authn` counts attempts at the forms that check a secret through `Attempts`, per account and address, per account, and per address, as far as each is known:

| Limit | Default | |
|---|---|---|
| `perAccountAndAddress` | 10 per 15 minutes | Someone trying one account from one place |
| `perAccount` | 100 per hour | An attack on one account from many addresses slows down, without shutting the owner out for long |
| `perAddress` | 300 per 15 minutes | One address trying many accounts; room for an office behind one address |

Configure them with `new Attempts($throttle, new AttemptLimits(perAccount: new Limit(50, Duration::hours(1))))`.

- [Recovery codes](recovery-codes.md) count per account and address; a success forgets that account's attempts from that address.
- [Passkey sign-in](passkeys.md#signing-in) counts per address.
- [Setup codes](setup-codes.md) count per address.

Pass the client's address (`address:`) to these services: without it, only the account's limit applies, or none.

## Storage

`DbalThrottleStore` keeps one row per key in the `throttle_key` table, created by `ThrottleMigrations`. `InMemoryThrottleStore` is for tests.

Attempts are counted **outside transactions**: a transaction rolled back after a failed attempt would undo the count, and with it the limit. The database store refuses to count inside one. YorvoID's services count before their own transaction begins. If you call them inside yours, give the throttle a database connection of its own, to the same database.
