# yorvoid/delegation

YorvoID's delegations: a principal passes part of its authority to another, e.g. an agent, for some actions on some resources, for a while, and maybe for one purpose or task. Each step can only narrow it, and every use checks the whole chain again, up to its root's authority now. Part of [YorvoID](https://github.com/yorvoid/yorvoid), identity and access management for PHP.

```bash
composer require yorvoid/delegation
```

Applications usually install [`yorvoid/symfony`](https://github.com/yorvoid/yorvoid/tree/main/packages/symfony), which brings this package along. Read [Delegation](https://github.com/yorvoid/yorvoid/blob/main/docs/concepts/delegation.md), and [the rest of the documentation](https://github.com/yorvoid/yorvoid/tree/main/docs).

## Read-only

This package is developed in [yorvoid/yorvoid](https://github.com/yorvoid/yorvoid), in [`packages/delegation`](https://github.com/yorvoid/yorvoid/tree/main/packages/delegation); its own repository is a read-only copy. Please open issues and pull requests in yorvoid/yorvoid, and report vulnerabilities privately as [the security policy](https://github.com/yorvoid/yorvoid/security/policy) describes.

## License

Licensed under the [Apache License 2.0](LICENSE); see also [NOTICE](NOTICE).
