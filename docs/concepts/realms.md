# Realms

A realm is an isolation boundary. Every principal, credential, session, grant and security log entry belongs to exactly one realm, and nothing is shared between realms: a principal of one realm can't sign in to another, hold authority in it, or see its records.

Most applications have a single realm, `default`, and never need to think about it. A multi-tenant application can give each tenant its own realm, for example `acme-corp` and `globex`.

## Names

A realm's name is like a hostname label:

- 1 to 63 characters;
- lowercase letters `a`–`z`, digits and hyphens;
- not starting or ending with a hyphen.

`default`, `acme-corp` and `eu-1` are valid; `Acme`, `acme_corp` and `-acme` aren't.

The name is stored in every record of the realm, so choose it once and keep it.

```php
use YorvoID\Core\Realm;

$realm = new Realm('acme-corp');
$default = Realm::default();
```

An invalid name throws an `IntegratorError` that says what's wrong and how to fix it.

## Realms and organisations

A realm separates data completely. Organisations, which come later, are something else: they group principals *inside* one realm, with memberships and roles, and can share resources. Use realms for tenants that must never see each other, and organisations for teams and companies within one tenant.
