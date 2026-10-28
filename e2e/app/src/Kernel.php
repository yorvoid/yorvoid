<?php

declare(strict_types=1);

namespace YorvoID\E2e;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use YorvoID\IntentVerifier\Operation;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\ParameterType;
use YorvoID\IntentVerifier\Storage\FileVerifierLog;
use YorvoID\IntentVerifier\Storage\FileVerifierStore;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\IntentVerifier\VerifierConfig;
use YorvoID\Symfony\Security\Authenticator\SessionAuthenticator;
use YorvoID\Symfony\Security\PrincipalUserChecker;
use YorvoID\Symfony\Security\PrincipalUserProvider;
use YorvoID\Symfony\YorvoIDBundle;

/**
 * The application the browser tests run against: YorvoID's flows and pages, as an application
 * would set them up, with a verifier of its own for signed operations, and endpoints only tests use.
 */
final class Kernel extends BaseKernel
{
    public const string ORIGIN = 'http://localhost:8000';

    public function __construct()
    {
        parent::__construct('e2e', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new YorvoIDBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return self::data().'/cache';
    }

    public function getLogDir(): string
    {
        return self::data().'/log';
    }

    /**
     * Where the application keeps its database, sessions and the verifier's files.
     */
    public static function data(): string
    {
        return \dirname(__DIR__, 3).'/var/e2e';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $data = self::data();
            $container->loadFromExtension('framework', [
                'secret' => 'e2e',
                'http_method_override' => false,
                'router' => ['resource' => __DIR__.'/../routes.php', 'type' => 'php', 'utf8' => true],
                'session' => ['handler_id' => 'session.handler.native_file', 'save_path' => $data.'/sessions', 'cookie_secure' => 'auto', 'cookie_samesite' => 'lax'],
                'translator' => ['default_path' => __DIR__.'/../translations', 'fallbacks' => ['en']],
            ]);
            $container->loadFromExtension('doctrine', ['dbal' => ['url' => 'sqlite:///'.$data.'/yorvoid.sqlite']]);
            $container->loadFromExtension('security', [
                'providers' => ['yorvoid' => ['id' => PrincipalUserProvider::class]],
                'firewalls' => ['main' => ['lazy' => true, 'provider' => 'yorvoid', 'user_checker' => PrincipalUserChecker::class, 'custom_authenticators' => [SessionAuthenticator::class]]],
            ]);
            $container->loadFromExtension('yorvoid', [
                'passkeys' => ['relying_party_id' => 'localhost', 'origins' => [self::ORIGIN]],
                'notifications' => ['enabled' => false],
                'flows' => ['completed_paths' => ['yorvoid.sign_intent' => '/signed']],
            ]);

            $container->register(Verifier::class)->setPublic(true)->setArguments([
                new \Symfony\Component\DependencyInjection\Definition(VerifierConfig::class, ['e2e-verifier', 'localhost', [self::ORIGIN]]),
                new \Symfony\Component\DependencyInjection\Definition(OperationPolicy::class, [[new \Symfony\Component\DependencyInjection\Definition(Operation::class, ['acme.site.remove', ['site' => ParameterType::String]])]]),
                new \Symfony\Component\DependencyInjection\Definition(FileVerifierStore::class, [$data.'/verifier.json']),
                new \Symfony\Component\DependencyInjection\Definition(FileVerifierLog::class, [$data.'/verifier.jsonl']),
            ]);
            $container->register(AppController::class)->setAutowired(true)->setPublic(true)->addTag('controller.service_arguments')->setArgument('$kernel', new \Symfony\Component\DependencyInjection\Reference('kernel'));
            $container->register(SignedOperations::class)->setAutowired(true)->addTag('kernel.event_listener');
        });
    }
}
