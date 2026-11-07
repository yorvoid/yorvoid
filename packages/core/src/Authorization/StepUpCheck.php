<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Operation\Actor;

/**
 * Whether an actor proved who it is as recently, and as strongly, as asked: for permits that ask for
 * it, e.g. giving a strict ability (AUZ-052). yorvoid/authn's sessions answer it.
 *
 * @api
 */
interface StepUpCheck
{
    /**
     * Holds when it did; fails otherwise, with what would satisfy it (a step-up, or waiting).
     */
    public function check(Actor $actor, StepUpRemedy $required): Truth;
}
