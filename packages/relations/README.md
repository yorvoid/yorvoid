# yorvoid/relations

YorvoID's relations between resources (ReBAC): who is an editor of which page, which site belongs to which server, groups within groups. A relation can imply another (an editor is a viewer) or be inherited from a parent (a site's viewers include its server's), and give abilities on the resource. Checked a level at a time and bounded, and an element of YorvoID's evaluator. Part of [YorvoID](https://github.com/yorvoid/yorvoid), identity and access management for PHP.

```bash
composer require yorvoid/relations
```

Applications usually install [`yorvoid/symfony`](https://github.com/yorvoid/yorvoid/tree/main/packages/symfony), which brings this package along. Read [Relations](https://github.com/yorvoid/yorvoid/blob/main/docs/concepts/authorization.md#relations), and [the rest of the documentation](https://github.com/yorvoid/yorvoid/tree/main/docs).

## Read-only

This package is developed in [yorvoid/yorvoid](https://github.com/yorvoid/yorvoid), in [`packages/relations`](https://github.com/yorvoid/yorvoid/tree/main/packages/relations); its own repository is a read-only copy. Please open issues and pull requests in yorvoid/yorvoid, and report vulnerabilities privately as [the security policy](https://github.com/yorvoid/yorvoid/security/policy) describes.

## License

Licensed under the [Apache License 2.0](LICENSE); see also [NOTICE](NOTICE).
