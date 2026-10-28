<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Intent;

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
                throw new IntegratorError(\sprintf('The parameter "%s" of %s is longer than a page can show in full (%d characters).', $name, $intent->action, self::DISPLAY_LIMIT), 'Pass a shorter value, e.g. an ID instead of a document: what can\'t be shown can\'t be signed safely.', 'concepts/signed-intents.md#the-confirming-page');
            }

            $parameters[$name] = $text;
        }

        $domain = str_starts_with($intent->action, 'yorvoid.') ? Translations::DOMAIN : $this->domain;
        // The same text as the parameters the page lists, so the sentence and the list can't differ.
        $summary = $this->translator->trans($intent->action, $parameters, $domain, $locale);

        if ($summary === $intent->action) {
            throw new IntegratorError(\sprintf('The operation %s has no summary in the "%s" translations.', $intent->action, $domain), \sprintf('Add the message "%s", saying what it does in words, e.g. "Remove the site {site}".', $intent->action), 'concepts/signed-intents.md#the-confirming-page');
        }

        return new IntentSummary($summary, $parameters, $intent->expiresAt);
    }
}
