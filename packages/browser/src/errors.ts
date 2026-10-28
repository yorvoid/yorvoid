/**
 * Why something didn't work, as a code to translate:
 *
 * - `not_supported`: this browser can't use passkeys, or not this way (e.g. autofill)
 * - `cancelled`: the person cancelled, the request timed out, or the browser didn't allow it
 * - `aborted`: the page stopped the request, e.g. autofill when the person chose the button
 * - `already_registered`: this authenticator already has a passkey for the account
 * - `user_verification_unavailable`: the authenticator can't check that it's its owner
 * - `wrong_site`: the page isn't on the passkeys' site, or not on HTTPS (a configuration error)
 * - `refused`: YorvoID refused it; the message says why
 * - `too_many_attempts`: try again after `retryAfter` seconds
 * - `signed_out`: the session ended; sign in again
 * - `step_up_required`: confirm it's you first, with `stepUp()`
 * - `network`: the server couldn't be reached
 * - `server`: the server answered something unexpected
 * - `unknown`: anything else the browser reported
 */
export type ErrorCode =
  | 'not_supported'
  | 'cancelled'
  | 'aborted'
  | 'already_registered'
  | 'user_verification_unavailable'
  | 'wrong_site'
  | 'refused'
  | 'too_many_attempts'
  | 'signed_out'
  | 'step_up_required'
  | 'network'
  | 'server'
  | 'unknown';

export type Messages = Record<ErrorCode, string>;

/**
 * The messages shown for each code, in English. Give your own translations in the client's options.
 */
export const MESSAGES: Readonly<Messages> = Object.freeze({
  not_supported: 'This browser can\'t use passkeys here. Try another way to sign in.',
  cancelled: 'That was cancelled or took too long. Please try again.',
  aborted: 'That was stopped.',
  already_registered: 'This device already has a passkey for your account.',
  user_verification_unavailable: 'This device can\'t confirm it\'s you. Use another device, or set up a screen lock.',
  wrong_site: 'Passkeys can\'t be used on this page.',
  refused: 'That didn\'t work. Please try again.',
  too_many_attempts: 'Too many attempts. Please wait, then try again.',
  signed_out: 'You were signed out. Please sign in again.',
  step_up_required: 'Please confirm it\'s you.',
  network: 'The server couldn\'t be reached. Check your connection, then try again.',
  server: 'Something went wrong. Please try again later.',
  unknown: 'Something went wrong. Please try again.',
});

export interface StepUpRequirement {
  /** The operation to step up for, e.g. "yorvoid.credential.add" */
  readonly operation: string | null;
  /** How recent the proof must be, e.g. "5m" */
  readonly within: string;
}

export interface ErrorDetails {
  readonly status?: number;
  readonly reason?: string | undefined;
  readonly retryAfter?: number | undefined;
  readonly stepUp?: StepUpRequirement;
  readonly cause?: unknown;
}

/**
 * Something that didn't work, with a message to show and a code to act on.
 */
export class YorvoIDError extends Error {
  override readonly name = 'YorvoIDError';
  readonly code: ErrorCode;
  /** The HTTP status, when the server refused */
  readonly status: number | undefined;
  /** YorvoID's reason for refusing a passkey, e.g. "ceremony_expired": for logs, not for people */
  readonly reason: string | undefined;
  /** Seconds to wait, for `too_many_attempts` */
  readonly retryAfter: number | undefined;
  /** What to step up for, for `step_up_required` */
  readonly stepUp: StepUpRequirement | undefined;

  constructor(code: ErrorCode, message: string, details: ErrorDetails = {}) {
    super(message, { cause: details.cause });
    this.code = code;
    this.status = details.status;
    this.reason = details.reason;
    this.retryAfter = details.retryAfter;
    this.stepUp = details.stepUp;
  }
}

const BROWSER_ERRORS: Readonly<Record<string, ErrorCode>> = Object.freeze({
  NotAllowedError: 'cancelled',
  AbortError: 'aborted',
  InvalidStateError: 'already_registered',
  ConstraintError: 'user_verification_unavailable',
  NotSupportedError: 'not_supported',
  SecurityError: 'wrong_site',
});

/**
 * The code for what navigator.credentials reported.
 */
export function browserErrorCode(error: unknown): ErrorCode {
  return error instanceof Error && Object.hasOwn(BROWSER_ERRORS, error.name) ? BROWSER_ERRORS[error.name] as ErrorCode : 'unknown';
}
