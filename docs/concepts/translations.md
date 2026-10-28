# Translations

Every message YorvoID shows people is translatable (PLT-050): ICU MessageFormat, with stable message IDs, in English and German. Each package ships its catalogues; `yorvoid/translation` translates them, and an application with Symfony's translator can use that instead.

```php
use YorvoID\Authn\Translations;
use YorvoID\Translation\Translator;

$translator = new Translator([Translations::DIRECTORY], locale: 'de');
$translator->trans('yorvoid.notification.new_device.subject', ['application' => 'Acme'], Translations::DOMAIN);
// "Neue Anmeldung bei Ihrem Acme-Konto"
```

`Translator` implements Symfony's `TranslatorInterface`, which YorvoID's services expect: pass either.

## Catalogues

A catalogue is a PHP file named `{domain}+intl-icu.{locale}.php` returning message IDs and their messages: the same names Symfony's translator loads, with `+intl-icu` marking ICU messages. YorvoID's domain is `yorvoid`.

```php
return [
    'yorvoid.notification.new_device.subject' => 'New sign-in to your {application} account',
    'yorvoid.notification.recovery_code_used.body' => '… {remaining, plural, =0 {You have no recovery codes left.} one {You have # recovery code left.} other {You have # recovery codes left.}}',
];
```

- A message missing in a locale comes from the less specific one (`de_AT` from `de`), then from the fallback locale (English); a message missing everywhere is its ID.
- Later directories override earlier ones: to change YorvoID's wording, add your own directory after YorvoID's with only the messages you change. Never edit YorvoID's files: they change with each release.
- Parameters are named, with or without braces (`['count' => 3]` or `['{count}' => 3]`).
- An invalid message or catalogue throws `IntegratorError`. YorvoID's own catalogues are checked in its tests: English and German have the same messages, with the same parameters, all valid ICU.

## With Symfony's translator

Add each package's directory to `framework.translator.paths` (the Symfony bridge does), and pass Symfony's translator to YorvoID's services.
