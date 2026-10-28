<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Kernel;
use YorvoID\Symfony\Security\Authenticator\PasskeyAuthenticator;
use YorvoID\Symfony\Security\Authenticator\RecoveryCodeAuthenticator;
use YorvoID\Symfony\Security\Authenticator\RecoveryLinkAuthenticator;
use YorvoID\Symfony\Security\Authenticator\SessionAuthenticator;
use YorvoID\Symfony\Security\PrincipalUserChecker;
use YorvoID\Symfony\Security\PrincipalUserProvider;
use YorvoID\Symfony\YorvoIDBundle;

/**
 * A Symfony application with YorvoID, as one would set it up: on a SQLite file of its own.
 */
final class TestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $yorvoid        YorvoID's configuration, over the defaults below
     * @param bool                 $translations   Whether the application has Symfony's translator
     * @param bool                 $authenticators Whether the firewall has YorvoID's authenticators
     */
    public function __construct(
        private readonly string $directory,
        private readonly array $yorvoid = [],
        private readonly bool $translations = true,
        private readonly bool $authenticators = true,
        private readonly bool $twig = true,
        private readonly bool $customize = false,
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->twig) {
            yield new TwigBundle();
        }
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new YorvoIDBundle();
    }

    public function getProjectDir(): string
    {
        return $this->directory;
    }

    public function getCacheDir(): string
    {
        return $this->directory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory.'/log';
    }

    /**
     * A clock the tests set.
     */
    public function process(ContainerBuilder $container): void
    {
        $container->setDefinition('clock', new Definition(MockClock::class))->setPublic(true);
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->register('logger', NullLogger::class);
            $container->loadFromExtension('framework', [
                'test' => true,
                'secret' => 'test',
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'mailer' => ['dsn' => 'null://null'],
                'translator' => ['enabled' => $this->translations, 'default_path' => $this->directory.'/translations'],
                'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
                'router' => ['resource' => __DIR__.'/routes.php', 'type' => 'php', 'utf8' => true],
            ]);
            $container->loadFromExtension('doctrine', ['dbal' => ['default_connection' => 'default', 'connections' => [
                'default' => ['url' => 'sqlite:///'.$this->directory.'/yorvoid.sqlite'],
                'other' => ['url' => 'sqlite:///'.$this->directory.'/other.sqlite'],
            ]]]);
            $container->loadFromExtension('security', [
                'providers' => ['yorvoid' => ['id' => PrincipalUserProvider::class]],
                'firewalls' => ['main' => [
                    'lazy' => true,
                    'provider' => 'yorvoid',
                    'user_checker' => PrincipalUserChecker::class,
                ] + ($this->authenticators ? ['custom_authenticators' => [PasskeyAuthenticator::class, RecoveryCodeAuthenticator::class, RecoveryLinkAuthenticator::class, SessionAuthenticator::class]] : [])],
                'access_control' => [['path' => '^/me', 'roles' => 'IS_AUTHENTICATED']],
            ]);
            $container->register(IntentController::class)->setPublic(true)->setAutowired(true)->addTag('controller.service_arguments');

            if ($this->customize) {
                $container->register(TermsCustomizer::class)->setAutoconfigured(true);
            }

            $container->register(MeController::class)->setPublic(true)->setAutowired(true)->addTag('controller.service_arguments');
            $container->register('test.users', OwnUsers::class);
            $yorvoid = array_replace_recursive([
                'passkeys' => ['relying_party_id' => 'example.com', 'origins' => ['https://example.com']],
                'notifications' => ['application_name' => 'Acme', 'not_me_url' => 'https://example.com/not-me?token={token}', 'from' => 'security@example.com'],
            ], $this->yorvoid);

            $passkeys = $yorvoid['passkeys'];
            \assert(\is_array($passkeys));

            // Lists are replaced, not merged.
            if (\is_array($this->yorvoid['passkeys'] ?? null) && \array_key_exists('origins', $this->yorvoid['passkeys'])) {
                $passkeys['origins'] = $this->yorvoid['passkeys']['origins'];
            }

            $container->loadFromExtension('yorvoid', ['passkeys' => $passkeys] + array_filter($yorvoid, static fn (int|string $name): bool => \is_string($name), \ARRAY_FILTER_USE_KEY));
        });
    }
}
