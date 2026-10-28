<?php

declare(strict_types=1);

// yorvoid/authn's messages in German, ICU MessageFormat. Message IDs are stable (PLT-050); change the
// wording through your own translations, never by editing this file.

return [
    'yorvoid.notification.new_device.subject' => 'Neue Anmeldung bei Ihrem {application}-Konto',
    'yorvoid.notification.new_device.body' => 'Jemand hat sich bei Ihrem {application}-Konto von einem Gerät angemeldet, das Sie bisher nicht verwendet haben. Wenn Sie das waren, müssen Sie nichts tun.',
    'yorvoid.notification.credential_added.subject' => 'Neue Anmeldemethode für Ihr {application}-Konto',
    'yorvoid.notification.credential_added.body' => 'Zu Ihrem {application}-Konto wurde eine neue Anmeldemethode hinzugefügt. Wenn Sie das waren, müssen Sie nichts tun.',
    'yorvoid.notification.credential_removed.subject' => 'Anmeldemethode aus Ihrem {application}-Konto entfernt',
    'yorvoid.notification.credential_removed.body' => 'Aus Ihrem {application}-Konto wurde eine Anmeldemethode entfernt. Wenn Sie das waren, müssen Sie nichts tun.',
    'yorvoid.notification.recovery_code_used.subject' => 'Wiederherstellungscode für Ihr {application}-Konto verwendet',
    'yorvoid.notification.recovery_code_used.body' => 'Jemand hat einen Ihrer Wiederherstellungscodes verwendet, um auf Ihr {application}-Konto zuzugreifen, und alle anderen wurden abgemeldet. {remaining, plural, =0 {Sie haben keine Wiederherstellungscodes mehr: Erstellen Sie neue.} one {Sie haben noch # Wiederherstellungscode.} other {Sie haben noch # Wiederherstellungscodes.}}',
    'yorvoid.notification.recovery_codes_generated.subject' => 'Neue Wiederherstellungscodes für Ihr {application}-Konto',
    'yorvoid.notification.recovery_codes_generated.body' => 'Für Ihr {application}-Konto {count, plural, one {wurde ein neuer Wiederherstellungscode} other {wurden # neue Wiederherstellungscodes}} erstellt. Die alten funktionieren nicht mehr. Wenn Sie das waren, bewahren Sie die neuen sicher auf.',
    'yorvoid.notification.signed_operation.subject' => 'Ein von Ihnen signierter Vorgang wurde bei {application} ausgeführt',
    'yorvoid.notification.signed_operation.body' => 'Ein mit einem Ihrer Passkeys signierter Vorgang wurde bei {application} ausgeführt. Wenn Sie ihn signiert haben, müssen Sie nichts tun.',
    'yorvoid.notification.fact.operation' => 'Vorgang',
    'yorvoid.notification.fact.when' => 'Wann',
    'yorvoid.notification.fact.device' => 'Gerät',
    'yorvoid.notification.fact.address' => 'IP-Adresse',
    'yorvoid.notification.fact.method' => 'Anmeldemethode',
    'yorvoid.notification.fact.name' => 'Name',
    'yorvoid.notification.unknown' => 'Unbekannt',
    'yorvoid.notification.kind.passkey' => 'Passkey',
    'yorvoid.notification.kind.password' => 'Passwort',
    'yorvoid.notification.kind.totp' => 'Authenticator-App',
    'yorvoid.notification.kind.recovery_codes' => 'Wiederherstellungscodes',
    'yorvoid.notification.not_me' => 'Das war ich nicht',
    'yorvoid.notification.not_me.explanation' => 'Wenn Sie das nicht waren, verwenden Sie den Link unten. Er meldet alle von Ihrem Konto ab und hilft Ihnen, wieder Zugang zu bekommen. Er funktioniert einmal, 7 Tage lang.',
    'yorvoid.notification.not_me.explanation_method' => 'Wenn Sie das nicht waren, verwenden Sie den Link unten. Er deaktiviert diese Anmeldemethode, meldet alle von Ihrem Konto ab und hilft Ihnen, wieder Zugang zu bekommen. Er funktioniert einmal, 7 Tage lang.',
    'yorvoid.notification.footnote' => 'Sie erhalten diese E-Mail, weil sie die Sicherheit Ihres {application}-Kontos betrifft. Wir senden sie immer, damit Sie wissen, wenn sich etwas ändert.',
];
