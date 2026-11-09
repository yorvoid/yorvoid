<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support\Itf;

/**
 * One state of a model's execution, as Quint's ITF gives it with --mbt: the action that led to it, the
 * choices that action made, and the model's variables, decoded into PHP values (Itf::value()).
 */
final readonly class ItfState
{
    /**
     * @param array<string, mixed> $picks Each choice the action made, null for one it didn't
     * @param array<string, mixed> $vars  By name, without their module's path
     */
    public function __construct(
        public string $action,
        public array $picks,
        public array $vars,
    ) {
    }

    public function var(string $name): mixed
    {
        return \array_key_exists($name, $this->vars) ? $this->vars[$name] : throw new \LogicException(\sprintf('The model has no variable "%s".', $name));
    }

    public function pick(string $name): mixed
    {
        return $this->picks[$name] ?? null;
    }
}
