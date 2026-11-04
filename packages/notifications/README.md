# yorvoid/notifications

YorvoID's security notifications: emails about new devices, changed sign-in methods and used recovery codes, the "This wasn't me" link that ends the attacker's sessions, and the link that verifies an email address. Part of [YorvoID](https://github.com/yorvoid/yorvoid), identity and access management for PHP.

```bash
composer require yorvoid/notifications
```

Applications usually install [`yorvoid/symfony`](https://github.com/yorvoid/yorvoid/tree/main/packages/symfony), which brings this package along. Without a framework, add it next to [`yorvoid/passkeys`](https://github.com/yorvoid/yorvoid/tree/main/packages/passkeys) to send these emails. Read [Notifications](https://github.com/yorvoid/yorvoid/blob/main/docs/concepts/notifications.md), and [the rest of the documentation](https://github.com/yorvoid/yorvoid/tree/main/docs).

## Read-only

This package is developed in [yorvoid/yorvoid](https://github.com/yorvoid/yorvoid), in [`packages/notifications`](https://github.com/yorvoid/yorvoid/tree/main/packages/notifications); its own repository is a read-only copy. Please open issues and pull requests in yorvoid/yorvoid, and report vulnerabilities privately as [the security policy](https://github.com/yorvoid/yorvoid/security/policy) describes.

## License

Licensed under the [Apache License 2.0](LICENSE); see also [NOTICE](NOTICE).
