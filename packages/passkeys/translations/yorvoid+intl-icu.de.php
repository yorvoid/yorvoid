<?php

declare(strict_types=1);

// yorvoid/passkeys' messages in German, ICU MessageFormat. Message IDs are stable (PLT-050); change the
// wording through your own translations, never by editing this file.

return [
    'yorvoid.verifier.add_key' => 'Dem Passkey „{label}“ erlauben, Vorgänge zu signieren',
    'yorvoid.verifier.remove_key' => 'Einem Passkey das Signieren von Vorgängen entziehen',
    'yorvoid.flow.signed_out' => 'Sie wurden abgemeldet. Melden Sie sich an und versuchen Sie es noch einmal.',
    'yorvoid.flow.setup_code.invalid' => 'Dieser Einrichtungscode hat nicht funktioniert. Prüfen Sie ihn, oder erstellen Sie auf dem Server einen neuen.',
    'yorvoid.flow.email.invalid' => 'Geben Sie eine gültige E-Mail-Adresse ein, zum Beispiel name@example.com.',
    'yorvoid.flow.invitation.not_working' => 'Diese Einladung funktioniert nicht mehr. Bitten Sie um eine neue.',
    'yorvoid.flow.recovery_code.invalid' => 'Dieser Code hat nicht funktioniert. Prüfen Sie ihn, oder verwenden Sie einen anderen.',
    'yorvoid.flow.recovery_link.not_working' => 'Dieser Link funktioniert nicht mehr. Bitten Sie um einen neuen.',
    'yorvoid.flow.recovery_codes.not_saved' => 'Bestätigen Sie, dass Sie Ihre Wiederherstellungscodes gespeichert haben.',
    'yorvoid.passkey.expired' => 'Das hat zu lange gedauert. Bitte versuchen Sie es noch einmal.',
    'yorvoid.passkey.not_verified' => 'Ihr Gerät hat nicht bestätigt, dass Sie es sind. Verwenden Sie einen Passkey, der mit PIN, Fingerabdruck oder Gesicht geschützt ist.',
    'yorvoid.passkey.already_registered' => 'Dieser Passkey ist bereits in Ihrem Konto.',
    'yorvoid.passkey.no_passkey' => 'Ihr Konto hat keinen Passkey. Bestätigen Sie auf andere Weise, dass Sie es sind.',
    'yorvoid.passkey.not_working' => 'Dieser Passkey hat nicht funktioniert. Versuchen Sie einen anderen, oder melden Sie sich auf andere Weise an.',
];
