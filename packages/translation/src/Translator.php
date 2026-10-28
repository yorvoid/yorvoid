<?php

declare(strict_types=1);

namespace YorvoID\Translation;

use Symfony\Contracts\Translation\TranslatorInterface;
use YorvoID\Core\Exception\IntegratorError;

/**
 * Translates YorvoID's user-facing messages (PLT-050): ICU MessageFormat, from catalogues that each
 * package ships, in English and German.
 *
 * A catalogue is a PHP file named `{domain}+intl-icu.{locale}.php` that returns message IDs and their
 * messages, as Symfony's translator expects them: an application with Symfony's translator loads the
 * same directories, and uses it instead.
 *
 * A message missing in a locale comes from the less specific one (de_AT from de), then from the fallback
 * locale; a message missing everywhere is its ID.
 *
 * @api
 */
final class Translator implements TranslatorInterface
{
    /** @var array<string, array<string, array<string, string>>> By domain, then locale: the messages */
    private array $catalogues = [];

    /**
     * @param list<string> $directories Where the catalogues are, e.g. AuthnTranslations::DIRECTORY
     * @param string       $locale      The locale when none is asked for
     * @param string       $fallback    The locale of messages missing in the one asked for
     */
    public function __construct(
        private readonly array $directories,
        private readonly string $locale = 'en',
        private readonly string $fallback = 'en',
    ) {
    }

    /**
     * @param array<mixed> $parameters By name, with or without braces: ['count' => 3] or ['{count}' => 3]
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $domain ??= 'messages';
        $locale ??= $this->locale;

        foreach ($this->chain($locale) as $candidate) {
            $message = $this->catalogue($domain, $candidate)[$id] ?? null;

            if (null !== $message) {
                return $this->format($candidate, $id, $message, $parameters);
            }
        }

        return $id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * @return list<string> The locale, the less specific ones, then the fallback (which may repeat one)
     */
    private function chain(string $locale): array
    {
        $chain = [$locale];

        while (false !== ($cut = strrpos($locale, '_'))) {
            $locale = substr($locale, 0, $cut);
            $chain[] = $locale;
        }

        return [...$chain, $this->fallback];
    }

    /**
     * @return array<string, string>
     */
    private function catalogue(string $domain, string $locale): array
    {
        if (!isset($this->catalogues[$domain][$locale])) {
            $this->catalogues[$domain][$locale] = [];

            foreach ($this->directories as $directory) {
                $this->catalogues[$domain][$locale] = [...$this->catalogues[$domain][$locale], ...self::load(\sprintf('%s/%s+intl-icu.%s.php', $directory, $domain, $locale))];
            }
        }

        return $this->catalogues[$domain][$locale];
    }

    /**
     * @return array<string, string>
     */
    private static function load(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $loaded = require $file;
        $messages = [];

        foreach (\is_array($loaded) ? $loaded : [null] as $id => $message) {
            if (!\is_string($id) || !\is_string($message)) {
                throw new IntegratorError(\sprintf('The catalogue %s isn\'t a list of message IDs and messages.', $file), 'Return an array of message IDs and their ICU messages, both strings.', 'concepts/translations.md#catalogues');
            }

            $messages[$id] = $message;
        }

        return $messages;
    }

    /**
     * @param array<mixed> $parameters
     */
    private function format(string $locale, string $id, string $message, array $parameters): string
    {
        $named = [];

        foreach ($parameters as $name => $value) {
            $named[trim((string) $name, '{}%')] = $value;
        }

        $formatted = \MessageFormatter::formatMessage($locale, $message, $named);

        if (false === $formatted) {
            throw new IntegratorError(\sprintf('The message "%s" in %s isn\'t valid ICU MessageFormat.', $id, $locale), 'Fix the message in its catalogue, e.g. balance its braces.', 'concepts/translations.md#catalogues');
        }

        return $formatted;
    }
}
