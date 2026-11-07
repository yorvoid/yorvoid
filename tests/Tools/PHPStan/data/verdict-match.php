<?php

declare(strict_types=1);

namespace Acme;

use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Verdict;

function refused(Decision $decision): void
{
    $a = Verdict::Permit === $decision->verdict;
    $b = $decision->verdict != Verdict::Deny;
    $c = 'permit' === $decision->verdict->value;
    $d = \in_array($decision->verdict, [Verdict::Permit, Verdict::PermitOffline], true);
    $e = match ($decision->verdict) {
        Verdict::Permit => true,
        default => false,
    };

    switch ($decision->verdict) {
        case Verdict::Permit:
            break;
    }

    $f = array_search($decision->verdict, Verdict::cases(), true);
}

function allowed(Decision $decision): bool
{
    $logged = $decision->verdict->value;
    $other = 'permit' === $decision->reason;

    return match ($decision->verdict) {
        Verdict::Permit => true,
        Verdict::PermitOffline, Verdict::Deny, Verdict::Indeterminate => false,
    };
}

function unrelated(mixed $value, ?Verdict $maybe): bool
{
    return 'permit' === $value || null === $maybe;
}
