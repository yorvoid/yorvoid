<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Parameter;
use YorvoID\Tests\Core\Operation\Fixtures\ActivatePrincipal;
use YorvoID\Tests\Core\Operation\Fixtures\DuplicateEndSession;
use YorvoID\Tests\Core\Operation\Fixtures\EndSession;
use YorvoID\Tests\Core\Operation\Fixtures\NoConstructor;
use YorvoID\Tests\Core\Operation\Fixtures\NotACommand;
use YorvoID\Tests\Core\Operation\Fixtures\NotAnOperation;
use YorvoID\Tests\Core\Operation\Fixtures\SelfWithoutConcerns;
use YorvoID\Tests\Core\Operation\Fixtures\SuspendPrincipal;

final class OperationCatalogueTest extends TestCase
{
    public function testDescribesEachOperationAndItsParameters(): void
    {
        $catalogue = new OperationCatalogue([EndSession::class, SuspendPrincipal::class, ActivatePrincipal::class, NoConstructor::class]);
        $operation = $catalogue->get('yorvoid.session.end');

        self::assertSame('End a session', $operation->description);
        self::assertSame([AuthorizedBy::Self, AuthorizedBy::Ability], $operation->authorizedBy);
        self::assertSame('yorvoid.sessions.manage', $operation->ability);
        self::assertNull($operation->inverse);
        self::assertSame(EndSession::class, $operation->command);
        self::assertEquals([
            new Parameter('realm', 'YorvoID\Core\Realm', false, false, false),
            new Parameter('sessionId', 'string', false, false, false),
            new Parameter('reason', '?string', true, true, false),
            new Parameter('holdFor', '?YorvoID\Core\Duration', true, true, false),
            new Parameter('confirmation', 'string', false, true, true),
            new Parameter('untyped', 'string|int', false, true, false),
            new Parameter('anything', 'mixed', true, true, false),
        ], $operation->parameters);

        self::assertSame('yorvoid.principal.activate', $catalogue->get('yorvoid.principal.suspend')->inverse);
        self::assertSame([], $catalogue->get('yorvoid.log.verify')->parameters);
        self::assertSame($operation, $catalogue->forCommand(EndSession::class));
    }

    public function testRefusesACommandItDoesntHave(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage(\sprintf('The command %s isn\'t in the operation catalogue.', SuspendPrincipal::class));

        new OperationCatalogue([EndSession::class])->forCommand(SuspendPrincipal::class);
    }

    public function testRefusesAnOperationThatIsntACommand(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('has #[Operation] but isn\'t a Command');

        new OperationCatalogue([NotACommand::class]);
    }

    public function testRefusesAnOwnAccountOperationThatDoesntSayWhoseItConcerns(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('may be run on one\'s own account');

        new OperationCatalogue([SelfWithoutConcerns::class]);
    }

    public function testListsOperationsSortedByName(): void
    {
        $catalogue = new OperationCatalogue([SuspendPrincipal::class, EndSession::class, ActivatePrincipal::class]);

        self::assertSame(['yorvoid.principal.activate', 'yorvoid.principal.suspend', 'yorvoid.session.end'], array_keys($catalogue->all()));
        self::assertTrue($catalogue->has('yorvoid.session.end'));
        self::assertFalse($catalogue->has('yorvoid.session.start'));
    }

    public function testAcceptsAnyIterable(): void
    {
        $commands = static function (): \Generator {
            yield EndSession::class;
        };

        self::assertTrue(new OperationCatalogue($commands())->has('yorvoid.session.end'));
        self::assertSame([], new OperationCatalogue([])->all());
    }

    public function testRefusesAnUnknownOperation(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('There is no operation "yorvoid.session.start".');

        new OperationCatalogue([EndSession::class])->get('yorvoid.session.start');
    }

    public function testRefusesACommandWithoutTheAttribute(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('has no #[Operation] attribute');

        new OperationCatalogue([NotAnOperation::class]);
    }

    public function testRefusesTwoOperationsWithOneName(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('is declared by both');

        new OperationCatalogue([EndSession::class, DuplicateEndSession::class]);
    }

    public function testRefusesAnInverseThatIsntInTheCatalogue(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('names "yorvoid.principal.activate" as its inverse');

        new OperationCatalogue([SuspendPrincipal::class]);
    }

    /**
     * @return iterable<string, array{string, ?string, string, AuthorizedBy|list<AuthorizedBy>, ?string, string}>
     */
    public static function invalidAttributes(): iterable
    {
        $operator = AuthorizedBy::Operator;

        yield 'two segments' => ['yorvoid.end', null, 'End', $operator, null, 'is invalid'];
        yield 'uppercase' => ['yorvoid.Session.end', null, 'End', $operator, null, 'is invalid'];
        yield 'trailing dot' => ['yorvoid.session.end.', null, 'End', $operator, null, 'is invalid'];
        yield 'starts with a digit' => ['yorvoid.2fa.enable', null, 'Enable', $operator, null, 'is invalid'];
        yield 'invalid inverse' => ['yorvoid.session.end', 'start', 'End', $operator, null, 'is invalid'];
        yield 'empty description' => ['yorvoid.session.end', null, '', $operator, null, 'has no description'];
        yield 'blank description' => ['yorvoid.session.end', null, '   ', $operator, null, 'has no description'];
        yield 'trailing newline' => ["yorvoid.session.end\n", null, 'End', $operator, null, 'is invalid'];
        yield 'nothing authorizes it' => ['yorvoid.session.end', null, 'End', [], null, 'doesn\'t say what authorizes it'];
        yield 'an ability without AuthorizedBy::Ability' => ['yorvoid.session.end', null, 'End', $operator, 'yorvoid.sessions.manage', 'needs an ability exactly when'];
        yield 'AuthorizedBy::Ability without an ability' => ['yorvoid.session.end', null, 'End', AuthorizedBy::Ability, null, 'needs an ability exactly when'];
        yield 'a consequence and something else' => ['yorvoid.session.start', null, 'Start', [AuthorizedBy::Consequence, AuthorizedBy::Operator], null, 'is a consequence only, and something else'];
    }

    /**
     * @param AuthorizedBy|list<AuthorizedBy> $authorizedBy
     */
    #[DataProvider('invalidAttributes')]
    public function testRefusesInvalidAttributes(string $name, ?string $inverse, string $description, AuthorizedBy|array $authorizedBy, ?string $ability, string $message): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage($message);

        new Operation($name, $description, $authorizedBy, $ability, $inverse);
    }

    public function testAcceptsValidNames(): void
    {
        self::assertSame('acme_corp.badge.issue', new Operation('acme_corp.badge.issue', 'Issue a badge', AuthorizedBy::Operator)->name);
        self::assertSame('yorvoid.session.end_all', new Operation('yorvoid.session.end_all', 'End all', AuthorizedBy::Operator, inverse: 'yorvoid.session.x.y')->name);
    }

    public function testListsWhatAuthorizesAnOperationOnceEach(): void
    {
        self::assertSame([AuthorizedBy::Operator], new Operation('acme.badge.issue', 'Issue a badge', AuthorizedBy::Operator)->authorizedBy);
        self::assertSame([AuthorizedBy::Self, AuthorizedBy::Operator], new Operation('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Self, AuthorizedBy::Operator, AuthorizedBy::Self])->authorizedBy);
        self::assertSame([AuthorizedBy::Consequence], new Operation('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Consequence])->authorizedBy);
    }
}
