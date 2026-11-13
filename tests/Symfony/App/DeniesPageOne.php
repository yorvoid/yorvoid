<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use YorvoID\Core\Authorization\ResourceRef;

/**
 * An application's own restriction on one of its declared abilities: nobody views page 1, whatever
 * YorvoID permits.
 *
 * @extends Voter<string, mixed>
 */
final class DeniesPageOne extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return 'acme.pages.view' === $attribute && $subject instanceof ResourceRef && $subject->equals(new ResourceRef('page', '1'));
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $vote?->addReason('Page 1 is closed.');

        return false;
    }
}
