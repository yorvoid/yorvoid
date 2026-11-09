<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Symfony;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use YorvoID\Authn\Credential\CredentialStore;
use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Authn\Session\KnownDeviceStore;
use YorvoID\Authn\Session\SessionStore;
use YorvoID\Authn\Setup\SetupStore;
use YorvoID\Authn\StepUp\CoolDownStore;
use YorvoID\Authn\Token\OneTimeTokenStore;
use YorvoID\Core\Authorization\AuthorityCheck;
use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Log\SecurityLogStore;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Principal\IdentifierStore;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Passkeys\CeremonyStore;
use YorvoID\Passkeys\PasskeyStore;
use YorvoID\Policy\Attribute\AttributeStore;
use YorvoID\Policy\Policy;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyStore;
use YorvoID\PolicyTesting\Mutation\Mutants;
use YorvoID\Relations\RelationSchema;
use YorvoID\Relations\RelationStore;
use YorvoID\Testing\InMemoryStorage;
use YorvoID\Throttle\ThrottleStore;

/**
 * Puts YorvoID's stores in memory, and changes nothing else: every other service is the application's
 * own definition, so the policy under test is the one production builds (M2-5's one construction path).
 * YorvoID's connection is refused: a store this list misses fails the tests, and never reaches a
 * database.
 *
 * @internal
 */
final readonly class InMemoryStoresPass implements CompilerPassInterface
{
    /** Each store YorvoID's bridge defines, and InMemoryStorage's store for it */
    public const array STORES = [
        Transactions::class => 'transactions',
        PrincipalStore::class => 'principals',
        IdentifierStore::class => 'identifiers',
        SecurityLogStore::class => 'log',
        SessionStore::class => 'sessions',
        KnownDeviceStore::class => 'devices',
        OneTimeTokenStore::class => 'tokens',
        SetupStore::class => 'setup',
        CredentialStore::class => 'credentials',
        RecoveryCodeStore::class => 'recoveryCodes',
        CoolDownStore::class => 'coolDowns',
        PasskeyStore::class => 'passkeys',
        CeremonyStore::class => 'ceremonies',
        PolicyStore::class => 'policy',
        RelationStore::class => 'relations',
        AttributeStore::class => 'attributes',
        ThrottleStore::class => 'throttle',
        DecisionLogStore::class => 'decisions',
    ];

    /** The stores mutants change what's read from */
    private const array SEAMS = [
        PolicyStore::class => 'policyStore',
        RelationStore::class => 'relationStore',
    ];

    /** Evaluator::builtIn()'s parameters 0 and 3, which the mutants wrap */
    private const string WRAPPED = '$authority: YorvoID\\Core\\Authorization\\AuthorityCheck, $elements: iterable';

    /**
     * The bridge's evaluator, built by Evaluator::builtIn() with the authority check and the elements
     * where the pass wraps them: checked, so a change to either fails here rather than wrapping
     * something else.
     *
     * @throws \LogicException When it isn't
     */
    private static function evaluator(ContainerBuilder $container): Definition
    {
        $evaluator = $container->getDefinition(Evaluator::class);

        if ([Evaluator::class, 'builtIn'] !== $evaluator->getFactory()) {
            throw new \LogicException('The bridge no longer builds the evaluator with Evaluator::builtIn(): the policy testing pass must follow it.');
        }

        $parameters = new \ReflectionMethod(Evaluator::class, 'builtIn')->getParameters();
        $wrapped = \sprintf('$%s: %s, $%s: %s', $parameters[0]->getName(), $parameters[0]->getType(), $parameters[3]->getName(), $parameters[3]->getType());

        if (self::WRAPPED !== $wrapped) {
            throw new \LogicException(\sprintf('Evaluator::builtIn()\'s parameters 0 and 3 are no longer %s but %s: the policy testing pass must follow them.', self::WRAPPED, $wrapped));
        }

        return $evaluator;
    }

    public function process(ContainerBuilder $container): void
    {
        $container->setDefinition(InMemoryStorage::class, new Definition(InMemoryStorage::class));

        $mutants = new Reference(Mutants::class);
        $container->setDefinition(Mutants::class, new Definition(Mutants::class)
            ->addMethodCall('catalogue', [new Reference(PolicyCatalogue::class)])
            ->addMethodCall('schema', [new Reference(RelationSchema::class)])
            ->setPublic(true));

        foreach (self::STORES as $interface => $store) {
            // In place of the bridge's alias, as public as it was.
            $public = $container->hasAlias($interface) && $container->getAlias($interface)->isPublic();
            $definition = new Definition($interface)->setFactory([Stores::class, 'store'])->setArguments([new Reference(InMemoryStorage::class), $store]);
            $seam = self::SEAMS[$interface] ?? null;

            if (null !== $seam) {
                $definition = new Definition($interface)->setFactory([$mutants, $seam])->setArguments([$definition]);
            }

            $container->setDefinition($interface, $definition->setPublic($public));
        }

        // The evaluator's authority check and elements, through the mutants: the definition is the bridge's.
        $evaluator = self::evaluator($container);
        $evaluator->replaceArgument(0, new Definition(AuthorityCheck::class)->setFactory([$mutants, 'authority'])->setArguments([$evaluator->getArgument(0)]));
        $evaluator->replaceArgument(3, new Definition('array')->setFactory([$mutants, 'elements'])->setArguments([$evaluator->getArgument(3)]));

        $container->setDefinition('yorvoid.connection', new Definition(Connection::class)->setFactory([Stores::class, 'noDatabase']));
        $container->setDefinition(Stores::class, new Definition(Stores::class)->setArguments([new Reference(InMemoryStorage::class), new Reference('services_resetter')])->setPublic(true));
        $container->setAlias('yorvoid_policy_testing.operations', Operations::class)->setPublic(true);
        $container->setAlias('yorvoid_policy_testing.policy', Policy::class)->setPublic(true);
        $container->setAlias('yorvoid_policy_testing.realm', Realm::class)->setPublic(true);
        $container->setDefinition('yorvoid_policy_testing.cases', new Definition(CasesOf::class)->setArguments([new TaggedIteratorArgument('yorvoid.policy_cases')])->setPublic(true));
    }
}
