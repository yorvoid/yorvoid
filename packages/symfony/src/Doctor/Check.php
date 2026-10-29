<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

/**
 * One thing yorvoid:doctor checks. Checks are services tagged "yorvoid.doctor_check", with a "name"
 * for the report. An IntegratorError a check throws, or its service throws while being built, is
 * reported as a problem.
 *
 * @internal
 */
interface Check
{
    /**
     * @return iterable<int, Finding> None when all is well
     */
    public function findings(): iterable;
}
