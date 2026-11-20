<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\DeletePrincipal;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Policy\Attribute\ForgetAttributesOfDeletedPrincipals;
use YorvoID\Policy\ForgetRolesOfDeletedPrincipals;
use YorvoID\Relations\ForgetRelationsOfDeletedPrincipals;
use YorvoID\Testing\Ids;
use YorvoID\Testing\InMemoryStorage;
use YorvoID\Tests\Support\Permits;

/**
 * What a deletion makes go, it does on the deletion's permit (D-41, G-1 Part B): an event made up to
 * name another principal than the one deleted is refused, and that principal keeps everything.
 */
final class ForgettingOnDeletionTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(InMemoryStorage): (callable(PrincipalStatusChanging): void)}>
     */
    public static function listeners(): iterable
    {
        yield 'roles' => [static fn (InMemoryStorage $memory): callable => new ForgetRolesOfDeletedPrincipals($memory->policy)(...)];
        yield 'relations' => [static fn (InMemoryStorage $memory): callable => new ForgetRelationsOfDeletedPrincipals($memory->relations)(...)];
        yield 'attributes' => [static fn (InMemoryStorage $memory): callable => new ForgetAttributesOfDeletedPrincipals($memory->attributes)(...)];
    }

    /**
     * @param \Closure(InMemoryStorage): (callable(PrincipalStatusChanging): void) $listener
     */
    #[DataProvider('listeners')]
    public function testAnEventNamingAnotherPrincipalIsRefused(\Closure $listener): void
    {
        $realm = new Realm('acme');
        $deleted = new PrincipalId(Ids::next());
        $other = self::principal($realm, new PrincipalId(Ids::next()), PrincipalStatus::Active);
        $permit = Permits::for($command = new DeletePrincipal($realm, $deleted));
        $permit->use($command);

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('about another principal');

        $listener(new InMemoryStorage())(new PrincipalStatusChanging($other, self::principal($realm, $other->id, PrincipalStatus::Deleted), new Origin(OriginKind::Web), null, $permit));
    }

    private static function principal(Realm $realm, PrincipalId $id, PrincipalStatus $status): Principal
    {
        $now = new \DateTimeImmutable();

        return new Principal($id, $realm, PrincipalKind::Human, $status, $now, $now, $now);
    }
}
