# Setup codes

A fresh installation has no one who can sign in. Instead of a default account with a known password, whoever installs the application gets a **setup code**, and whoever enters it first becomes the first principal of the realm. They're in the `yorvoid/authn` package.

```php
use YorvoID\Authn\Setup\CreateSetupCode;

$code = $setupCodes->create(new CreateSetupCode(Realm::default(), Duration::days(1)), new Origin(OriginKind::Console, 'app:install'));

$output->writeln('Open https://example.com/setup and enter '.$code->reveal()); // e.g. 7KQ2M-W9XHE-…
```

- A code is 130 random bits, written as 26 characters in groups of five. Case, hyphens and spaces don't matter, and O, I and L count as 0, 1 and 1, so it can be read aloud and typed.
- Only its hash is kept. Creating a new code replaces the old one.
- A realm is claimed **once**. After that, creating a code throws `RealmAlreadyClaimed`: someone who lost access recovers it with a [recovery link](recovery-links.md).

## Claiming a realm

```php
$transactions->run(function () use ($setupCodes, $code): void {
    $owner = $setupCodes->claim(
        Realm::default(),
        Secret::fromString($code),
        new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com'),
        new Origin(OriginKind::Web, 'setup'),
    );

    $yourRoles->grantOwner($owner->id); // in the same transaction
});
```

`claim()` checks the code, creates the principal (active, in the realm being claimed), and marks the realm claimed, in one transaction. Give the principal its authority in the same transaction, as above: if that fails, the realm stays unclaimed and the code keeps working.

A code that doesn't work throws `InvalidSetupCode`, without saying why.

Pass the client's address (`address: $request->getClientIp()`) so attempts from one address are [throttled](throttling.md): at 130 bits, guessing isn't practical, but limiting attempts costs nothing. Inside your own transaction, as above, the throttle needs a database connection of its own (see [storage](throttling.md#storage)).
