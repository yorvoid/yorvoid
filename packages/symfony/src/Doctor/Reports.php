<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

/**
 * A check that also says what it found when all is well, e.g. which mode is configured: yorvoid:doctor
 * prints it after the check's name.
 *
 * @internal
 */
interface Reports
{
    public function summary(): string;
}
