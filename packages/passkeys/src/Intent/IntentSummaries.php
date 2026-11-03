<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Intent;

use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\IntentVerifier\Intent;
use YorvoID\Passkeys\Translations;

/**
 * Summaries of intents for the confirming page (INT-011), from translations: the message whose ID is the
 * operation's name, with the intent's parameters, e.g. 'acme.site.remove' => 'Remove the site {site}'.
 * The verifier's own operations have theirs in YorvoID's catalogue.
 *
 * What the page shows is the person's only view of what they sign: browsers and authenticators show the
 * site, not the operation (T-INT-3). So an operation without a summary, or with a parameter longer than
 * a page can show in full (DISPLAY_LIMIT characters), isn't summarised: it can't be signed safely.
 *
 * @api
 */
final readonly class IntentSummaries
{
    /** The longest parameter value shown, in characters */
    public const int DISPLAY_LIMIT = 200;

    /**
     * @param string $domain Where your operations' summaries are
     */
    public function __construct(
        private TranslatorInterface $translator,
        private string $domain = 'messages',
    ) {
    }

    /**
     * @throws IntegratorError When the operation has no summary, or a parameter is too long to show
     */
    public function summarize(Intent $intent, string $locale): IntentSummary
    {
        $parameters = [];

        foreach ($intent->parameters as $name => $value) {
            $text = match (true) {
                true === $value => 'true',
                false === $value => 'false',
                \is_int($value), \is_string($value) => (string) $value,
                default => throw new IntegratorError(\sprintf('The parameter "%s" of %s isn\'t a string, an integer or a boolean.', $name, $intent->action), 'Give operations scalar parameters, as the verifier\'s policy does.', 'concepts/signed-intents.md#signing-an-operation'),
            };

            if (mb_strlen($text) > self::DISPLAY_LIMIT) {
                throw new IntegratorError(\sprintf('The parameter "%s" of %s is longer than a page can show in full (%d characters).', $name, $intent->action, self::DISPLAY_LIMIT), 'Pass a shorter value, e.g. an ID instead of a document: what can\'t be shown can\'t be signed safely.', 'concepts/signed-intents.md#summaries');
            }

            $parameters[$name] = $text;
        }

        $domain = str_starts_with($intent->action, 'yorvoid.') ? Translations::DOMAIN : $this->domain;
        // The same text as the parameters the page lists, so the sentence and the list can't differ.
        $summary = $this->format($intent->action, $parameters, $domain, $locale);

        if ($summary === $intent->action) {
            throw new IntegratorError(\sprintf('The operation %s has no summary in the "%s" translations.', $intent->action, $domain), \sprintf('Add the message "%s", saying what it does in words, e.g. "Remove the site {site}".', $intent->action), 'concepts/signed-intents.md#summaries');
        }

        // A brace that isn't in a value is a placeholder that wasn't filled in.
        if (str_contains(str_replace($parameters, '', $summary), '{')) {
            throw new IntegratorError(\sprintf('The summary of %s has a placeholder that wasn\'t filled in: "%s".', $intent->action, $summary), 'Write summaries in ICU MessageFormat, in a catalogue whose name says so, e.g. translations/messages+intl-icu.en.yaml.', 'concepts/signed-intents.md#summaries');
        }

        return new IntentSummary($summary, $parameters, $intent->expiresAt);
    }

    /**
     * The message in ICU MessageFormat, whichever catalogue it's in: Symfony treats one without "+intl-icu"
     * in its name as plain text, and would put a parameter's value wherever its name occurs ("Remove the
     * jane {jane}").
     *
     * @param array<string, string> $parameters
     */
    private function format(string $id, array $parameters, string $domain, string $locale): string
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return $this->translator->trans($id, $parameters, $domain, $locale);
        }

        // The ID itself when there's no such message: summarize() refuses that.
        $message = $this->translator->getCatalogue($locale)->get($id, $domain);

        try {
            $summary = new \MessageFormatter($locale, $message)->format($parameters);
        } catch (\IntlException) {
            $summary = false;
        }

        if (false === $summary) {
            throw new IntegratorError(\sprintf('The summary of %s isn\'t a message in ICU MessageFormat: "%s".', $id, $message), 'Write it like "Remove the site {site}", with each parameter\'s name in braces.', 'concepts/signed-intents.md#summaries');
        }

        return $summary;
    }
}
