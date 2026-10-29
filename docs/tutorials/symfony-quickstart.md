# Quickstart: passkeys in a Symfony application

In about 15 minutes, a fresh Symfony application where you claim the first account with a passkey, sign out, and sign in again (PLT-080).

You need PHP 8.5, [Composer](https://getcomposer.org), and a browser with passkeys: any current Chrome, Edge, Firefox or Safari, with your device's screen lock or a security key.

## 1. Create the application

<!-- quickstart: run -->
```bash
composer create-project symfony/skeleton:"8.1.*" acme
cd acme
```

## 2. Give it a database

YorvoID keeps its tables in your database through Doctrine DBAL; it needs no ORM. SQLite is enough to start:

<!-- quickstart: write .env.local -->
```dotenv
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
```

<!-- quickstart: write config/packages/doctrine.yaml -->
```yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
```

Write this file before the next step: DoctrineBundle's recipe would otherwise configure the ORM, which YorvoID doesn't use. If your application already uses the ORM, keep your configuration.

## 3. Install YorvoID

<!-- quickstart: run -->
```bash
composer require yorvoid/symfony doctrine/doctrine-bundle symfony/twig-bundle
```

Twig renders YorvoID's sign-in pages. Then register the bundle:

<!-- quickstart: insert config/bundles.php before ]; -->
```php
    YorvoID\Symfony\YorvoIDBundle::class => ['all' => true],
```

## 4. Configure YorvoID

<!-- quickstart: write config/packages/yorvoid.yaml -->
```yaml
yorvoid:
    passkeys:
        relying_party_id: localhost
        origins: ['http://localhost:8000']
    notifications:
        enabled: false
```

- **The relying party ID** is the domain passkeys belong to: they work on it and its subdomains, nowhere else. In production it's your site's domain, e.g. `example.com`, with `https://example.com` as the origin.
- **Notifications** email people about new sign-ins and passkeys. They need Symfony Mailer; turn them on once it's set up ([notifications](../concepts/notifications.md)).

## 5. Sign in through your firewall

YorvoID never takes over your firewall: you put its user provider, user checker and authenticators in it.

<!-- quickstart: write config/packages/security.yaml -->
```yaml
security:
    providers:
        yorvoid:
            id: YorvoID\Symfony\Security\PrincipalUserProvider
    firewalls:
        dev:
            pattern: ^/(_profiler(/|$)|(_wdt|assets|build)/)
            security: false
        main:
            lazy: true
            provider: yorvoid
            user_checker: YorvoID\Symfony\Security\PrincipalUserChecker
            custom_authenticators:
                - YorvoID\Symfony\Security\Authenticator\PasskeyAuthenticator
                - YorvoID\Symfony\Security\Authenticator\RecoveryCodeAuthenticator
                - YorvoID\Symfony\Security\Authenticator\RecoveryLinkAuthenticator
                - YorvoID\Symfony\Security\Authenticator\SessionAuthenticator
            logout:
                path: /sign-out
```

Signing out at `/sign-out` ends the YorvoID session too, not only the PHP session. YorvoID's pages and endpoints go under `/yorvoid`:

<!-- quickstart: write config/routes/yorvoid.yaml -->
```yaml
yorvoid:
    resource: '@YorvoIDBundle/config/routes.php'
    prefix: /yorvoid
```

## 6. A page that knows who's signed in

People land on `/` when a flow completes:

<!-- quickstart: write src/Controller/HomeController.php -->
```php
<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Symfony\Security\CurrentSession;

final class HomeController
{
    #[Route('/', name: 'home')]
    public function __invoke(CurrentSession $current, Principals $principals): Response
    {
        $session = $current->get();

        if (null === $session) {
            return new Response('<p>Not signed in. <a href="/yorvoid/flows/yorvoid.sign_in">Sign in</a></p>');
        }

        $email = $principals->identifiers($session->realm, $session->principalId)[IdentifierKind::Email->value]->value;

        return new Response(sprintf('<p>Signed in as %s. <a href="/sign-out">Sign out</a></p>', htmlspecialchars($email)));
    }
}
```

## 7. Create the tables, and check

<!-- quickstart: run -->
```bash
php bin/console assets:install public
php bin/console yorvoid:migrate
php bin/console yorvoid:doctor
```

`yorvoid:migrate` creates YorvoID's tables, separately from your own migrations. `yorvoid:doctor` checks the setup and says how to fix anything it finds; it should end with "YorvoID's setup is ready."

## 8. Claim the first account

A fresh installation has no accounts, and no default password: whoever has the server's shell creates a setup code, and claims the installation with it.

<!-- quickstart: setup-code -->
```bash
php bin/console yorvoid:setup-code
```

Start the application. Use `localhost`, not `127.0.0.1`: passkeys belong to domain names, not addresses.

<!-- quickstart: serve -->
```bash
php -S localhost:8000 -t public
```

Open <http://localhost:8000/yorvoid/flows/yorvoid.setup>:

1. Enter the setup code and your email address, and continue.
2. Name your passkey, e.g. "Laptop", and add it: your browser asks for your screen lock or security key.
3. Save the ten recovery codes somewhere safe, confirm, and continue.

You land on the home page: "Signed in as" your address.

## 9. Sign out, and back in

Follow "Sign out", then "Sign in". The sign-in page offers your passkey in the email field's suggestions, or with "Sign in with a passkey". No password, nothing to type.

## What's next

- **People:** [invite them](../concepts/invitations.md) by email; they set up their own passkeys.
- **Email:** set up Symfony Mailer and turn on [notifications](../concepts/notifications.md).
- **Your look:** [change the sign-in pages](../concepts/symfony.md#changing-the-templates) with your own templates.
- **Your kind of application:** pick a [preset](../concepts/symfony.md#presets).
- **Your users:** [sign in your own user objects](../concepts/symfony.md#your-own-users).
- **Tests:** [sign in to functional tests](../concepts/testing.md) through YorvoID.
- **Production:** HTTPS, your domain as the relying party ID, PostgreSQL, and `yorvoid:doctor --strict` in your deployment.
