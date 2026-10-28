# Browser client

`@yorvoid/browser` runs passkey ceremonies in the browser against your application's YorvoID routes ([Symfony](symfony.md#signing-in)), and turns everything that can go wrong into a code to act on and a message to show. It's TypeScript, with no dependencies, for browsers with WebAuthn Level 3.

```bash
npm install @yorvoid/browser
```

```ts
import { YorvoID, YorvoIDError } from '@yorvoid/browser';

const yorvoid = new YorvoID({ endpoint: '/yorvoid' });
```

## Signing in

Offer passkeys in the username field's autofill as soon as the page loads, and keep a button for everyone else. Signing in with the button stops the autofill request: there's one ceremony at a time.

```html
<input name="username" autocomplete="username webauthn">
<button id="passkey">Sign in with a passkey</button>
```

```ts
if (await yorvoid.isConditionalSignInAvailable()) {
  yorvoid.signIn({ conditional: true }).then(signedIn, failed);
}

document.querySelector('#passkey')!.addEventListener('click', () => yorvoid.signIn().then(signedIn, failed));

function failed(error: YorvoIDError): void {
  if (error.code !== 'aborted') {
    show(error.message);
  }
}
```

- `recoverWithCode(email, code)` and `recoverWithLink(token)` recover an account; every other session ends. Call `recoverWithLink()` when the person confirms on your recovery page, not when it loads: email scanners open links.
- After signing in, the session is your application's: reload, or go where the person was going.

## Adding a passkey and stepping up

Adding a passkey needs a recent proof that it's the person. The error says what to step up for:

```ts
async function addPasskey(label: string): Promise<void> {
  try {
    await yorvoid.addPasskey(label);
  } catch (error) {
    if (error instanceof YorvoIDError && error.code === 'step_up_required') {
      await yorvoid.stepUp({ operation: error.stepUp?.operation });
      await yorvoid.addPasskey(label);
    } else {
      throw error;
    }
  }
}
```

`stepUp({ operation, multiFactor })` works the same way for your own operations: your endpoint answers 403 with `step_up`, as YorvoID's do ([step-up](step-up.md)).

## Signing intents

For an operation the verifier prepared ([signed intents](signed-intents.md)), show the person the summary, then have them sign:

```ts
const signed = await yorvoid.signIntent(request.options);
await fetch('/console/operations/confirm', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ nonce: request.nonce, assertion: signed }) });
```

The client only runs the ceremony: your console sends the signature to the verifier, the way it talks to it.

## Keeping passkey lists right

Passkey providers (password managers, the operating system) list people's passkeys. With the WebAuthn signal API, the client tells them which still work here, and the name to show them with:

- after signing in and adding a passkey, it sends the account's passkeys and name (`signals: false` turns this off);
- when a sign-in used a passkey this site doesn't know any more, it says so, and the provider can stop offering it;
- after removing a passkey, call `syncPasskeys()`.

Browsers without the signal API are left alone, and a signal that fails changes nothing.

## Flows

Applications that render YorvoID's [flows](flows.md) themselves, in a single-page app for instance, drive them with JSON:

```ts
let progress = await yorvoid.startFlow('yorvoid.sign_in');

while (progress.status === 'in_progress') {
  // Show progress.step with progress.view and progress.problems, then send the answer:
  const response = await yorvoid.authenticate(progress.view!.options as object);
  progress = await yorvoid.submitStep(progress.flow, progress.id!, progress.step!, { response });
}

if (progress.status === 'completed') {
  location.assign(progress.redirect ?? '/');
}
```

- `authenticate(options, { conditional })` and `register(options)` run a passkey step's ceremony, and give the answer to send as `response`.
- `resumeFlow(flow, id)` shows the current step again.
- A refused answer, a flow that failed or expired, and too many attempts are progress, not errors: the progress has the problems (translated), and `retryAfter`.

### The flow pages' script

`@yorvoid/browser/forms` runs the passkey ceremonies of the [reference pages](symfony.md#the-reference-templates), and of yours if they follow them: a form with `data-yorvoid-ceremony="get"` or `"create"` and `data-yorvoid-options` runs it when sent, puts the answer in its `response` field, and is sent; `data-yorvoid-conditional` offers passkeys in autofill as soon as the page loads. The Symfony bridge ships it, built, in its public files.

## Errors

Every failure is a `YorvoIDError` with a `code`, and a `message` to show:

| Code | When |
|---|---|
| `not_supported` | The browser can't use passkeys, or not this way (autofill) |
| `cancelled` | The person cancelled, it timed out, or the browser didn't allow it |
| `aborted` | The page stopped it, e.g. autofill when the person chose the button: usually nothing to show |
| `already_registered` | This authenticator already has a passkey for the account |
| `user_verification_unavailable` | The authenticator can't check that it's its owner |
| `wrong_site` | The page isn't on the passkeys' site, or not on HTTPS: a configuration error |
| `refused` | YorvoID refused: the message is the server's; `reason` is for logs |
| `too_many_attempts` | `retryAfter` says how many seconds to wait |
| `signed_out` | The session ended; sign in again |
| `step_up_required` | `stepUp` says what for |
| `network` | The server couldn't be reached |
| `server` | The server answered something unexpected |
| `unknown` | Anything else the browser reported |

The messages are English. Give your translations by code:

```ts
new YorvoID({ messages: { cancelled: 'Das wurde abgebrochen. Bitte versuch es noch einmal.' } });
```

Your translation wins over the server's message, which wins over the client's own.

## How it talks to the server

- Every request is a `POST` with a JSON body and the page's cookies (`same-origin`). YorvoID's endpoints accept only JSON, which another site's form can't send.
- WebAuthn's options and answers are converted here rather than with the browser's `toJSON()`, which some browsers and password managers lack or get wrong.
