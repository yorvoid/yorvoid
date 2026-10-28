<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\Parameter;
use YorvoID\Tests\Core\Operation\Fixtures\ActivatePrincipal;
use YorvoID\Tests\Core\Operation\Fixtures\DuplicateEndSession;
use YorvoID\Tests\Core\Operation\Fixtures\EndSession;
use YorvoID\Tests\Core\Operation\Fixtures\NoConstructor;
use YorvoID\Tests\Core\Operation\Fixtures\NotAnOperation;
use YorvoID\Tests\Core\Operation\Fixtures\SuspendPrincipal;

final class OperationCatalogueTest extends TestCase
{
    public function testDescribesEachOperationAndItsParameters(): void
    {
        $catalogue = new OperationCatalogue([EndSession::class, SuspendPrincipal::class, ActivatePrincipal::class, NoConstructor::class]);
        $operation = $catalogue->get('yorvoid.session.end');

        self::assertSame('End a session', $operation->description);
        self::assertSame('yorvoid.sessions.manage', $operation->ability);
        self::assertNull($operation->inverse);
        self::assertSame(EndSession::class, $operation->command);
        self::assertEquals([
            new Parameter('sessionId', 'string', false, false, false),
            new Parameter('reason', '?string', true, true, false),
            new Parameter('holdFor', '?YorvoID\Core\Duration', true, true, false),
            new Parameter('confirmation', 'string', false, true, true),
            new Parameter('untyped', 'string|int', false, true, false),
            new Parameter('anything', 'mixed', true, true, false),
        ], $operation->parameters);

        self::assertSame('yorvoid.principal.activate', $catalogue->get('yorvoid.principal.suspend')->inverse);
        self::assertSame([], $catalogue->get('yorvoid.log.verify')->parameters);
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
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function invalidAttributes(): iterable
    {
        yield 'two segments' => ['yorvoid.end', null, 'End'];
        yield 'uppercase' => ['yorvoid.Session.end', null, 'End'];
        yield 'trailing dot' => ['yorvoid.session.end.', null, 'End'];
        yield 'starts with a digit' => ['yorvoid.2fa.enable', null, 'Enable'];
        yield 'invalid inverse' => ['yorvoid.session.end', 'start', 'End'];
        yield 'empty description' => ['yorvoid.session.end', null, ''];
        yield 'blank description' => ['yorvoid.session.end', null, '   '];
        yield 'trailing newline' => ["yorvoid.session.end\n", null, 'End'];
    }

    #[DataProvider('invalidAttributes')]
    public function testRefusesInvalidAttributes(string $name, ?string $inverse, string $description): void
    {
        $this->expectException(IntegratorError::class);

        new Operation($name, $description, inverse: $inverse);
    }

    public function testAcceptsValidNames(): void
    {
        self::assertSame('acme_corp.badge.issue', new Operation('acme_corp.badge.issue', 'Issue a badge')->name);
        self::assertSame('yorvoid.session.end_all', new Operation('yorvoid.session.end_all', 'End all', inverse: 'yorvoid.session.x.y')->name);
    }
}
