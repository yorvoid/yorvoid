# yorvoid/intent-signing

YorvoID's side of signed intents in the application, the console: asking for an operation's signature, limits on asking, recording requests and outcomes in the security log, and the summaries the signing page shows. Part of [YorvoID](https://github.com/yorvoid/yorvoid), identity and access management for PHP.

```bash
composer require yorvoid/intent-signing
```

Applications usually install [`yorvoid/symfony`](https://github.com/yorvoid/yorvoid/tree/main/packages/symfony), which brings this package along; the privileged side runs [`yorvoid/intent-verifier`](https://github.com/yorvoid/yorvoid/tree/main/packages/intent-verifier). Read [Signed intents](https://github.com/yorvoid/yorvoid/blob/main/docs/concepts/signed-intents.md), and [the rest of the documentation](https://github.com/yorvoid/yorvoid/tree/main/docs).

## Read-only

This package is developed in [yorvoid/yorvoid](https://github.com/yorvoid/yorvoid), in [`packages/intent-signing`](https://github.com/yorvoid/yorvoid/tree/main/packages/intent-signing); its own repository is a read-only copy. Please open issues and pull requests in yorvoid/yorvoid, and report vulnerabilities privately as [the security policy](https://github.com/yorvoid/yorvoid/security/policy) describes.

## License

Licensed under the [Apache License 2.0](LICENSE); see also [NOTICE](NOTICE).
