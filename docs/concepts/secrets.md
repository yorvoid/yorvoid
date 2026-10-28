# Secrets

Tokens, recovery codes and keys are held in a `Secret`, never in a plain string.

```php
use YorvoID\Core\Secret;

$token = Secret::random();            // 32 random bytes from the operating system
$given = Secret::fromString($input);

if ($token->equals($given)) {         // constant time
    // …
}
```

A `Secret`:

- **is compared only in constant time.** `equals()` uses `hash_equals()`, so the time a comparison takes doesn't tell an attacker how much of a guess was right.
- **can't leak by accident.** `var_dump()`, `print_r()` and debuggers show `[redacted]`; `json_encode()` gives `{}`; it can't be serialized; and it never appears in stack traces.
- **comes from a secure generator when it's random:** `Secret::random()` uses `random_bytes()`, with at least 16 bytes (128 bits).

`reveal()` returns the raw value, for the few places that need it: hashing it before storage, or sending it to its owner once.

## What YorvoID's own code is held to

YorvoID's PHPStan configuration refuses:

- comparing a revealed secret with `==`, `===`, `!=`, `!==`, `<=>` or the `strcmp()` family;
- predictable generators (`rand()`, `mt_rand()`, `uniqid()`, `shuffle()`, …);
- weak hashes (`md5()`, `sha1()`, `crc32()`);
- debug output (`var_dump()`, `print_r()`, `error_log()`) outside tests.

You can use the same rule in your application: add `YorvoID\Tools\PHPStan\SecretComparisonRule` to your PHPStan rules once YorvoID ships its PHPStan extension.
