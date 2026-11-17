# yorvoid/phpstan-rules

PHPStan rules for an application that uses YorvoID. They flag a call to one of YorvoID's stores, which are for implementing, not calling, and a use of what YorvoID marks `@internal`. For development only. Part of [YorvoID](https://github.com/yorvoid/yorvoid), identity and access management for PHP.

```bash
composer require --dev yorvoid/phpstan-rules
```

With [phpstan/extension-installer](https://github.com/phpstan/extension-installer), the rules are on. Without it, include `vendor/yorvoid/phpstan-rules/rules.neon` in your PHPStan configuration. Read [Check your application with YorvoID's PHPStan rules](https://github.com/yorvoid/yorvoid/blob/main/docs/how-to/phpstan-rules.md), and [the rest of the documentation](https://github.com/yorvoid/yorvoid/tree/main/docs).

## Read-only

This package is developed in [yorvoid/yorvoid](https://github.com/yorvoid/yorvoid), in [`packages/phpstan-rules`](https://github.com/yorvoid/yorvoid/tree/main/packages/phpstan-rules); its own repository is a read-only copy. Please open issues and pull requests in yorvoid/yorvoid, and report vulnerabilities privately as [the security policy](https://github.com/yorvoid/yorvoid/security/policy) describes.

## License

Licensed under the [Apache License 2.0](LICENSE); see also [NOTICE](NOTICE).
