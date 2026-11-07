# yorvoid/policy

YorvoID's policy: declared abilities, roles (super roles, strict abilities, the "everyone" pseudo-role), a realm's changes to the defaults kept as differences, roles given in a realm or on one resource, and rules in PHP that permit, forbid or can't tell. They're elements of YorvoID's evaluator, which decides YorvoID's operations and an application's own abilities alike. Part of [YorvoID](https://github.com/yorvoid/yorvoid), identity and access management for PHP.

```bash
composer require yorvoid/policy
```

Applications usually install [`yorvoid/symfony`](https://github.com/yorvoid/yorvoid/tree/main/packages/symfony), which brings this package along. Read [Authorization](https://github.com/yorvoid/yorvoid/blob/main/docs/concepts/authorization.md), and [the rest of the documentation](https://github.com/yorvoid/yorvoid/tree/main/docs).

## Read-only

This package is developed in [yorvoid/yorvoid](https://github.com/yorvoid/yorvoid), in [`packages/policy`](https://github.com/yorvoid/yorvoid/tree/main/packages/policy); its own repository is a read-only copy. Please open issues and pull requests in yorvoid/yorvoid, and report vulnerabilities privately as [the security policy](https://github.com/yorvoid/yorvoid/security/policy) describes.

## License

Licensed under the [Apache License 2.0](LICENSE); see also [NOTICE](NOTICE).
