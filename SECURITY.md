# Security policy

YorvoID is security software, so we treat reports about it with priority.

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues, discussions or pull requests.**

Report them privately through GitHub instead:

1. Go to the [Security tab](https://github.com/yorvoid/yorvoid/security) of this repository.
2. Click **Report a vulnerability**.
3. Describe the issue. Include as much of the following as you can:
   - the affected package (e.g. `yorvoid/core`) and version or commit;
   - the type of issue (e.g. authentication bypass, privilege escalation, token replay, timing leak);
   - steps to reproduce, or a proof of concept;
   - the impact, i.e. what an attacker could achieve, and under which configuration.

Only the maintainers can see your report.

## What happens next

- We acknowledge your report within **3 working days**.
- We confirm or rule out the issue, and keep you updated while we work on a fix.
- We aim to release a fix within **90 days** of the report, sooner for critical issues.
- Once a fix is released, we publish a security advisory with a CVE ID, and credit you unless you prefer to stay anonymous.

Please give us a reasonable chance to fix the issue before you disclose it publicly.

## Supported versions

Until 1.0, the latest 0.x release receives security fixes, as a patch release (e.g. 0.1.1 after 0.1.0), and so does `main`. Earlier 0.x releases don't: upgrade to the latest.

After 1.0, the latest minor version receives security fixes, and the previous minor receives them for six months after its successor is released.

## Scope

This policy covers the code in this repository: every `yorvoid/*` Composer package (their own repositories, such as `yorvoid/core`, are read-only copies of `packages/`), the `@yorvoid/browser` npm package, and the Docker setup used to develop them.

Vulnerabilities in YorvoID's dependencies (e.g. Doctrine DBAL, Symfony components, `web-auth/webauthn-lib`) should be reported to those projects. If you're not sure where an issue belongs, report it here and we'll help route it.

## Verifying a release

Each version tag is signed with a key listed in [`.github/release-signers`](.github/release-signers), and the packages are released only from a signed tag. To check one:

```sh
git -c gpg.format=ssh -c gpg.ssh.allowedSignersFile=.github/release-signers tag -v v0.2.0
```

Each [GitHub release](https://github.com/yorvoid/yorvoid/releases) has every package's source as released and its CycloneDX SBOM: the packages it needs, at the versions CI tested it with. Both carry build provenance, signed through Sigstore, that links them to the tag and the workflow run that made them:

```sh
gh attestation verify yorvoid-core-0.2.0.tar.gz --repo yorvoid/yorvoid
gh attestation verify yorvoid-core-0.2.0.cdx.json --repo yorvoid/yorvoid
```

The browser client on npm carries provenance too (`npm audit signatures`).
