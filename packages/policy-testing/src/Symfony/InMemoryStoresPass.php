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
use YorvoID\Core\Authorization\DecisionLog\DecisionLogStore;
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
use YorvoID\Policy\PolicyStore;
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

    public function process(ContainerBuilder $container): void
    {
        $container->setDefinition(InMemoryStorage::class, new Definition(InMemoryStorage::class));

        foreach (self::STORES as $interface => $store) {
            // In place of the bridge's alias, as public as it was.
            $public = $container->hasAlias($interface) && $container->getAlias($interface)->isPublic();
            $container->setDefinition($interface, new Definition($interface)->setFactory([Stores::class, 'store'])->setArguments([new Reference(InMemoryStorage::class), $store])->setPublic($public));
        }

        $container->setDefinition('yorvoid.connection', new Definition(Connection::class)->setFactory([Stores::class, 'noDatabase']));
        $container->setDefinition(Stores::class, new Definition(Stores::class)->setArguments([new Reference(InMemoryStorage::class), new Reference('services_resetter')])->setPublic(true));
        $container->setAlias('yorvoid_policy_testing.operations', Operations::class)->setPublic(true);
        $container->setAlias('yorvoid_policy_testing.policy', Policy::class)->setPublic(true);
        $container->setAlias('yorvoid_policy_testing.realm', Realm::class)->setPublic(true);
        $container->setDefinition('yorvoid_policy_testing.cases', new Definition(CasesOf::class)->setArguments([new TaggedIteratorArgument('yorvoid.policy_cases')])->setPublic(true));
    }
}
