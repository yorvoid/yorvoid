<?php

declare(strict_types=1);

namespace YorvoID\Tests\PolicyTesting;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\YorvoIDDeclarations;
use YorvoID\PolicyTesting\Console\PolicyMutateCommand;
use YorvoID\PolicyTesting\Mutation\Mutant;
use YorvoID\PolicyTesting\Mutation\Mutants;
use YorvoID\PolicyTesting\Mutation\MutationOperator;
use YorvoID\PolicyTesting\Mutation\MutationReport;
use YorvoID\PolicyTesting\Mutation\PolicyMutation;
use YorvoID\PolicyTesting\PolicyCase;
use YorvoID\PolicyTesting\PolicyCases;
use YorvoID\PolicyTesting\PolicyResult;
use YorvoID\PolicyTesting\PolicyServices;
use YorvoID\PolicyTesting\PolicySuite;
use YorvoID\PolicyTesting\PolicyUnderTest;
use YorvoID\PolicyTesting\Symfony\PolicyKernel;
use YorvoID\PolicyTesting\Symfony\YorvoIDPolicyTestingBundle;
use YorvoID\Testing\InMemoryStorage;
use YorvoID\Tests\Policy\Fixtures\PolicyWorld;
use YorvoID\Tests\PolicyTesting\Fixtures\AcmeCases;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * Policy mutation testing (AUZ-045): the sample's cases catch every mutant of its policy (M2-5's
 * acceptance), every operator makes one, and a case taken away lets its mutant through.
 */
