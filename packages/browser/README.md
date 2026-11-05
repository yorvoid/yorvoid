# @yorvoid/browser

The browser client of [YorvoID](https://github.com/yorvoid/yorvoid), the embedded IAM library for PHP: passkey sign-in with autofill, recovery, adding passkeys, step-up, signing intents, the WebAuthn signal API, and every error as a code to translate. TypeScript, no dependencies.

```ts
import { YorvoID } from '@yorvoid/browser';

const yorvoid = new YorvoID({ endpoint: '/yorvoid' });

if (await yorvoid.isConditionalSignInAvailable()) {
  await yorvoid.signIn({ conditional: true }); // passkeys in the username field's autofill
}
```

Read [the documentation](https://github.com/yorvoid/yorvoid/blob/main/docs/concepts/browser-client.md).

Licensed under the [Apache License 2.0](LICENSE).
