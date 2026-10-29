<?php

declare(strict_types=1);

namespace YorvoID\Tests\Docs;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Dumper\YamlReferenceDumper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Symfony\YorvoIDBundle;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * The reference pages are what the code says: the configuration tree and the console commands, as
 * Symfony describes them. When they change, `composer docs-reference` writes the pages again.
 */
final class ReferenceDocsTest extends TestCase
{
    private const string REFERENCE = __DIR__.'/../../docs/reference';

    public function testTheConfigurationReferenceIsTheBundles(): void
    {
        $extension = new YorvoIDBundle()->getContainerExtension();
        \assert($extension instanceof ConfigurationExtensionInterface);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        \assert($configuration instanceof ConfigurationInterface);

        $this->check('configuration.md', <<<MD
            # Configuration reference

            Everything the Symfony bundle takes under `yorvoid:`, with its defaults, as `bin/console config:dump-reference yorvoid` prints it. What each setting means is explained in [Symfony](../concepts/symfony.md#configuration); settings marked "preset" come from the [preset](../concepts/symfony.md#presets) when left out.

            ```yaml
            {$this->trimmed(new YamlReferenceDumper()->dump($configuration))}
            ```

            MD);
    }

    public function testTheConsoleReferenceIsTheCommands(): void
    {
        $directory = \sprintf('%s/yorvoid-reference-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $kernel = new TestKernel($directory);

        try {
            $kernel->boot();
            $application = new Application($kernel);
            $commands = array_filter($application->all('yorvoid'), static fn ($command): bool => !$command->isHidden());
            ksort($commands);
            $page = "# Console commands\n\nThe Symfony bundle's commands, as `bin/console help` describes them. [Symfony](../concepts/symfony.md#console-commands) says when to use each.\n";

            foreach ($commands as $name => $command) {
                $definition = $command->getDefinition();
                $page .= \sprintf("\n## `%s`\n\n%s.\n\n```bash\nphp bin/console %s\n```\n", $name, $command->getDescription(), $command->getSynopsis(true));
                $rows = [
                    ...array_map(static fn (InputArgument $argument): string => \sprintf('| `%s` | %s | %s |', $argument->getName(), $argument->getDescription(), self::default($argument->getDefault())), $definition->getArguments()),
                    ...array_map(static fn (InputOption $option): string => \sprintf('| `--%s` | %s | %s |', $option->getName(), $option->getDescription(), self::default($option->getDefault())), $definition->getOptions()),
                ];

                if ([] !== $rows) {
                    $page .= "\n| Argument or option | | Default |\n|---|---|---|\n".implode("\n", $rows)."\n";
                }
            }

            $this->check('console.md', $page);
        } finally {
            $kernel->shutdown();
            new Filesystem()->remove($directory);
        }
    }

    private function check(string $file, string $content): void
    {
        if (false !== getenv('YORVOID_WRITE_REFERENCE')) {
            file_put_contents(self::REFERENCE.'/'.$file, $content);
        }

        self::assertSame($content, (string) @file_get_contents(self::REFERENCE.'/'.$file), \sprintf('docs/reference/%s is out of date: run composer docs-reference.', $file));
    }

    private function trimmed(string $yaml): string
    {
        return implode("\n", array_map(rtrim(...), explode("\n", rtrim($yaml))));
    }

    private static function default(mixed $default): string
    {
        return match (true) {
            null === $default, false === $default, [] === $default => '',
            \is_string($default) => \sprintf('`%s`', $default),
            default => \sprintf('`%s`', json_encode($default)),
        };
    }
}
