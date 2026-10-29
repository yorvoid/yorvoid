# yorvoid/intent-verifier

YorvoID's intent verifier: runs a privileged operation only after a passkey signed exactly that operation, checked against keys the verifier holds itself. Stands alone: no framework, no database layer. Part of [YorvoID](https://github.com/yorvoid/yorvoid), identity and access management for PHP.

```bash
composer require yorvoid/intent-verifier
```

It stands alone: install it where the privileged operations run, next to no other YorvoID package. Read [Signed intents](https://github.com/yorvoid/yorvoid/blob/main/docs/concepts/signed-intents.md), and [the rest of the documentation](https://github.com/yorvoid/yorvoid/tree/main/docs).

## Read-only

This package is developed in [yorvoid/yorvoid](https://github.com/yorvoid/yorvoid), in [`packages/intent-verifier`](https://github.com/yorvoid/yorvoid/tree/main/packages/intent-verifier); its own repository is a read-only copy. Please open issues and pull requests in yorvoid/yorvoid, and report vulnerabilities privately as [the security policy](https://github.com/yorvoid/yorvoid/security/policy) describes.

## License

Licensed under the [Apache License 2.0](LICENSE); see also [NOTICE](NOTICE).
