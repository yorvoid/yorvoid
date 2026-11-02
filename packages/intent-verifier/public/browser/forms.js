import { YorvoIDError } from './errors.js';
import { YorvoID } from './yorvoid.js';
/**
 * Runs the passkey ceremonies of YorvoID's flow pages, which work without it otherwise:
 *
 * - a form with data-yorvoid-ceremony="get" or "create" and data-yorvoid-options runs the ceremony
 *   when it's sent, puts the answer in its "response" field, and is sent;
 * - with data-yorvoid-conditional, passkeys are offered in the username field's autofill as soon as
 *   the page loads, until the person uses the button;
 * - a button named "action" ("Not now") sends the form without a ceremony;
 * - what goes wrong shows in the form's [data-yorvoid-error], in the page's language
 *   (data-yorvoid-messages);
 * - the page's problems, if any, get the focus, so screen readers read them first.
 */
export function wireForms(root, create = (messages) => new YorvoID({ messages })) {
    root.querySelector('[data-yorvoid-problems]')?.focus();
    for (const form of root.querySelectorAll('form[data-yorvoid-ceremony]')) {
        // One client per form: the button's ceremony stops the autofill's.
        const yorvoid = create(messages(form.dataset['yorvoidMessages']));
        // Without options, the ceremony is refused as the server's mistake.
        const options = String(form.dataset['yorvoidOptions']);
        const run = (conditional) => form.dataset['yorvoidCeremony'] === 'create' ? yorvoid.register(options) : yorvoid.authenticate(options, { conditional });
        const send = (answer) => {
            const field = form.querySelector('input[name="response"]');
            if (field !== null) {
                field.value = JSON.stringify(answer);
            }
            form.submit();
        };
        form.addEventListener('submit', (event) => {
            if (event.submitter?.getAttribute('name') === 'action') {
                return;
            }
            event.preventDefault();
            // A YorvoIDError, or a TypeError when the browser gave no passkey.
            run(false).then(send, (error) => show(form, error));
        });
        if ('yorvoidConditional' in form.dataset) {
            // Stopped when the person uses the button, or not available: the button still works.
            run(true).then(send, () => undefined);
        }
    }
}
function show(form, error) {
    const element = form.querySelector('[data-yorvoid-error]');
    if (element === null || (error instanceof YorvoIDError && error.code === 'aborted')) {
        return;
    }
    element.textContent = error.message;
    element.hidden = false;
}
function messages(json) {
    try {
        const parsed = JSON.parse(String(json));
        return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed) ? parsed : {};
    }
    catch {
        return {};
    }
}
if (typeof document !== 'undefined') {
    wireForms(document);
}
