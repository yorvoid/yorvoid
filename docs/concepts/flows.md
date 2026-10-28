# Flows

A **flow** is a journey through YorvoID: signing in, claiming a fresh installation, accepting an invitation, recovering an account, adding a passkey, stepping up, confirming a signed operation. It runs on the server, one **step** at a time: each step says what to show, takes what the person sends, and the flow decides what comes next (PLT-070).

Templates and the [browser client](browser-client.md) only render steps. So your own UI can render them too, and your application can add steps of its own, accepting the terms or collecting a company name, without copying controllers.

The engine is the `yorvoid/flows` package. The Symfony bridge serves every flow as [pages and JSON](symfony.md#flow-pages).

## How a flow runs

```php
use YorvoID\Flows\FlowContext;
use YorvoID\Flows\Flows;
use YorvoID\Flows\Input;

$progress = $flows->start('yorvoid.setup', $realm, $context);           // the first step, prepared
$progress = $flows->resume($id, $context);                                // the current step, again
$progress = $flows->submit($id, 'passkey', new Input($fields), $context); // the answer for a step
```

- **Only the current step takes answers.** An answer for any other is refused with `yorvoid.flow.out_of_date`, so no step can be skipped by sending another's.
- **A step that doesn't apply is passed over**, for good: e.g. generating recovery codes when the account still has some.
- **A refused answer** shows the step again with its problems, prepared afresh (a new passkey ceremony, for instance). Too many attempts are refused with `yorvoid.flow.too_many_attempts` and the seconds to wait.
- **A step can end the flow**, e.g. an invitation that doesn't work any more.
- **A flow takes at most 30 minutes** by default, and is forgotten when it completes, fails or expires.
- **Signing in happens in the step that does it.** The `Progress` carries the started session for the application to hold, and the following steps run in it. A step-up's new token comes the same way.
- **When a flow completes**, `FlowCompleted` carries its state: keep what your own steps collected.

The context says who is signed in, and what steps that sign people in record: the origin, the device's description, the client's address, the device cookie, and the language to prepare things in. The Symfony bridge builds it for each request.

## The flows

The flows are a list of `Flow` objects. `Flows::flow($name)` gives one; starting a flow that isn't in the list is an integrator's error.

YorvoID's passkey-first journeys come from `PasskeyFlows` in `yorvoid/passkeys` (UX-009). Each ends with a way to sign in that works:

| Flow | Steps | Start it with |
|---|---|---|
| `yorvoid.sign_in` | `passkey` | |
| `yorvoid.setup` | `setup_code` (code and email; signs the owner in), `passkey_registration`, `recovery_codes` | |
| `yorvoid.invitation` | `invitation` (confirm; signs the invitee in), `passkey_registration`, `recovery_codes` | `invitation.token`: the link's token |
| `yorvoid.recover_with_code` | `recovery_code` (email and code), `passkey_registration` (or later), `recovery_codes` | |
| `yorvoid.recover_with_link` | `recovery_link` (confirm), `passkey_registration` (or later), `recovery_codes` | `recovery_link.token`: the link's token |
| `yorvoid.add_passkey` | `step_up`, `passkey_registration` | |
| `yorvoid.step_up` | `step_up` | `step_up.operation`, and `step_up.multi_factor` if it needs two factors |
| `yorvoid.sign_intent` | `confirm` | `IntentConfirmationStep::data($signingRequest)` |

- **`passkey_registration`** keeps the new credential's ID as `passkey_registration.credential`. After a recovery, the person may add one later, on a borrowed device for instance.
- **`recovery_codes`** applies only when the account has no recovery codes left. The codes stay in the flow's state until the person confirms they saved them.
- **`step_up`** applies only when the session's proof isn't recent enough; without a session it applies, and fails, so a step-up flow never completes without a proof.
- **`invitation` and `recovery_link`** ask the person to confirm: an email scanner opening the link doesn't use it up.
- **`confirm`** shows an operation the [verifier](signed-intents.md) prepared, and has the person sign it. When the flow completes, send `intent.nonce` and `intent.assertion` to the verifier: it checks the signature, not this step.
- Refusals while signing in don't say why, except an unknown passkey (`reason: unknown_passkey`), which the browser tells the passkey provider.
- Every message is in English and German, in the `yorvoid` domain.

## Changing a flow

Each change gives a new `Flow`; the original stays as it was:

```php
$setup = $flows->flow('yorvoid.setup')
    ->withStepAfter('passkey', new AcceptTermsStep())
    ->withStepReplaced(new MyRecoveryCodesStep())  // same name as the step it replaces
    ->withoutStep('optional_step');                // only steps the flow doesn't require
```

- Flows are named in lowercase segments separated by dots: YorvoID's start with `yorvoid.`, give yours your own prefix.
- Every step of a flow has a name of its own.
- A flow lists the steps it can't do without, because it isn't safe without them: claiming the setup code, for instance. Removing one is an integrator's error; replacing it with one of your own is up to you.

## Writing a step

A step implements `Step`:

| Method | |
|---|---|
| `name()` | Unique in its flow; forms and the browser client send it back |
| `applies($state, $context)` | Whether it's needed; asked once, when the flow reaches it |
| `enter($state, $context)` | Prepares it, as it becomes current and after a refused answer; gives the state with what the other methods need |
| `view($state)` | A typed view model for templates and the browser client: data, never copy |
| `submit($state, $context, $input)` | Checks the answer: `StepResult::done()`, `refused()`, `throttled()` or `failed()` |

- **Keep what the step needs in the flow's state**, never in properties: one instance serves every flow, and a flow may continue in another request or on another server.
- **The state holds plain data:** strings, numbers, booleans, null, and lists and maps of them. Keep an object's ID, and load it again; anything else is an integrator's error.
- **Problems are messages to translate** (domain `yorvoid`), with parameters, the field they're about, and a reason for logs.
- `Input` reads what was sent, from a form or the browser client: `string()`, `isChecked()`, `json()` (a passkey ceremony's answer, sent as an object or a JSON string), and `action()`, which is `Input::SKIP` when the person passes over a step that offers it.
