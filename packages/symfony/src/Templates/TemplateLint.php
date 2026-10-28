<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Templates;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Checks the flow pages as the application renders them, its own templates included, for what each
 * step needs (UX-014): the fields and their names, autocomplete tokens, labels, problems people can
 * find, the passkey ceremony's hooks, and the copy that mustn't go, like a signing page's summary.
 * The server's checks never depend on it: it's for the people using the pages.
 *
 * In a test: self::assertSame([], $container->get(TemplateLint::class)->lint());
 *
 * @api
 */
final readonly class TemplateLint
{
    public function __construct(
        private FlowPages $pages,
    ) {
    }

    /**
     * @return list<Violation> None when every page has what it needs
     */
    public function lint(string $locale = 'en'): array
    {
        $violations = [];

        foreach (Samples::all() as $sample) {
            foreach ($this->check($sample, $this->pages->sample($sample, $locale), $locale) as $message) {
                $violations[] = new Violation($sample->template, $sample->state, $message);
            }
        }

        return $violations;
    }

    /**
     * What a page rendered for a sample lacks.
     *
     * @return list<string>
     */
    public function check(Sample $sample, string $html, string $locale = 'en'): array
    {
        $page = HTMLDocument::createFromString($html, \LIBXML_NOERROR);

        return [...$this->common($page, $sample, $locale), ...$this->step($page, $sample)];
    }

    /**
     * What every page needs.
     *
     * @return list<string>
     */
    private function common(HTMLDocument $page, Sample $sample, string $locale): array
    {
        $messages = [];

        \assert(null !== $page->documentElement);

        if ('' === trim((string) $page->documentElement->getAttribute('lang'))) {
            $messages[] = 'The page doesn\'t say its language: <html lang="…">.';
        }

        // Already without the space around it.
        if ('' === $page->title) {
            $messages[] = 'The page has no title.';
        }

        if (null === $page->querySelector('h1')) {
            $messages[] = 'The page has no heading: <h1>.';
        }

        foreach ($page->querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea') as $control) {
            if (!self::isLabelled($page, $control)) {
                $messages[] = \sprintf('The field "%s" has no label.', $control->getAttribute('name') ?? $control->getAttribute('id') ?? '?');
            }
        }

        foreach ($page->querySelectorAll('form') as $form) {
            if ('post' !== strtolower((string) $form->getAttribute('method'))) {
                $messages[] = 'A form doesn\'t post: method="post".';
            }

            if (Sample::ACTION !== $form->getAttribute('action')) {
                $messages[] = 'A form doesn\'t post to its step: action="{{ action }}".';
            }
        }

        $alerts = implode(' ', array_map(static fn (Element $alert): ?string => $alert->textContent, iterator_to_array($page->querySelectorAll('[role="alert"]'))));

        foreach ($this->pages->problems($sample->problems, $locale) as $problem) {
            if (!str_contains(self::normalized($alerts), self::normalized($problem['message']))) {
                $messages[] = \sprintf('The problem "%s" isn\'t in an alert: role="alert".', $problem['message']);
            }

            if (null !== $problem['field']) {
                $messages = [...$messages, ...self::fieldProblem($page, $problem['field'], $problem['message'])];
            }
        }

        return $messages;
    }

    /**
     * What the step's page needs.
     *
     * @return list<string>
     */
    private function step(HTMLDocument $page, Sample $sample): array
    {
        $view = $sample->view?->jsonSerialize() ?? [];
        // Each text by itself: elements next to each other don't run their words together.
        $text = self::normalized(implode(' ', array_map(static fn (\Dom\Node $node): ?string => $node->textContent, iterator_to_array(new \Dom\XPath($page)->query('//*[local-name()="body"]//text()')))));

        return match ($sample->template) {
            'passkey' => [...self::ceremony($page, 'get'), ...self::require($page, 'input[autocomplete~="webauthn"]', 'The email field doesn\'t offer passkeys: autocomplete="username webauthn".')],
            'passkey_registration' => null === $view['options'] ? [] : [
                ...self::ceremony($page, 'create'),
                ...(true === $view['can_skip'] ? self::require($page, 'button[name="action"][value="skip"]', 'There\'s no way to add a passkey later: a button name="action" value="skip".') : []),
            ],
            'step_up' => null === $view['options'] ? [] : self::ceremony($page, 'get'),
            'confirm' => [...self::ceremony($page, 'get'), ...self::shows($text, [self::text($view['summary'] ?? null), ...array_map(self::text(...), array_keys((array) ($view['parameters'] ?? []))), ...array_map(self::text(...), (array) ($view['parameters'] ?? []))], 'The page doesn\'t show what is signed in full: "%s" is missing.')],
            'setup_code' => [...self::require($page, 'input[name="code"][autocomplete="one-time-code"]', 'There\'s no setup code field: name="code" autocomplete="one-time-code".'), ...self::require($page, 'input[name="email"][type="email"]', 'There\'s no email field: name="email" type="email".')],
            'recovery_code' => [...self::require($page, 'input[name="email"]', 'There\'s no email field: name="email".'), ...self::require($page, 'input[name="code"]', 'There\'s no recovery code field: name="code".')],
            'recovery_codes' => [...self::shows($text, array_map(self::text(...), (array) ($view['codes'] ?? [])), 'A recovery code isn\'t shown: "%s".'), ...self::require($page, 'input[name="saved"][type="checkbox"]', 'There\'s no way to confirm the codes were saved: a checkbox name="saved".')],
            'invitation' => null === $view['email'] ? [] : [...self::shows($text, [self::text($view['email'])], 'The page doesn\'t say who is invited: "%s".'), ...self::require($page, 'form button[type="submit"], form button:not([type])', 'There\'s no way to accept: a form with a button.')],
            'recovery_link' => self::require($page, 'form button[type="submit"], form button:not([type])', 'There\'s no way to continue: a form with a button.'),
            default => [],
        };
    }

    /**
     * A form the browser client runs a passkey ceremony from.
     *
     * @return list<string>
     */
    private static function ceremony(HTMLDocument $page, string $kind): array
    {
        $form = $page->querySelector(\sprintf('form[data-yorvoid-ceremony="%s"]', $kind));

        if (null === $form) {
            return [\sprintf('There\'s no passkey form: <form data-yorvoid-ceremony="%s" data-yorvoid-options="{{ view.options }}">.', $kind)];
        }

        return [
            ...(json_validate((string) $form->getAttribute('data-yorvoid-options')) ? [] : ['The passkey form doesn\'t have the ceremony\'s options: data-yorvoid-options="{{ view.options }}".']),
            ...(null === $form->querySelector('input[name="response"]') ? ['The passkey form has no field for the answer: <input type="hidden" name="response">.'] : []),
            ...(null === $form->querySelector('[data-yorvoid-error]') ? ['The passkey form has nowhere to say what went wrong: an element with data-yorvoid-error.'] : []),
            ...(null === $page->querySelector('script[type="module"][src]') ? ['The page doesn\'t load the browser client\'s script: <script type="module" src="{{ script }}">.'] : []),
        ];
    }

    /**
     * @return list<string>
     */
    private static function fieldProblem(HTMLDocument $page, string $field, string $message): array
    {
        $control = $page->querySelector(\sprintf('[name="%s"]', $field));

        if (null === $control) {
            return [\sprintf('There\'s no field "%s" for its problem.', $field)];
        }

        $ids = explode(' ', (string) preg_replace('/\s+/', ' ', (string) $control->getAttribute('aria-describedby')));
        $described = implode(' ', array_map(static fn (string $id): string => (string) $page->getElementById($id)?->textContent, $ids));

        return [
            ...('true' === $control->getAttribute('aria-invalid') ? [] : [\sprintf('The field "%s" doesn\'t say it\'s invalid: aria-invalid="true".', $field)]),
            ...(str_contains(self::normalized($described), self::normalized($message)) ? [] : [\sprintf('The field "%s" doesn\'t say why it\'s invalid: aria-describedby, to its problem.', $field)]),
        ];
    }

    private static function isLabelled(HTMLDocument $page, Element $control): bool
    {
        $id = $control->getAttribute('id');

        return (null !== $id && null !== $page->querySelector(\sprintf('label[for="%s"]', addcslashes($id, '"\\'))))
            || null !== $control->closest('label')
            || '' !== trim((string) $control->getAttribute('aria-label'))
            || '' !== trim((string) $control->getAttribute('aria-labelledby'));
    }

    /**
     * @return list<string>
     */
    private static function require(HTMLDocument $page, string $selector, string $message): array
    {
        return null === $page->querySelector($selector) ? [$message] : [];
    }

    /**
     * @param array<string> $texts
     *
     * @return list<string>
     */
    private static function shows(string $text, array $texts, string $message): array
    {
        // As a whole: "no" isn't shown by "nothing". Nothing, like a value that isn't text, has nothing to show.
        return array_values(array_map(static fn (string $missing): string => \sprintf($message, $missing), array_filter($texts, static fn (string $shown): bool => '' !== self::normalized($shown) && 1 !== preg_match('/(?<![\p{L}\p{N}])'.preg_quote(self::normalized($shown), '/').'(?![\p{L}\p{N}])/u', $text))));
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private static function normalized(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
