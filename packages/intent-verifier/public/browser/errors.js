/**
 * The messages shown for each code, in English. Give your own translations in the client's options.
 */
export const MESSAGES = Object.freeze({
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
/**
 * Something that didn't work, with a message to show and a code to act on.
 */
export class YorvoIDError extends Error {
    name = 'YorvoIDError';
    code;
    /** The HTTP status, when the server refused */
    status;
    /** YorvoID's reason for refusing a passkey, e.g. "ceremony_expired": for logs, not for people */
    reason;
    /** Seconds to wait, for `too_many_attempts` */
    retryAfter;
    /** What to step up for, for `step_up_required` */
    stepUp;
    constructor(code, message, details = {}) {
        super(message, { cause: details.cause });
        this.code = code;
        this.status = details.status;
        this.reason = details.reason;
        this.retryAfter = details.retryAfter;
        this.stepUp = details.stepUp;
    }
}
const BROWSER_ERRORS = Object.freeze({
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
export function browserErrorCode(error) {
    return error instanceof Error && Object.hasOwn(BROWSER_ERRORS, error.name) ? BROWSER_ERRORS[error.name] : 'unknown';
}
