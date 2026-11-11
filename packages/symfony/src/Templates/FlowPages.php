<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Templates;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Flows\Problem;
use YorvoID\Flows\StepView;
use YorvoID\Passkeys\Flow\View\StepUpView;
use YorvoID\Symfony\Remedy\RemedyPrompts;

/**
 * Renders the flow pages, with what every template gets: for the flows, the template gallery and the
 * template lint alike.
 *
 * @phpstan-import-type Prompt from RemedyPrompts
 *
 * @internal
 */
final readonly class FlowPages
{
    /** The browser client's error codes, for the pages to show its messages in the person's language */
    public const array CLIENT_ERRORS = ['not_supported', 'cancelled', 'aborted', 'already_registered', 'user_verification_unavailable', 'wrong_site', 'refused', 'too_many_attempts', 'signed_out', 'step_up_required', 'not_permitted', 'network', 'server', 'unknown'];

    public function __construct(
        private TranslatorInterface $translator,
        private ?Environment $twig,
        private string $script,
        private ?RemedyPrompts $remedies = null,
    ) {
    }

    /**
     * A step's page: "@YorvoID/flow/{step}.html.twig".
     *
     * @param list<Problem> $problems Why the last answer was refused
     * @param string        $action   Where its forms post to
     */
    public function step(string $flow, string $step, StepView $view, array $problems, string $action, string $locale): string
    {
        $translated = $this->problems($problems, $locale);
        $fields = [];

        foreach ($translated as $problem) {
            if (null !== $problem['field']) {
                $fields[$problem['field']] = $problem['message'];
            }
        }

        $messages = [];

        foreach (self::CLIENT_ERRORS as $code) {
            $messages[$code] = $this->translator->trans('yorvoid.ui.error.'.$code, [], 'yorvoid', $locale);
        }

        return $this->render('@YorvoID/flow/'.$step.'.html.twig', [
            'flow' => $flow,
            'step' => $step,
            'view' => $view,
            'problems' => $translated,
            'field_problems' => $fields,
            'action' => $action,
            'client_messages' => $messages,
            'operation' => $view instanceof StepUpView ? $this->operation($view->operation, $locale) : null,
        ], $locale);
    }

    /**
     * What a step-up is for, in the person's words: the message "yorvoid.operation.{name}", if there's
     * one; YorvoID has its own operations', the application adds its own.
     */
    public function operation(?string $name, string $locale): ?string
    {
        $id = 'yorvoid.operation.'.$name;
        $text = $this->translator->trans($id, [], 'yorvoid', $locale);

        return $id === $text ? null : $text;
    }

    /**
     * What the page a completed step-up returns to says: "yorvoid.ui.step_up.done".
     */
    public function stepUpDone(string $locale): string
    {
        return $this->translator->trans('yorvoid.ui.step_up.done', [], 'yorvoid', $locale);
    }

    /**
     * A flow that couldn't go on.
     *
     * @param string|null $start Where to start it again, if a request can
     */
    public function failed(string $flow, Problem $failure, ?string $start, string $locale): string
    {
        return $this->render('@YorvoID/flow/failed.html.twig', ['flow' => $flow, 'problems' => $this->problems([$failure], $locale), 'start' => $start], $locale);
    }

    /**
     * A flow that's gone: it took too long, finished already, or never began here.
     */
    public function expired(string $flow, ?string $start, string $locale): string
    {
        return $this->render('@YorvoID/flow/expired.html.twig', ['flow' => $flow, 'problems' => $this->problems([new Problem('yorvoid.flow.expired')], $locale), 'start' => $start], $locale);
    }

    /**
     * An operation refused: what would satisfy it, if anything, and the decision's reference.
     *
     * @param array{title: string, text: string|null} $summary
     * @param list<Prompt>                            $remedies
     * @param string                                  $back     Where "Go back" goes: a path on this site
     */
    public function refused(array $summary, array $remedies, ?string $reference, string $back, string $locale): string
    {
        return $this->render('@YorvoID/flow/refused.html.twig', ['flow' => null, 'problems' => [], 'summary' => $summary, 'remedies' => $remedies, 'reference' => $reference, 'back' => $back], $locale);
    }

    /**
     * A sample page, for the gallery and the lint: its forms post to Sample::ACTION.
     */
    public function sample(Sample $sample, string $locale): string
    {
        return match ($sample->template) {
            'failed' => $this->failed($sample->flow, $sample->problems[0] ?? new Problem('yorvoid.flow.out_of_date'), Sample::START, $locale),
            'expired' => $this->expired($sample->flow, Sample::START, $locale),
            'refused' => $this->sampleRefusal($sample, $locale),
            default => $this->step($sample->flow, $sample->template, $sample->view ?? new \YorvoID\Passkeys\Flow\View\SetupCodeView(), $sample->problems, Sample::ACTION, $locale),
        };
    }

    private function sampleRefusal(Sample $sample, string $locale): string
    {
        if (null === $this->remedies || null === $sample->decision) {
            throw new IntegratorError('A refusal\'s sample needs its decision, and the remedies\' prompts.', 'Render it through the container\'s FlowPages.', 'concepts/symfony.md#flow-pages');
        }

        return $this->refused($this->remedies->summary($sample->decision, $locale), $this->remedies->of($sample->decision, Sample::START, $locale), $this->remedies->reference($sample->decision, $locale), Sample::START, $locale);
    }

    /**
     * @param list<Problem> $problems
     *
     * @return list<array{message: string, field: string|null, reason: string|null}>
     */
    public function problems(array $problems, string $locale): array
    {
        return array_map(fn (Problem $problem): array => ['message' => $this->translator->trans($problem->message, $problem->parameters, 'yorvoid', $locale), 'field' => $problem->field, 'reason' => $problem->reason], $problems);
    }

    /**
     * The template gallery's index: "@YorvoID/gallery/index.html.twig".
     *
     * @param list<array{template: string, state: string, links: array<string, string>}> $samples Each sample's page, by language
     *
     * @internal
     */
    public function gallery(array $samples): string
    {
        return $this->render('@YorvoID/gallery/index.html.twig', ['samples' => $samples], 'en');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(string $template, array $parameters, string $locale): string
    {
        if (null === $this->twig) {
            throw new IntegratorError('YorvoID\'s flow pages need Twig.', 'Install symfony/twig-bundle, or use the flows from the browser client, which asks for JSON.', 'concepts/symfony.md#flow-pages');
        }

        return $this->twig->render($template, $parameters + ['locale' => $locale, 'script' => $this->script]);
    }
}
