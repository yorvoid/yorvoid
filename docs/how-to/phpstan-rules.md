# Check your application with YorvoID's PHPStan rules

YorvoID's stores and its `@internal` code are YorvoID's own: your application uses its services, which decide, log and keep things consistent ([compatibility](../compatibility.md)). Two [PHPStan](https://phpstan.org) rules flag your code where it reaches past them. For development only:

```bash
composer require --dev yorvoid/phpstan-rules
```

With [phpstan/extension-installer](https://github.com/phpstan/extension-installer), the rules are on. Without it, include them in your `phpstan.neon`:

```neon
includes:
    - vendor/yorvoid/phpstan-rules/rules.neon
```

## The rules

- **`yorvoid.storeCall`:** a call to one of YorvoID's stores (`PolicyStore`, `SessionStore` and the others marked `@spi`), or to one of their implementations, e.g. `DbalPolicyStore`. Every method counts, reads too: a store gives records as they're kept, without the checks YorvoID's services make. Use the service instead: `Operations` for a change, `Policy` for a decision, `Sessions` for sessions. Two kinds of code may call a store:
  - a store of your own that wraps another, calling the one it wraps (a class that implements the same interface);
  - YorvoID's store contracts in `yorvoid/testing`, which call the store your test gives them.
- **`yorvoid.internalUse`:** a call to a method, a read of a property, or a `new`, of what YorvoID marks `@internal`, on the member or on its class. Such code is for YorvoID's own packages, e.g. `Sessions::stepUp()`, which only the packages that check a proof call, and it can change in any minor release.

Code counts as YorvoID's by where its file is, in `vendor/yorvoid/`, not by its namespace: a class of yours in the `YorvoID\` namespace is still yours.

## What they don't catch

The rules see what PHPStan sees. A store reached through a type PHPStan doesn't know (`mixed`, a container's `get()` without a type), or by reflection, isn't flagged. In Symfony, YorvoID doesn't give stores to your services ([stores of your own](../concepts/storage.md#stores-of-your-own)), but `#[Autowire(service: 'yorvoid.store.session')]` would still reach one: the rule flags the calls that follow.

Calling a store isn't a way around YorvoID that it can stop: it's writing the database ([threat model](../security/threat-model.md)). The rules keep it from happening by mistake.
