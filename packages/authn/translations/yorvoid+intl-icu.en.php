<?php

declare(strict_types=1);

// yorvoid/authn's messages in English, ICU MessageFormat. Message IDs are stable (PLT-050); change the
// wording through your own translations, never by editing this file.

return [
    'yorvoid.notification.new_device.subject' => 'New sign-in to your {application} account',
    'yorvoid.notification.new_device.body' => 'Someone signed in to your {application} account from a device you haven\'t used before. If it was you, there\'s nothing to do.',
    'yorvoid.notification.credential_added.subject' => 'A sign-in method was added to your {application} account',
    'yorvoid.notification.credential_added.body' => 'A new way to sign in was added to your {application} account. If it was you, there\'s nothing to do.',
    'yorvoid.notification.credential_removed.subject' => 'A sign-in method was removed from your {application} account',
    'yorvoid.notification.credential_removed.body' => 'A way to sign in was removed from your {application} account. If it was you, there\'s nothing to do.',
    'yorvoid.notification.recovery_code_used.subject' => 'A recovery code was used for your {application} account',
    'yorvoid.notification.recovery_code_used.body' => 'Someone used one of your recovery codes to get into your {application} account, and everyone else was signed out. {remaining, plural, =0 {You have no recovery codes left: create new ones.} one {You have # recovery code left.} other {You have # recovery codes left.}}',
    'yorvoid.notification.recovery_codes_generated.subject' => 'New recovery codes for your {application} account',
    'yorvoid.notification.recovery_codes_generated.body' => '{count, plural, one {A new recovery code was} other {# new recovery codes were}} created for your {application} account. The old ones no longer work. If it was you, keep the new ones somewhere safe.',
    'yorvoid.notification.signed_operation.subject' => 'An operation you signed ran on {application}',
    'yorvoid.notification.signed_operation.body' => 'An operation signed with one of your passkeys ran on {application}. If you signed it, there\'s nothing to do.',
    'yorvoid.notification.fact.operation' => 'Operation',
    'yorvoid.notification.fact.when' => 'When',
    'yorvoid.notification.fact.device' => 'Device',
    'yorvoid.notification.fact.address' => 'IP address',
    'yorvoid.notification.fact.method' => 'Sign-in method',
    'yorvoid.notification.fact.name' => 'Name',
    'yorvoid.notification.unknown' => 'Unknown',
    'yorvoid.notification.kind.passkey' => 'Passkey',
    'yorvoid.notification.kind.password' => 'Password',
    'yorvoid.notification.kind.totp' => 'Authenticator app',
    'yorvoid.notification.kind.recovery_codes' => 'Recovery codes',
    'yorvoid.notification.not_me' => 'This wasn\'t me',
    'yorvoid.notification.not_me.explanation' => 'If it wasn\'t you, use the link below. It signs everyone out of your account and helps you get back in. It works once, for 7 days.',
    'yorvoid.notification.not_me.explanation_method' => 'If it wasn\'t you, use the link below. It turns this sign-in method off, signs everyone out of your account, and helps you get back in. It works once, for 7 days.',
    'yorvoid.notification.footnote' => 'You\'re getting this email because it\'s about the security of your {application} account. We always send these, so you know when something changes.',
];
