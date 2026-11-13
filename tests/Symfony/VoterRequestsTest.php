<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use YorvoID\Core\Authorization\DecisionLog\DecisionRecord;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Core\Storage\Row;
use YorvoID\Symfony\Migrations;
use YorvoID\Symfony\Security\RequestOrigins;
use YorvoID\Symfony\Security\SubjectOf;
use YorvoID\Tests\Support\SubjectShapes;
use YorvoID\Tests\Symfony\App\FailingInserts;
use YorvoID\Tests\Symfony\App\HelpDraft;
use YorvoID\Tests\Symfony\App\LockedPage;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * isGranted() for a declared ability within a request: it takes only a subject that says which
 * resource it's about (H-5), every decision of the request shares its correlation ID (L-7), and the
 * vote says only the verdict and the decision's ID, not the policy's reason (L-9).
 */
final class VoterRequestsTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/voter-requests-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        FailingInserts::$tables = [];
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    #[DataProviderExternal(SubjectShapes::class, 'refused')]
    public function testRefusesWhatDoesntSayWhichResourceItsAbout(mixed $wrong): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage(\sprintf('isGranted("acme.pages.view") was given %s.', get_debug_type($wrong)));
        SubjectOf::isGranted($wrong, 'acme.pages.view');
    }

    public function testTakesWhatSaysWhichResourceItsAboutOrThatItsAboutNone(): void
    {
        $page = new ResourceRef('page', 'locked');
        $draft = new HelpDraft();
        $locked = new LockedPage();

        self::assertSame([null, null], SubjectOf::isGranted(null, 'acme.pages.view'));
        self::assertSame([null, null], SubjectOf::isGranted(new Request(), 'acme.pages.view'), 'What access_control asks with: the whole realm, and the Request isn\'t for rules.');
        self::assertSame([$page, null], SubjectOf::isGranted($page, 'acme.pages.view'));
        self::assertSame([null, $locked], SubjectOf::isGranted($locked, 'acme.pages.view'), 'The policy reads the resource it concerns.');
        self::assertSame([null, $draft], SubjectOf::isGranted($draft, 'acme.pages.view'));
        self::assertSame([$page, $draft], SubjectOf::isGranted([$page, $draft], 'acme.pages.view'));
    }

    public function testAResourcesForbidAppliesHoweverItsSubjectIsGiven(): void
    {
        $checker = $this->checker();

        self::assertFalse($checker->isGranted('acme.pages.view', new ResourceRef('page', 'locked')));
        self::assertFalse($checker->isGranted('acme.pages.view', new LockedPage()));
        self::assertFalse($checker->isGranted('acme.pages.view', [new ResourceRef('page', 'locked'), new \stdClass()]), 'A resource with its subject.');
        self::assertTrue($checker->isGranted('acme.pages.view', [new ResourceRef('page', 'open'), new \stdClass()]));
        self::assertTrue($checker->isGranted('acme.pages.view', new Request()), 'Explicitly given the Request: the whole realm too.');

        foreach (['locked', ['page', 'locked'], new \stdClass(), SubjectShapes::both(), [new ResourceRef('page', 'locked'), new HelpDraft()]] as $wrong) {
            try {
                $checker->isGranted('acme.pages.view', $wrong);
                self::fail('Decided on '.get_debug_type($wrong).'.');
            } catch (IntegratorError $error) {
                self::assertMatchesRegularExpression('/^(isGranted\("acme\.pages\.view"\) was given|The subject of "acme\.pages\.view" concerns no resource)/', $error->getMessage());
            }
        }
    }

    public function testARequestsDecisionsShareOneCorrelationId(): void
    {
        $checker = $this->checker();
        $requests = $this->container()->get('request_stack');
        \assert($requests instanceof RequestStack);
        $origins = $this->container()->get(RequestOrigins::class);
        \assert($origins instanceof RequestOrigins);

        self::assertNotSame($origins->of()->correlationId, $origins->of()->correlationId, 'Outside a request, each has its own.');

        foreach (['/one', '/two'] as $path) {
            $request = Request::create($path);
            $requests->push($request);
            $requests->push(Request::create('/fragment'));
            self::assertSame($origins->of()->correlationId, $origins->of('a route')->correlationId, 'A sub-request has its main request\'s.');

            for ($i = 0; $i < 5; ++$i) {
                self::assertFalse($checker->isGranted('acme.pages.publish'));
            }

            $requests->pop();
            $this->terminate($request);
            $requests->pop();
        }

        $origins = array_map(static fn (DecisionRecord $record): string => $record->origin[1], $this->recorded());
        self::assertCount(10, $origins);
        self::assertCount(2, array_unique($origins), 'One per request.');
        self::assertSame(array_fill(0, 5, $origins[0]), \array_slice($origins, 0, 5));
    }

    public function testASampledRequestIsRecordedWholeOrNotAtAll(): void
    {
        $checker = $this->checker(['decision_log' => ['permits' => 'sampled', 'sample_rate' => 0.5]]);
        $requests = $this->container()->get('request_stack');
        \assert($requests instanceof RequestStack);

        for ($i = 0; $i < 8; ++$i) {
            $request = Request::create('/'.$i);
            $requests->push($request);

            for ($j = 0; $j < 6; ++$j) {
                self::assertTrue($checker->isGranted('acme.pages.view'));
            }

            $this->terminate($request);
            $requests->pop();
        }

        $counts = array_count_values(array_map(static fn (DecisionRecord $record): string => $record->origin[1], $this->recorded()));
        self::assertSame([], array_filter($counts, static fn (int $count): bool => 6 !== $count), 'Every request recorded has all its six permits.');
    }

    public function testTheVoteSaysTheVerdictAndTheRecordNotTheReason(): void
    {
        $checker = $this->checker(['audit' => ['on_failure' => 'refuse'], 'decision_log' => ['permits' => 'sampled', 'sample_rate' => 0.0]]);

        $denied = new AccessDecision();
        self::assertFalse($checker->isGranted('acme.pages.view', new ResourceRef('page', 'locked'), $denied));
        self::assertMatchesRegularExpression('/YorvoID: Deny \(decision [0-9a-f-]{36}\)\./', $denied->getMessage());
        self::assertStringNotContainsString('locked', $denied->getMessage(), 'Which forbid refused stays in the log.');
        self::assertStringNotContainsString('forbid', $denied->getMessage());

        $permitted = new AccessDecision();
        self::assertTrue($checker->isGranted('acme.pages.view', null, $permitted));
        self::assertSame(['YorvoID: Permit.'], self::reasons($permitted), 'Sampled out: no record to point to.');

        FailingInserts::$tables = [DbalDecisionLogStore::TABLE];
        $unrecorded = new AccessDecision();
        self::assertFalse($checker->isGranted('acme.pages.view', new ResourceRef('page', 'locked'), $unrecorded));
        self::assertSame(['YorvoID: Deny.'], self::reasons($unrecorded), 'Its record couldn\'t be written: nothing to point to.');

        FailingInserts::$tables = [];
        $this->kernel?->shutdown();
        $this->directory .= '-all';
        $checker = $this->checker(['audit' => ['on_failure' => 'refuse'], 'decision_log' => ['permits' => 'all']]);
        FailingInserts::$tables = [DbalDecisionLogStore::TABLE];
        $unavailable = new AccessDecision();
        self::assertFalse($checker->isGranted('acme.pages.view', null, $unavailable));
        self::assertSame(['YorvoID: Indeterminate.'], self::reasons($unavailable), 'A permit whose record couldn\'t be written is refused: AuditUnavailable.');
    }

    /**
     * @return list<string>
     */
    private static function reasons(AccessDecision $decision): array
    {
        return array_merge(...array_map(static fn (Vote $vote): array => $vote->reasons, $decision->votes));
    }

    /**
     * @param array<string, mixed> $yorvoid
     */
    private function checker(array $yorvoid = []): AuthorizationCheckerInterface
    {
        $this->kernel = new TestKernel($this->directory, $yorvoid);
        $this->kernel->boot();
        $database = $this->container()->get(Database::class);
        \assert($database instanceof Database);
        new Migrator($database, Migrations::all())->migrate();
        $checker = $this->container()->get('security.authorization_checker');
        \assert($checker instanceof AuthorizationCheckerInterface);

        return $checker;
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel?->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    private function terminate(Request $request): void
    {
        $dispatcher = $this->container()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->dispatch(new TerminateEvent($this->kernel ?? throw new \LogicException('Not booted.'), $request, new Response()), KernelEvents::TERMINATE);
    }

    /**
     * @return list<DecisionRecord>
     */
    private function recorded(): array
    {
        $database = $this->container()->get(Database::class);
        \assert($database instanceof Database);

        return array_map(static fn (array $row): DecisionRecord => DecisionRecord::fromJson(Row::string($row, 'content')), $database->connection->fetchAllAssociative(\sprintf('SELECT content FROM %s ORDER BY decided_at, id', $database->table(DbalDecisionLogStore::TABLE))));
    }
}