final class MutationTest extends TestCase
{
    private string $directory;

    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/mutation-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }

        new Filesystem()->remove($this->directory);
    }

    public function testTheSamplesCasesCatchEveryMutant(): void
    {
        $report = $this->sample();

        self::assertSame([], $report->failing);
        self::assertSame([], array_map(static fn (Mutant $mutant): string => $mutant->id(), $report->survived));
        self::assertSame(100.0, $report->score());
        // Every mutant, in order, with the first case that catches it.
        self::assertSame([
            'narrow:acme.pages.view:everyone' => 'Anyone views pages',
            'widen:acme.pages.view_drafts:everyone' => 'Other drafts are editors\'',
            'widen:acme.pages.view_drafts:member' => 'Other drafts are editors\'',
            'narrow:acme.pages.view_drafts:editor' => 'An editor views drafts',
            'widen:acme.pages.view_drafts:manager' => 'A site manager doesn\'t view drafts',
            'widen:acme.pages.publish:everyone' => 'Someone not signed in publishes nothing',
            'widen:acme.pages.publish:member' => 'A member doesn\'t publish',
            'narrow:acme.pages.publish:editor' => 'An editor publishes',
            'widen:acme.pages.publish:manager' => 'A site manager doesn\'t publish',
            'widen:acme.pages.delete:everyone' => 'Someone not signed in deletes nothing',
            'widen:acme.pages.delete:member' => 'A member doesn\'t delete',
            'narrow:acme.pages.delete:editor' => 'An editor deletes',
            'widen:acme.pages.delete:manager' => 'A site manager doesn\'t delete',
            'widen:acme.pages.purge:everyone' => 'A super role doesn\'t purge: it\'s strict',
            'widen:acme.pages.purge:member' => 'A member doesn\'t purge',
            'widen:acme.pages.purge:editor' => 'An editor doesn\'t purge',
            'widen:acme.pages.purge:manager' => 'A site manager doesn\'t purge',
            'strict_off:acme.pages.purge:admin' => 'A super role doesn\'t purge: it\'s strict',
            'widen:acme.sites.update:everyone' => 'An editor doesn\'t update a site',
            'widen:acme.sites.update:member' => 'A member doesn\'t update a site',
            'widen:acme.sites.update:editor' => 'An editor doesn\'t update a site',
            'narrow:acme.sites.update:manager' => 'A site manager updates a site',
            'super:member' => 'A member doesn\'t publish',
            'super:editor' => 'An editor doesn\'t update a site',
            'super:manager' => 'A site manager doesn\'t view drafts',
            'scope_widens' => 'An editor of a page doesn\'t publish another',
            'groups_dont_count' => 'A group\'s editors publish',
            'parents_dont_count' => 'A viewer of a site views the drafts of its pages',
            'drop_implication:server#viewer:admin' => 'A server\'s admin views the drafts of its pages',
            'drop_implication:page#viewer:editor' => 'An editor of a page views its drafts',
            'drop_forbid:acme.help_drafts:forbid' => 'Archived help drafts are no one\'s',
            'forbid_everything:acme.help_drafts:forbid' => 'Help drafts are anyone\'s to view',
            'can_tell:acme.help_drafts:forbid' => 'A lost help draft isn\'t shown',
            'permit_always:acme.help_drafts:permit' => 'Other drafts are editors\'',
            'permit_never:acme.help_drafts:permit' => 'Help drafts are anyone\'s to view',
            'drop_forbid:acme.locked_pages' => 'Nobody publishes a locked page',
            'forbid_everything:acme.locked_pages' => 'Anyone views pages',
            'can_tell:acme.locked_pages' => 'A page whose lock can\'t be read isn\'t published',
            'authority_off' => 'A suspended editor publishes nothing',
        ], $report->killed);
    }

    /**
     * The sample is thick enough: every operator has something to change in it.
     */
    public function testEveryOperatorMakesAMutantOfTheSample(): void
    {
        $operators = array_unique(array_map(static fn (string $id): string => explode(':', $id)[0], array_keys($this->sample()->killed)));
        sort($operators);
        $all = array_map(static fn (MutationOperator $operator): string => $operator->value, MutationOperator::cases());
        sort($all);

        self::assertSame($all, $operators);
    }

    /**
     * The tool notices a gap: without the case that pins a decision, the mutant that changes it lives.
     */
    public function testACaseTakenAwayLetsItsMutantSurvive(): void
    {
        foreach ([
            'An editor of a page doesn\'t publish another' => 'scope_widens',
            'A site manager doesn\'t publish' => 'widen:acme.pages.publish:manager',
            'A group\'s editors publish' => 'groups_dont_count',
            // The server's admin reaches the page through its parents too: without both, both live.
            'A viewer of a site views the drafts of its pages|A server\'s admin views the drafts of its pages' => 'parents_dont_count|drop_implication:server#viewer:admin',
            'A server\'s admin views the drafts of its pages' => 'drop_implication:server#viewer:admin',
            'An editor of a page views its drafts' => 'drop_implication:page#viewer:editor',
        ] as $case => $mutant) {
            $report = $this->sample(except: explode('|', $case));
            self::assertSame(explode('|', $mutant), array_map(static fn (Mutant $one): string => $one->id(), $report->survived), $case);
            self::assertLessThan(100.0, $report->score());
        }
    }

    /**
     * A mutant no case reaches is still made, and says so when it survives: the gap it shows is the
     * case that's missing.
     */
    public function testSaysWhatNoCaseReaches(): void
    {
        $without = ['Archived help drafts are no one\'s', 'A lost help draft isn\'t shown'];
        $report = $this->sample(except: [...$without, 'A site manager doesn\'t publish']);

        self::assertSame(['widen:acme.pages.publish:manager', 'drop_forbid:acme.help_drafts:forbid', 'can_tell:acme.help_drafts:forbid'], array_map(static fn (Mutant $one): string => $one->id(), $report->survived));
        self::assertSame(['drop_forbid:acme.help_drafts:forbid', 'can_tell:acme.help_drafts:forbid'], $report->unexercised, 'As a list.');
        self::assertTrue($report->exercised($report->survived[0]));
        self::assertFalse($report->exercised($report->survived[1]));
        self::assertTrue($this->sample(except: 'A site manager doesn\'t publish')->exercised(new Mutant(MutationOperator::Widen, 'acme.pages.publish', 'manager')), 'Reached, not caught.');

        $kernel = $this->kernel();
        $tester = new CommandTester(new PolicyMutateCommand(static fn (): PolicySuite => new PolicySuite(PolicyKernel::underTest($kernel), self::casesOf(array_values(array_filter(iterator_to_array(new AcmeCases()->cases(), false), static fn (PolicyCase $case): bool => !\in_array($case->description, $without, true)))))));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('✘ drop_forbid:acme.help_drafts:forbid: The forbid "acme.help_drafts:forbid" never holds. Not exercised: no case reaches it. Add one, or --ignore it if it\'s equivalent.', $tester->getDisplay());
        $tester->execute(['--format' => 'json']);
        self::assertStringContainsString('"exercised": false', $tester->getDisplay());
    }

    public function testIgnoresOnlyMutantsThePolicyHas(): void
    {
        $report = $this->sample(except: 'A site manager doesn\'t publish', ignore: ['widen:acme.pages.publish:manager']);

        self::assertSame([], $report->survived);
        self::assertSame(['widen:acme.pages.publish:manager'], array_map(static fn (Mutant $one): string => $one->id(), $report->ignored));
        self::assertSame(100.0, $report->score(), 'Left out of the score.');
        self::assertCount(38, $report->killed);

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The ignored mutant "widen:acme.pages.publish:nobody" isn\'t one of the policy\'s.');
        $this->sample(ignore: ['widen:acme.pages.publish:nobody']);
    }

    public function testMutatesNothingWhileACaseFails(): void
    {
        $passing = PolicyCase::that('an editor publishes')->given(roles: ['editor'])->asks('acme.pages.publish')->permits();
        $failing = PolicyCase::that('a member publishes')->given(roles: ['member'])->asks('acme.pages.publish')->permits();
        $report = new PolicyMutation()->run($this->policy(), [$passing, $failing]);

        self::assertSame(['a member publishes'], array_map(static fn (PolicyResult $result): string => $result->case->description, $report->failing), 'As a list.');
        self::assertSame([[], [], []], [$report->killed, $report->survived, $report->ignored]);
    }

    public function testDecidesWithNoMutantOnButTheOneTried(): void
    {
        $policy = $this->policy();
        $mutants = $policy->mutants;
        \assert(null !== $mutants);
        $mutants->activate(new Mutant(MutationOperator::Widen, 'acme.pages.publish', 'member'));
        $report = new PolicyMutation()->run($policy, [PolicyCase::that('A member doesn\'t publish')->given(roles: ['member'])->asks('acme.pages.publish')->denies()]);

        self::assertSame([], $report->failing, 'The cases run on the policy itself first.');
        self::assertSame('A member doesn\'t publish', $report->killed['widen:acme.pages.publish:member'] ?? null);
        self::assertNull($mutants->active(), 'None on afterwards.');
    }

    public function testNeedsAPolicyBuiltThroughTheMutants(): void
    {
        $world = new PolicyWorld();
        $case = PolicyCase::that('anyone views')->anonymous()->asks('acme.pages.view')->permits();

        try {
            new PolicyMutation()->run(new PolicyUnderTest(static fn (): PolicyServices => new PolicyServices($world->operations, $world->policy, $world->realm)), [$case]);
            self::fail('Mutated without the mutants.');
        } catch (IntegratorError $error) {
            self::assertStringStartsWith('The policy under test wasn\'t built with mutants.', $error->getMessage());
        }

        foreach ([
            'catalogue' => static fn (InMemoryStorage $stores, Mutants $mutants): PolicyServices => self::services(new PolicyWorld(memory: $stores)),
            'authority' => static function (InMemoryStorage $stores, Mutants $mutants): PolicyServices {
                $mutants->catalogue(PolicyCatalogue::of([new YorvoIDDeclarations()]));
                $mutants->elements([]);

                return self::services(new PolicyWorld(memory: $stores));
            },
        ] as $seam => $build) {
            try {
                new PolicyMutation()->run(PolicyUnderTest::of($build), [$case]);
                self::fail($seam);
            } catch (IntegratorError $error) {
                self::assertStringStartsWith(\sprintf('The policy under test wasn\'t built through the mutants\' %s.', $seam), $error->getMessage());
            }
        }
    }

    public function testWithoutSymfonyTheFunctionBuildsThroughTheMutants(): void
    {
        $report = new PolicyMutation()->run($this->policy(), [
            PolicyCase::that('An editor publishes')->given(roles: ['editor'])->asks('acme.pages.publish')->permits(),
            PolicyCase::that('A member doesn\'t publish')->given(roles: ['member'])->asks('acme.pages.publish')->denies(),
        ]);

        self::assertSame('An editor publishes', $report->killed['narrow:acme.pages.publish:editor'] ?? null);
        self::assertSame('A member doesn\'t publish', $report->killed['widen:acme.pages.publish:member'] ?? null);
        self::assertContains('widen:acme.pages.delete:member', array_map(static fn (Mutant $one): string => $one->id(), $report->survived), 'Nothing asks about deleting.');
    }

    public function testNamesWhatEachMutantChanges(): void
    {
        foreach ([
            'drop_forbid:acme.locked' => ['The forbid "acme.locked" never holds.', new Mutant(MutationOperator::DropForbid, 'acme.locked')],
            'forbid_everything:acme.locked' => ['The forbid "acme.locked" holds whenever it matches.', new Mutant(MutationOperator::ForbidEverything, 'acme.locked')],
            'permit_always:acme.rule:permit' => ['The permit "acme.rule:permit" holds whenever it matches.', new Mutant(MutationOperator::PermitAlways, 'acme.rule:permit')],
            'permit_never:acme.rule:permit' => ['The permit "acme.rule:permit" never holds.', new Mutant(MutationOperator::PermitNever, 'acme.rule:permit')],
            'can_tell:acme.locked' => ['"acme.locked" fails where it couldn\'t tell.', new Mutant(MutationOperator::CanTell, 'acme.locked')],
            'widen:acme.pages.publish:member' => ['The role "member" has "acme.pages.publish".', new Mutant(MutationOperator::Widen, 'acme.pages.publish', 'member')],
            'narrow:acme.pages.publish:editor' => ['The role "editor" doesn\'t have "acme.pages.publish".', new Mutant(MutationOperator::Narrow, 'acme.pages.publish', 'editor')],
            'strict_off:acme.pages.purge:admin' => ['The super role "admin" has the strict "acme.pages.purge".', new Mutant(MutationOperator::StrictOff, 'acme.pages.purge', 'admin')],
            'super:member' => ['The role "member" is a super role.', new Mutant(MutationOperator::Super, role: 'member')],
            'scope_widens' => ['A role on a resource counts in the whole realm.', new Mutant(MutationOperator::ScopeWidens)],
            'groups_dont_count' => ['Roles and relations through a group don\'t reach its members.', new Mutant(MutationOperator::GroupsDontCount)],
            'parents_dont_count' => ['Roles and relations on a parent don\'t reach its children.', new Mutant(MutationOperator::ParentsDontCount)],
            'authority_off' => ['An actor that isn\'t active passes the authority check.', new Mutant(MutationOperator::AuthorityOff)],
        ] as $id => [$description, $mutant]) {
            self::assertSame([$id, $description], [$mutant->id(), $mutant->description()]);
        }

        self::assertSame(100.0, new MutationReport([], [], [], [])->score(), 'Nothing tried, nothing missed.');
    }

    public function testTheCommandSaysWhatSurvivesAndFailsBelowTheScore(): void
    {
        $kernel = $this->kernel();
        $cases = array_values(array_filter(iterator_to_array(new AcmeCases()->cases(), false), static fn (PolicyCase $case): bool => 'A site manager doesn\'t publish' !== $case->description));
        $tester = new CommandTester(new PolicyMutateCommand(static fn (): PolicySuite => new PolicySuite(PolicyKernel::underTest($kernel), self::casesOf($cases))));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('✘ widen:acme.pages.publish:manager: The role "manager" has "acme.pages.publish". No case catches it.', $tester->getDisplay());
        self::assertStringContainsString('39 mutants: 38 caught, 1 survived, 0 ignored. Score 97.44% (at least 100%).', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--min-score' => '97.43']));
        self::assertSame(1, $tester->execute(['--min-score' => '97.44']));
        self::assertSame(0, $tester->execute(['--min-score' => '0']));
        self::assertSame(0, $tester->execute(['--ignore' => ['widen:acme.pages.publish:manager', 'authority_off']]));
        self::assertStringContainsString('39 mutants: 37 caught, 0 survived, 2 ignored.', $tester->getDisplay());

        self::assertSame(1, $tester->execute(['--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(97.44, $report['score'] ?? null);
        self::assertSame([['id' => 'widen:acme.pages.publish:manager', 'description' => 'The role "manager" has "acme.pages.publish".', 'exercised' => true]], $report['survived'] ?? null);
        self::assertSame(0, $tester->execute(['--format' => 'json', '--ignore' => ['widen:acme.pages.publish:manager']]));
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame([['id' => 'widen:acme.pages.publish:manager', 'description' => 'The role "manager" has "acme.pages.publish".', 'exercised' => true]], $report['ignored'] ?? null);
        self::assertStringContainsString('"description": "The role \\"manager\\" has \\"acme.pages.publish\\"."', $tester->getDisplay(), 'Pretty, slashes as they are.');

        self::assertSame(2, $tester->execute(['--format' => 'xml']));
        self::assertStringContainsString('The format "xml" is neither text nor json.', $tester->getDisplay());
        self::assertSame(2, $tester->execute(['--min-score' => '100.5']));
        self::assertStringContainsString('The minimum score is a number from 0 to 100.', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--min-score' => '97']), 'Whole numbers too.');

        $two = array_values(array_filter($cases, static fn (PolicyCase $case): bool => 'A site manager doesn\'t delete' !== $case->description));
        $rounded = new CommandTester(new PolicyMutateCommand(static fn (): PolicySuite => new PolicySuite(PolicyKernel::underTest($kernel), self::casesOf($two))));
        $rounded->execute([]);
        self::assertStringContainsString('Score 94.87% (at least 100%).', $rounded->getDisplay(), 'To two places.');
        $rounded->execute(['--format' => 'json']);
        self::assertStringContainsString('"score": 94.87,', $rounded->getDisplay());
    }

    public function testTheCommandMutatesNothingWhileACaseFails(): void
    {
        $kernel = $this->kernel();
        $tester = new CommandTester(new PolicyMutateCommand(static fn (): PolicySuite => new PolicySuite(PolicyKernel::underTest($kernel), new readonly class implements PolicyCases {
            public function cases(): iterable
            {
                yield PolicyCase::that('fails/é')->given(roles: ['member'])->asks('acme.pages.publish')->permits();
            }
        })));

        self::assertSame(1, $tester->execute(['--min-score' => '0']));
        self::assertStringContainsString('The cases must pass on the policy itself first:', $tester->getDisplay());
        self::assertStringContainsString('✘ Expected permit, got deny', $tester->getDisplay());
        self::assertStringNotContainsString('mutants:', $tester->getDisplay(), 'Nothing was mutated.');
        self::assertSame(1, $tester->execute(['--min-score' => '0', '--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['failing'] ?? null);
        self::assertIsString($report['failing'][0] ?? null);
        self::assertStringStartsWith('Expected permit, got deny', $report['failing'][0]);
        self::assertStringContainsString('(fails/é)"', $tester->getDisplay());
    }

    public function testTheCommandReportsWhatSurvives(): void
    {
        $tester = new CommandTester(new Application($this->kernel())->find('yorvoid:policy:mutate'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('39 mutants: 39 caught, 0 survived, 0 ignored. Score 100% (at least 100%).', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--ignore' => ['authority_off']]));
        self::assertStringContainsString('– authority_off: ignored as equivalent.', $tester->getDisplay());
        self::assertStringContainsString('39 mutants: 38 caught, 0 survived, 1 ignored.', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--format' => 'json']));
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame([100, 100, [], []], [$report['score'] ?? null, $report['min_score'] ?? null, $report['survived'] ?? null, $report['failing'] ?? null]);
        self::assertIsArray($report['killed'] ?? null);
        self::assertSame('Nobody publishes a locked page', $report['killed']['drop_forbid:acme.locked_pages'] ?? null);

        foreach ([['--format' => 'xml'], ['--min-score' => '101'], ['--min-score' => 'all'], ['--min-score' => '-1']] as $invalid) {
            self::assertSame(2, $tester->execute($invalid), (string) json_encode($invalid));
        }
    }

    /**
     * @param string|list<string>|null $except The cases left out
     * @param list<string>             $ignore
     */
    private function sample(string|array|null $except = null, array $ignore = []): MutationReport
    {
        $cases = array_filter(iterator_to_array(new AcmeCases()->cases(), false), static fn (PolicyCase $case): bool => !\in_array($case->description, (array) $except, true));

        return new PolicyMutation()->run(PolicyKernel::underTest($this->kernel()), $cases, $ignore);
    }

    /**
     * @param list<PolicyCase> $cases
     */
    private static function casesOf(array $cases): PolicyCases
    {
        return new readonly class($cases) implements PolicyCases {
            /**
             * @param list<PolicyCase> $cases
             */
            public function __construct(
                private array $cases,
            ) {
            }

            public function cases(): iterable
            {
                return $this->cases;
            }
        };
    }

    private function kernel(): TestKernel
    {
        $kernel = new TestKernel($this->directory, environment: YorvoIDPolicyTestingBundle::ENVIRONMENT);
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }

    private function policy(): PolicyUnderTest
    {
        return PolicyUnderTest::of(static fn (InMemoryStorage $stores, Mutants $mutants): PolicyServices => self::services(new PolicyWorld(memory: $stores, mutants: $mutants)));
    }

    private static function services(PolicyWorld $world): PolicyServices
    {
        return new PolicyServices($world->operations, $world->policy, $world->realm);
    }
}
