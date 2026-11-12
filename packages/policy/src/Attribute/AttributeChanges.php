<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Authorization\RequiresForbids;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Policy\RolePermit;

/**
 * Sets principals' security attributes through Operations::run(), in a transaction with its security
 * log entry. The entry names the attribute, never its value: the log can't forget.
 *
 * @api
 */
final readonly class AttributeChanges implements OperationHandler, RequiresForbids
{
    public function __construct(
        private Attributes $attributes,
        private AttributeStore $store,
        private PrincipalStore $principals,
        private SecurityLog $log,
        private Transactions $transactions,
    ) {
    }

    public function operations(): array
    {
        return [SetAttribute::class => $this->set(...)];
    }

    /**
     * @throws UnknownAttribute When it isn't declared, or the value isn't of its type
     * @throws UnknownPrincipal
     */
    private function set(SetAttribute $command, Permit $permit, Origin $origin): void
    {
        $permit->use($command);
        $attribute = $this->attributes->declared($command->name) ?? throw new UnknownAttribute(\sprintf('The attribute "%s" isn\'t declared.', $command->name));

        if (null !== $command->value && !$attribute->type->fits($command->value)) {
            throw new UnknownAttribute(\sprintf('The attribute "%s" takes a value of the type %s.', $attribute->name, $attribute->type->value));
        }

        $this->transactions->run(function () use ($command, $permit, $origin): void {
            if (null === $this->principals->get($command->realm, $command->principalId)) {
                throw new UnknownPrincipal($command->realm, $command->principalId);
            }

            null === $command->value
                ? $this->store->remove($command->realm, $command->principalId, $command->name)
                : $this->store->set($command->realm, $command->principalId, $command->name, $command->value);
            $this->log->record($command->realm, 'yorvoid.attribute.set', Outcome::Success, $origin, $permit->actor->principalId, 'principal', $command->principalId->uuid->toRfc4122(), ['attribute' => $command->name, 'removed' => null === $command->value]);
        });
    }

    /**
     * Nobody sets their own security attributes, and nobody sets anyone's without a recent proof, whatever
     * permits it (M2-13): no-escalation and the step-up say so.
     */
    public function requiredForbids(): array
    {
        return RolePermit::SAFETY;
    }
}
