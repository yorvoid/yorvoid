# Mail

YorvoID's emails are built from **structured content**, not templates: a subject and a body of blocks (paragraphs, labelled facts, an action, a footnote), already in the reader's language. A renderer makes plain text and accessible HTML of them, and your application delivers them. It's the `yorvoid/mail` package; [security notifications](notifications.md) use it, and you can use it for your own emails.

## Emails

```php
use YorvoID\Mail\Block\Action;
use YorvoID\Mail\Block\Facts;
use YorvoID\Mail\Block\Footnote;
use YorvoID\Mail\Block\Paragraph;
use YorvoID\Mail\Email;

$email = new Email('ana@example.com', 'Your export is ready', [
    new Paragraph('The export you asked for is ready to download for 24 hours.'),
    new Facts(['Size' => '12 MB', 'Format' => 'CSV']),
    new Action('Download the export', 'https://example.com/exports/123'),
    new Footnote('You asked for this export on example.com.'),
], locale: 'en');
```

- The address must be an email address, and an action's link an absolute `https://` (or `http://`) address: anything else is refused, so a `javascript:` link can't reach an inbox.
- Wording comes from your translations; YorvoID's own emails have their messages in its [catalogues](translations.md).

## Rendering

`Renderer::text($email)` and `Renderer::html($email)` give the two parts of a multipart email. The HTML is accessible:

- the language and its direction (`dir="rtl"` for Arabic, Hebrew, Persian and others);
- one column, readable at 320 px, real text and no images;
- facts as a table with row headers, for screen readers;
- the action as a button, with its address also written out for clients that hide buttons;
- colours with enough contrast, and `color-scheme` for dark mode;
- everything from the email escaped.

## Delivering

Implement `Mailer` with your application's way of sending email; the Symfony bridge provides one for Symfony Mailer.

```php
final class SymfonyMailer implements Mailer
{
    public function __construct(private MailerInterface $mailer) {}

    public function send(Email $email): void
    {
        $this->mailer->send(new \Symfony\Component\Mime\Email()
            ->to($email->to)
            ->subject($email->subject)
            ->text(Renderer::text($email))
            ->html(Renderer::html($email)));
    }
}
```

`InMemoryMailer` keeps what it's given instead of sending it: for tests, and for previews.
