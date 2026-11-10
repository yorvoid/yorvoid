<?php

declare(strict_types=1);

namespace YorvoID\E2e\Acme;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\E2e\Kernel;

/**
 * Acme Team's projects, in a file next to the test app's database: what the usability test's
 * participants see and remove.
 */
final readonly class Projects implements OperationHandler
{
    /** The projects a reset starts with, by ID */
    public const array START = ['old-newsletter' => 'Old newsletter', 'spring-fair' => 'Spring fair', 'summer-fair' => 'Summer fair', 'website' => 'Website'];

    public function operations(): array
    {
        return [RemoveProject::class => $this->remove(...)];
    }

    /**
     * @return array<string, string> By ID
     */
    public function all(): array
    {
        $file = self::file();

        if (!is_file($file)) {
            return self::START;
        }

        /** @var array<string, string> $projects */
        $projects = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);

        return $projects;
    }

    public function reset(): void
    {
        @unlink(self::file());
    }

    public function remove(RemoveProject $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);
        $projects = $this->all();
        unset($projects[$command->project]);
        file_put_contents(self::file(), json_encode($projects, \JSON_THROW_ON_ERROR));
    }

    private static function file(): string
    {
        return Kernel::data().'/projects.json';
    }
}
