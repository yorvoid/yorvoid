import assert from 'node:assert/strict';

/**
 * A page's forms, as the forms script sees them: enough of the DOM to test it without a browser.
 */
export interface FakeEvent {
  readonly submitter: { getAttribute(name: string): string | null } | null;
  prevented: boolean;
  preventDefault(): void;
}

export class FakeForm {
  readonly dataset: Record<string, string>;
  readonly response: { value: string } | null;
  readonly error: { textContent: string; hidden: boolean } | null;
  submitted = 0;
  #listener: ((event: FakeEvent) => void) | null = null;

  constructor(dataset: Record<string, string>, fields: { response?: boolean; error?: boolean } = {}) {
    this.dataset = dataset;
    this.response = fields.response === false ? null : { value: '' };
    this.error = fields.error === false ? null : { textContent: '', hidden: true };
  }

  querySelector(selector: string): unknown {
    return { 'input[name="response"]': this.response, '[data-yorvoid-error]': this.error }[selector] ?? null;
  }

  addEventListener(type: string, listener: (event: FakeEvent) => void): void {
    assert.equal(type, 'submit');
    this.#listener = listener;
  }

  submit(): void {
    this.submitted++;
  }

  send(button: string | null = null): FakeEvent {
    const event: FakeEvent = { submitter: button === null ? null : { getAttribute: (name) => name === 'name' ? button : null }, prevented: false, preventDefault() { this.prevented = true; } };
    this.#listener?.(event);

    return event;
  }
}

export function page(forms: FakeForm[], problems: { focused: boolean } | null = null) {
  return {
    querySelector: (selector: string) => selector === '[data-yorvoid-problems]' && problems !== null ? { focus: () => { problems.focused = true; } } : null,
    querySelectorAll: (selector: string) => {
      assert.equal(selector, 'form[data-yorvoid-ceremony]');

      return forms;
    },
  } as unknown as ParentNode;
}

export const settle = () => new Promise((resolve) => setTimeout(resolve, 5));
