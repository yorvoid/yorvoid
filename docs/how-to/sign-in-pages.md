# Change the sign-in pages

YorvoID's pages work as they are: accessible, unstyled, in English and German. Change as little as you can, so YorvoID's improvements keep reaching you.

1. **Your look:** override the layout, every page extends it:

   ```twig
   {# templates/bundles/YorvoIDBundle/flow/layout.html.twig #}
   {% extends '@!YorvoID/flow/layout.html.twig' %}

   {% block head %}
       {{ parent() }}
       <link rel="stylesheet" href="/css/sign-in.css">
   {% endblock %}
   ```

2. **The wording:** translations in the `yorvoid` domain, never templates ([translations](../concepts/translations.md)).

3. **One part of one page:** extend that page, and override only its block, e.g. `alternatives` on the sign-in page.

4. **A whole page:** `php bin/console yorvoid:templates:publish passkey` copies it into your application; after an upgrade, `yorvoid:templates:diff` shows what YorvoID changed in it.

5. **Check:** `php bin/console yorvoid:templates:lint` says whether each page still has what its step needs; in development, the [gallery](../concepts/symfony.md#changing-the-templates) shows every page in every state, once you import its routes.

The details are in [changing the templates](../concepts/symfony.md#changing-the-templates).
