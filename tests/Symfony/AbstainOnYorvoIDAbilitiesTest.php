<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use YorvoID\Policy\Ability;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyDeclarations;
use YorvoID\Symfony\Security\AbstainOnYorvoIDAbilities;
use YorvoID\Tests\Symfony\App\RecordingVoter;

/**
 * Another voter, wrapped: its grant of a declared ability is taken away, its refusal stays, the
 * reasons are those of the vote that counts, and it's asked what it supports as before.
 */
final class AbstainOnYorvoIDAbilitiesTest extends TestCase
{
    public function testAGrantOfADeclaredAbilityIsAskedAgainWithoutIt(): void
    {
        $inner = self::voter(VoterInterface::ACCESS_GRANTED);
        $wrapped = new AbstainOnYorvoIDAbilities($inner, self::catalogue());

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $wrapped->vote(new NullToken(), null, ['acme.pages.view']), 'Nothing else was asked.');
        self::assertSame([['acme.pages.view']], $inner->asked, 'Asked once, not again with nothing.');

        $inner->asked = [];
        self::assertSame(VoterInterface::ACCESS_GRANTED, $wrapped->vote(new NullToken(), null, ['acme.pages.view', 'ROLE_ADMIN', 'acme.pages.publish', 'IS_AUTHENTICATED']));
        self::assertSame([['acme.pages.view', 'ROLE_ADMIN', 'acme.pages.publish', 'IS_AUTHENTICATED'], ['ROLE_ADMIN', 'IS_AUTHENTICATED']], $inner->asked, 'Again with only the others, as a list.');

        $inner->asked = [];
        self::assertSame(VoterInterface::ACCESS_GRANTED, $wrapped->vote(new NullToken(), null, ['ROLE_ADMIN']));
        self::assertSame([['ROLE_ADMIN']], $inner->asked, 'Nothing declared: asked once.');
    }

    public function testRefusalsAndAbstentionsStand(): void
    {
        foreach ([VoterInterface::ACCESS_DENIED, VoterInterface::ACCESS_ABSTAIN] as $result) {
            $inner = self::voter($result);

            self::assertSame($result, new AbstainOnYorvoIDAbilities($inner, self::catalogue())->vote(new NullToken(), null, ['acme.pages.view']));
            self::assertCount(1, $inner->asked);
        }
    }

    public function testTheReasonsAreThoseOfTheVoteThatCounts(): void
    {
        $vote = new Vote();
        new AbstainOnYorvoIDAbilities(self::voter(VoterInterface::ACCESS_GRANTED), self::catalogue())->vote(new NullToken(), null, ['acme.pages.view', 'ROLE_ADMIN'], $vote);
        self::assertSame(['Voted on ROLE_ADMIN.'], $vote->reasons, 'Not the grant taken away.');

        $vote = new Vote();
        new AbstainOnYorvoIDAbilities(self::voter(VoterInterface::ACCESS_DENIED), self::catalogue())->vote(new NullToken(), null, ['acme.pages.view'], $vote);
        self::assertSame(['Voted on acme.pages.view.'], $vote->reasons);

        $vote = new Vote();
        new AbstainOnYorvoIDAbilities(self::voter(VoterInterface::ACCESS_GRANTED), self::catalogue())->vote(new NullToken(), null, ['acme.pages.view'], $vote);
        self::assertSame([], $vote->reasons, 'Abstaining gives none.');

        $inner = self::voter(VoterInterface::ACCESS_GRANTED);
        new AbstainOnYorvoIDAbilities($inner, self::catalogue())->vote(new NullToken(), null, ['acme.pages.view', 'ROLE_ADMIN']);
        self::assertSame([false, false], $inner->withVote, 'Without a vote to explain, none is made.');
    }

    public function testItSupportsWhatTheVoterSupports(): void
    {
        $cacheable = new class implements CacheableVoterInterface {
            public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
            {
                return self::ACCESS_ABSTAIN;
            }

            public function supportsAttribute(string $attribute): bool
            {
                return 'ROLE_ADMIN' === $attribute;
            }

            public function supportsType(string $subjectType): bool
            {
                return 'string' === $subjectType;
            }
        };
        $wrapped = new AbstainOnYorvoIDAbilities($cacheable, self::catalogue());

        self::assertTrue($wrapped->supportsAttribute('ROLE_ADMIN'));
        self::assertFalse($wrapped->supportsAttribute('ROLE_USER'));
        self::assertTrue($wrapped->supportsType('string'));
        self::assertFalse($wrapped->supportsType('null'));

        $plain = new AbstainOnYorvoIDAbilities(self::voter(VoterInterface::ACCESS_ABSTAIN), self::catalogue());
        self::assertTrue($plain->supportsAttribute('ROLE_USER'), 'A voter that can\'t say is asked about everything.');
        self::assertTrue($plain->supportsType('null'));
    }

    /**
     * @param VoterInterface::ACCESS_* $result
     */
    private static function voter(int $result): RecordingVoter
    {
        $voter = new RecordingVoter();
        $voter->result = $result;

        return $voter;
    }

    private static function catalogue(): PolicyCatalogue
    {
        return PolicyCatalogue::of([new class implements PolicyDeclarations {
            public function abilities(): iterable
            {
                yield new Ability('acme.pages.view', 'View pages', 'acme');
                yield new Ability('acme.pages.publish', 'Publish pages', 'acme');
            }

            public function roles(): iterable
            {
                return [];
            }
        }]);
    }
}
