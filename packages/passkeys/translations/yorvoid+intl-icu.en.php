<?php

declare(strict_types=1);

// yorvoid/passkeys' messages in English, ICU MessageFormat. Message IDs are stable (PLT-050); change the
// wording through your own translations, never by editing this file.

return [
    'yorvoid.verifier.add_key' => 'Allow the passkey "{label}" to sign operations',
    'yorvoid.verifier.remove_key' => 'Stop a passkey from signing operations',
    'yorvoid.flow.signed_out' => 'You were signed out. Sign in, then try again.',
    'yorvoid.flow.setup_code.invalid' => 'That setup code didn\'t work. Check it, or create a new one on the server.',
    'yorvoid.flow.email.invalid' => 'Enter a valid email address, like name@example.com.',
    'yorvoid.flow.invitation.not_working' => 'This invitation doesn\'t work any more. Ask for a new one.',
    'yorvoid.flow.recovery_code.invalid' => 'That code didn\'t work. Check it, or use another one.',
    'yorvoid.flow.recovery_link.not_working' => 'This link doesn\'t work any more. Ask for a new one.',
    'yorvoid.flow.recovery_codes.not_saved' => 'Confirm that you saved your recovery codes.',
    'yorvoid.passkey.expired' => 'That took too long. Please try again.',
    'yorvoid.passkey.not_verified' => 'Your device didn\'t confirm it\'s you. Use a passkey protected by a PIN, fingerprint or face.',
    'yorvoid.passkey.already_registered' => 'This passkey is already on your account.',
    'yorvoid.passkey.no_passkey' => 'There\'s no passkey on your account. Confirm it\'s you another way.',
    'yorvoid.passkey.not_working' => 'That passkey didn\'t work. Try another, or another way to sign in.',
];
