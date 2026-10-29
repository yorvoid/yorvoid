<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use YorvoID\Core\Exception\IntegratorError;

/**
 * Something yorvoid:doctor found, and how to fix it (PLT-081, PLT-085). A problem breaks something:
 * sign-in, emails, the flow pages. A warning works, but less safely than it could, or than the
 * configuration says.
 *
 * @internal
 */
final readonly class Finding
{
    /**
     * @param string $docs The documentation page that explains it, relative to the docs folder
     */
    private function __construct(
        public bool $problem,
        public string $what,
        public string $fix,
        public string $docs,
    ) {
    }

    public static function problem(string $what, string $fix, string $docs): self
    {
        return new self(true, $what, $fix, $docs);
    }

    public static function warning(string $what, string $fix, string $docs): self
    {
        return new self(false, $what, $fix, $docs);
    }

    /**
     * What YorvoID refused when the doctor built it: the error already says how to fix it.
     */
    public static function of(IntegratorError $error): self
    {
        return new self(true, $error->problem, $error->fix, $error->docs);
    }

    public function url(): string
    {
        return IntegratorError::DOCS.$this->docs;
    }
}
