<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\TraceableAccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Voter\ExpressionVoter;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Symfony\Doctor\VotersCheck;
use YorvoID\Symfony\Migrations;
use YorvoID\Symfony\Security\AbstainOnYorvoIDAbilities;
use YorvoID\Symfony\Security\PolicyVoter;
use YorvoID\Tests\Symfony\App\DeniesPageOne;
use YorvoID\Tests\Symfony\App\GrantsAcme;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * No other voter can grant one of YorvoID's declared abilities, whatever the access decision strategy
 * (M-7); their refusals count where the strategy weighs them, and they decide everything else as before.
 */
final class OtherVotersTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/voters-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testAVoterThatGrantsEverythingDoesntOpenWhatYorvoIDRefuses(): void
    {
        foreach (['affirmative', 'consensus'] as $strategy) {
            $checker = $this->checker(['access_decision_manager' => ['strategy' => $strategy]], services: [GrantsAcme::class]);

            self::assertFalse($checker->isGranted('acme.pages.view', new ResourceRef('page', 'locked')), $strategy.': the forbid stands.');
            self::assertFalse($checker->isGranted('acme.pages.publish'), $strategy.': no role of theirs has it.');
            self::assertTrue($checker->isGranted('acme.pages.view', new ResourceRef('page', 'open')), $strategy.': YorvoID permits it.');
            self::assertTrue($checker->isGranted('acme_custom'), $strategy.': its own attribute, not a declared ability, is its to grant.');
            $this->kernel?->shutdown();
            $this->directory .= '-'.$strategy;
        }
    }

    public function testAnApplicationsRestrictionStillRefuses(): void
    {
        $checker = $this->checker(['access_decision_manager' => ['strategy' => 'unanimous']], services: [DeniesPageOne::class]);

        self::assertFalse($checker->isGranted('acme.pages.view', new ResourceRef('page', '1')), 'Its refusal counts, though YorvoID permits it.');
        self::assertTrue($checker->isGranted('acme.pages.view', new ResourceRef('page', '2')));
    }

    public function testAVoterAskedFirstDoesntDecideADeclaredAbility(): void
    {
        $checker = $this->checker(['access_decision_manager' => ['strategy' => 'priority']], voters: [GrantsAcme::class]);

        self::assertFalse($checker->isGranted('acme.pages.view', new ResourceRef('page', 'locked')), 'Its grant abstains, and YorvoID decides.');
        self::assertTrue($checker->isGranted('acme_custom'), 'It decides its own first.');
    }

    public function testAccessControlStillCountsAnotherRolesGrant(): void
    {
        $this->checker(services: [GrantsAcme::class]);
        // In debug, as access_control asks it.
        $manager = $this->container()->get('debug.security.access.decision_manager');
        \assert($manager instanceof TraceableAccessDecisionManager);
        $request = Request::create('/pages');
        $admin = new UsernamePasswordToken(new InMemoryUser('ada', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']);

        // As access_control asks: every role of the rule at once, the Request as the subject.
        self::assertTrue($manager->decide($admin, ['acme.pages.publish', 'ROLE_ADMIN'], $request, null, true), 'Symfony\'s role voter, wrapped too, still grants ROLE_ADMIN.');
        self::assertFalse($manager->decide(new NullToken(), ['acme.pages.publish', 'ROLE_ADMIN'], $request, null, true), 'Without it, GrantsAcme\'s grant of the declared ability doesn\'t count.');
        self::assertTrue($manager->decide(new NullToken(), ['acme.pages.view'], $request, null, true), 'The Request is no resource: YorvoID decides realm-wide.');
    }

    public function testExpressionsAndClosuresGoThroughAndAskYorvoIDInTurn(): void
    {
        $checker = $this->checker(services: [GrantsAcme::class], expressions: true);

        self::assertFalse($checker->isGranted(new Expression('is_granted("acme.pages.view", subject)'), new ResourceRef('page', 'locked')), 'YorvoID decides what the expression asks.');
        self::assertTrue($checker->isGranted(new Expression('is_granted("acme.pages.view", subject)'), new ResourceRef('page', 'open')));
        self::assertTrue($checker->isGranted(new Expression('is_granted("acme_custom")')));
        self::assertContains(ExpressionVoter::class, $this->wrapped(), 'Wrapped, and its attribute passes through.');
        self::assertTrue($checker->isGranted(static fn (): bool => true), 'A closure is the application\'s own.');
        self::assertFalse($checker->isGranted(static fn (): bool => false));
    }

    public function testEveryOtherVoterIsWrappedWhoeverTaggedIt(): void
    {
        foreach (['autoconfigured' => [[GrantsAcme::class], []], 'tagged by another bundle\'s pass' => [[], [GrantsAcme::class]]] as $how => [$services, $voters]) {
            $checker = $this->checker(services: $services, voters: $voters);
            $container = $this->container();
            $wrapped = $this->wrapped();

            self::assertContains(GrantsAcme::class, $wrapped, $how.'.');
            self::assertContains(RoleVoter::class, $wrapped, 'Symfony\'s own are wrapped too.');
            self::assertNotContains(PolicyVoter::class, $wrapped, 'YorvoID\'s own isn\'t.');

            self::assertTrue($checker->isGranted('acme_custom'));
            $manager = $container->get('debug.security.access.decision_manager');
            \assert($manager instanceof TraceableAccessDecisionManager);
            $log = $manager->getDecisionLog();
            $voted = [];

            $last = end($log);
            \assert(\is_array($last) && \is_array($last['voterDetails'] ?? null));

            foreach ($last['voterDetails'] as $detail) {
                \assert(\is_array($detail) && ($detail['voter'] ?? null) instanceof VoterInterface);
                $voter = $detail['voter'];
                $voted[] = $voter instanceof AbstainOnYorvoIDAbilities ? 'wrapped '.$voter->voter::class : $voter::class;
            }
            self::assertContains('wrapped '.GrantsAcme::class, $voted, $how.': the profiler shows the wrapper, and the voter in it.');

            $check = $container->get(VotersCheck::class);
            \assert($check instanceof VotersCheck);
            self::assertStringStartsWith('other voters can\'t grant a declared ability; their refusals count where the strategy weighs them (', $check->summary());
            self::assertStringContainsString(GrantsAcme::class, $check->summary(), 'The doctor names each wrapped voter by its own class.');
            $this->kernel?->shutdown();
            $this->directory .= '-next';
        }
    }

    /**
     * @param array<string, mixed> $security
     * @param list<class-string>   $services
     * @param list<class-string>   $voters
     */
    private function checker(array $security = [], array $services = [], array $voters = [], bool $expressions = false): AuthorizationCheckerInterface
    {
        $kernel = new TestKernel($this->directory, security: $security, services: $services, voters: $voters, expressions: $expressions);
        $kernel->boot();
        $this->kernel = $kernel;
        $container = $this->container();
        $database = $container->get(Database::class);
        \assert($database instanceof Database);
        new Migrator($database, Migrations::all())->migrate();
        $checker = $container->get('security.authorization_checker');
        \assert($checker instanceof AuthorizationCheckerInterface);

        return $checker;
    }

    /**
     * @return list<string>
     */
    private function wrapped(): array
    {
        $wrapped = $this->kernel?->getContainer()->getParameter('yorvoid.voters.wrapped');
        \assert(\is_array($wrapped));

        return array_values(array_map(strval(...), array_filter($wrapped, \is_string(...))));
    }

    private function container(): ContainerInterface
    {
        $container = $this->kernel?->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }
}
