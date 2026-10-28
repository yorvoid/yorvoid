<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Templates;

use YorvoID\Flows\Problem;
use YorvoID\Flows\StepView;

/**
 * A flow page with made-up data, in one of its states: what the gallery shows and the lint checks.
 *
 * @api
 */
final readonly class Sample
{
    /** Where a sample's forms post to */
    public const string ACTION = '/yorvoid/flows/sample/step';

    /** Where a sample flow starts again */
    public const string START = '/yorvoid/flows/sample';

    /**
     * @param string        $template E.g. "passkey", for "@YorvoID/flow/passkey.html.twig"
     * @param string        $state    E.g. "default", "problems", "can_skip"
     * @param StepView|null $view     The step's view model; null for "failed" and "expired"
     * @param list<Problem> $problems
     */
    public function __construct(
        public string $template,
        public string $state,
        public string $flow,
        public ?StepView $view,
        public array $problems = [],
    ) {
    }
}
