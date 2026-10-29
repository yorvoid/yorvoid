# yorvoid/throttle

YorvoID's throttle: at most so many attempts per period, per key, shared by every server through the database, atomic under concurrent requests. Part of [YorvoID](https://github.com/yorvoid/yorvoid), identity and access management for PHP.

```bash
composer require yorvoid/throttle
```

Applications usually install [`yorvoid/symfony`](https://github.com/yorvoid/yorvoid/tree/main/packages/symfony), or [`yorvoid/passkeys`](https://github.com/yorvoid/yorvoid/tree/main/packages/passkeys) without a framework, which bring this package along. Read [Throttling](https://github.com/yorvoid/yorvoid/blob/main/docs/concepts/throttling.md), and [the rest of the documentation](https://github.com/yorvoid/yorvoid/tree/main/docs).

## Read-only

This package is developed in [yorvoid/yorvoid](https://github.com/yorvoid/yorvoid), in [`packages/throttle`](https://github.com/yorvoid/yorvoid/tree/main/packages/throttle); its own repository is a read-only copy. Please open issues and pull requests in yorvoid/yorvoid, and report vulnerabilities privately as [the security policy](https://github.com/yorvoid/yorvoid/security/policy) describes.

## License

Licensed under the [Apache License 2.0](LICENSE); see also [NOTICE](NOTICE).
