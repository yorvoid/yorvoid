<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Templates;

/**
 * Something a flow page needs and doesn't have.
 *
 * @api
 */
final readonly class Violation implements \Stringable
{
    public function __construct(
        public string $template,
        public string $state,
        public string $message,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('@YorvoID/flow/%s.html.twig (%s): %s', $this->template, $this->state, $this->message);
    }
}
