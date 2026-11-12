<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Realm;

/**
 * Permits for tests of a handler by itself, without deciding: only Operations::run() issues them
 * otherwise, and this stays in the repository's tests, never in a package.
 */
final class Permits
{
    /**
     * Calls a handler with a permit for the command, e.g. Permits::call(Permits::call($principals->suspend(...), ...), $command, $origin).
     *
     * @template T
     * @template C of Command<T>
     *
     * @param \Closure(C, Permit, Origin): T $handler
     * @param C                              $command
     * @param Actor|PrincipalId|null         $actor   The operator by default; a principal ID acts as that principal
     *
     * @return T
     */
    public static function call(\Closure $handler, Command $command, Origin $origin, Actor|PrincipalId|null $actor = null): mixed
    {
        return $handler($command, self::for($command, $actor), $origin);
    }

    /**
     * @param Command<mixed>         $command
     * @param Actor|PrincipalId|null $actor   The operator by default; a principal ID acts as that principal
     */
    public static function for(Command $command, Actor|PrincipalId|null $actor = null, ?AuthorizedBy $basis = null): Permit
    {
        $operation = Operation::of($command::class);
        $actor = $actor instanceof PrincipalId ? new ActingPrincipal($command->realm, $actor) : ($actor ?? new Operator());
        $basis ??= $operation->authorizedBy[0];
        $issue = \Closure::bind(static fn (): Permit => new Permit($operation->name, $actor, $basis, 'test', $command), null, Permit::class);

        return $issue();
    }

    /**
     * A permit that was used, for what takes the operation that caused it, e.g. EmailVerification::send(): an
     * address change's, which causes the link's token, about the principal given.
     */
    public static function because(Realm $realm, ?PrincipalId $about = null): Permit
    {
        $command = new SetIdentifier($realm, $about ?? new PrincipalId(new UuidV7()), IdentifierKind::Email, 'ana@example.com');
        $permit = self::for($command);
        $permit->use($command);

        return $permit;
    }
}
