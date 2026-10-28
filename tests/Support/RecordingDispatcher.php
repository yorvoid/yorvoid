<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * A PSR-14 dispatcher for tests: records every event, and calls listeners registered by class.
 */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    /** @var array<class-string, list<\Closure(object): void>> */
    private array $listeners = [];

    /**
     * @template T of object
     *
     * @param class-string<T>   $event
     * @param \Closure(T): void $listener
     */
    public function listen(string $event, \Closure $listener): void
    {
        $this->listeners[$event][] = static function (object $dispatched) use ($event, $listener): void {
            \assert($dispatched instanceof $event);
            $listener($dispatched);
        };
    }

    /**
     * The events of one class, in the order dispatched.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->dispatched, static fn (object $event): bool => $event instanceof $class));
    }

    /**
     * The last event dispatched; null if none was.
     */
    public function last(): ?object
    {
        return [] === $this->dispatched ? null : $this->dispatched[\count($this->dispatched) - 1];
    }

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        foreach ($this->listeners[$event::class] ?? [] as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }

    /**
     * @return list<class-string>
     */
    public function classes(): array
    {
        return array_map(static fn (object $event): string => $event::class, $this->dispatched);
    }
}
