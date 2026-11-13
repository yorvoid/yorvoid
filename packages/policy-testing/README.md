# yorvoid/policy-testing

Tests for an application's YorvoID policy (AUZ-043): the decisions it must give, written as cases in PHP, and run in PHPUnit or from a command, on the policy as production builds it with only its stores in memory, and mutation testing that reports what no case catches. For development only. Part of [YorvoID](https://github.com/yorvoid/yorvoid), identity and access management for PHP.

```bash
composer require --dev yorvoid/policy-testing
```

Read [Test your policy](https://github.com/yorvoid/yorvoid/blob/main/docs/how-to/test-your-policy.md), and [the rest of the documentation](https://github.com/yorvoid/yorvoid/tree/main/docs).

## Read-only

This package is developed in [yorvoid/yorvoid](https://github.com/yorvoid/yorvoid), in [`packages/policy-testing`](https://github.com/yorvoid/yorvoid/tree/main/packages/policy-testing); its own repository is a read-only copy. Please open issues and pull requests in yorvoid/yorvoid, and report vulnerabilities privately as [the security policy](https://github.com/yorvoid/yorvoid/security/policy) describes.

## License

Licensed under the [Apache License 2.0](LICENSE); see also [NOTICE](NOTICE).
