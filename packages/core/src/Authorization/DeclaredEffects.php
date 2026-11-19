<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\OperationCatalogue;

/**
 * The effects of commands that change authority, from the providers that declare them (D-51): each
 * computed once per decision, so that everything weighing the change weighs the same snapshot, and
 * again after forget(), when the decision is made again.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise while delegation (M3) shapes it: it may change in a minor
 *               release ([compatibility](docs/compatibility.md)).
 */
final readonly class DeclaredEffects
{
    /** @var array<class-string, DeclaresEffects> */
    private array $providers;

    /** @var \WeakMap<Request, Effect> */
    private \WeakMap $effects;

    /**
     * @param iterable<DeclaresEffects> $providers
     *
     * @throws IntegratorError When two providers declare one command
     */
    public function __construct(iterable $providers)
    {
        $by = [];

        foreach ($providers as $provider) {
            foreach ($provider->declares() as $command) {
                if (isset($by[$command])) {
                    throw new IntegratorError(\sprintf('The effect of %s is declared twice: by %s and %s.', $command, $by[$command]::class, $provider::class), 'Give each command that changes authority one provider.', 'concepts/authorization.md#policy-elements');
                }

                $by[$command] = $provider;
            }
        }

        $this->providers = $by;
        $this->effects = new \WeakMap();
    }

    /**
     * The effect of the request's command; null for one no provider declares, which a forbid weighing
     * changes treats as unknown (C1).
     */
    public function effectOf(Request $request): ?Effect
    {
        $command = $request->command;

        if (null === $command || !isset($this->providers[$command::class])) {
            return null;
        }

        return $this->effects[$request] ??= $this->providers[$command::class]->effectOf($request);
    }

    /**
     * The request is decided again, e.g. by the guard once what it read has changed: its effect is
     * computed again too.
     */
    public function forget(Request $request): void
    {
        unset($this->effects[$request]);
    }

    /**
     * That every operation of the catalogue that changes authority has its effect declared: a forbid
     * weighing changes asks when Operations is built.
     *
     * @throws IntegratorError When one hasn't
     */
    public function check(OperationCatalogue $catalogue): void
    {
        foreach ($catalogue->all() as $operation) {
            if (is_subclass_of($operation->command, ChangesAuthority::class) && !isset($this->providers[$operation->command])) {
                throw new IntegratorError(\sprintf('"%s" changes authority, but nothing declares its effect.', $operation->name), 'Give the forbids that weigh changes its package\'s effects: in Symfony the bundle does; in plain PHP, a DeclaredEffects with every package\'s DeclaresEffects.', 'concepts/authorization.md#policy-elements');
            }
        }
    }
}
