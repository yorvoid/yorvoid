<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Operation\Fixtures\Doors;

use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;

final class Doors implements OperationHandler
{
    /** @var list<Permit> */
    public array $permits = [];

    public function operations(): array
    {
        return [
            OpenDoor::class => $this->open(...),
            LogDoor::class => $this->log(...),
        ];
    }

    public function open(OpenDoor $command, Permit $permit, Origin $origin): string
    {
        $permit->use($command);
        $this->permits[] = $permit;
        $log = new LogDoor($command->realm);

        return $this->log($log, $permit->consequence($log), $origin);
    }

    public function log(LogDoor $command, Permit $permit, Origin $origin): string
    {
        $permit->use($command);
        $this->permits[] = $permit;

        return 'opened';
    }
}
