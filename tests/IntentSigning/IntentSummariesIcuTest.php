<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentSigning;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\IntentSigning\IntentSummaries;
use YorvoID\IntentVerifier\Intent;

/**
 * Summaries in ICU MessageFormat, whichever catalogue Symfony found them in (ONBOARD-02).
 */
final class IntentSummariesIcuTest extends TestCase
{
    public function testAPlainCatalogueIsFormattedAsIcuToo(): void
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('array', new ArrayLoader());
        // messages.en.yaml, which Symfony reads as plain text: it would say "Remove the jane {jane}".
        $translator->addResource('array', ['acme.site.remove' => 'Remove the site {site}', 'acme.site.restore' => 'Restore {site} from {days, plural, one {# day} other {# days}} ago'], 'en', 'messages');
        $translator->addResource('array', ['acme.site.rename' => 'Rename {site} to {name}'], 'en', 'messages+intl-icu');
        $summaries = new IntentSummaries($translator);

        self::assertSame('Remove the site jane', $summaries->summarize(self::intent('acme.site.remove', ['site' => 'jane']), 'en')->text);
        self::assertSame('Restore jane from 30 days ago', $summaries->summarize(self::intent('acme.site.restore', ['site' => 'jane', 'days' => 30]), 'en')->text);
        self::assertSame('Rename jane to site', $summaries->summarize(self::intent('acme.site.rename', ['site' => 'jane', 'name' => 'site']), 'en')->text);
        self::assertSame('Remove the site jane', $summaries->summarize(self::intent('acme.site.remove', ['site' => 'jane']), 'de')->text, 'The fallback locale\'s.');
        self::assertSame('Remove the site {x}', $summaries->summarize(self::intent('acme.site.remove', ['site' => '{x}']), 'en')->text, 'Braces in a value are the value\'s.');

        $this->refused('The operation acme.site.missing has no summary in the "messages" translations.', static fn () => $summaries->summarize(self::intent('acme.site.missing', []), 'en'));

        $translator->addResource('array', ['acme.site.broken' => 'Remove {site'], 'en', 'messages');
        $this->refused('The summary of acme.site.broken isn\'t a message in ICU MessageFormat: "Remove {site".', static fn () => $summaries->summarize(self::intent('acme.site.broken', ['site' => 'jane']), 'en'));
    }

    public function testASummaryWhoseParametersWerentFilledInIsRefused(): void
    {
        // A translator that only translates, like Symfony's for a plain catalogue.
        $plain = new class implements TranslatorInterface {
            /**
             * @param array<mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return strtr('Remove the site {site}', array_map(static fn (mixed $value): string => \is_string($value) ? $value : '', $parameters));
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $this->refused('The summary of acme.site.remove has a placeholder that wasn\'t filled in: "Remove the jane {jane}".', static fn () => new IntentSummaries($plain)->summarize(self::intent('acme.site.remove', ['site' => 'jane']), 'en'));
    }

    private function refused(string $problem, \Closure $summarize): void
    {
        try {
            $summarize();
            self::fail('Summarised: '.$problem);
        } catch (IntegratorError $error) {
            self::assertSame($problem, $error->problem);
        }
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private static function intent(string $action, array $parameters): Intent
    {
        return new Intent($action, $parameters, 'acme-verifier', 'n', new \DateTimeImmutable('@1793210400'), new \DateTimeImmutable('@1793210700'));
    }
}
