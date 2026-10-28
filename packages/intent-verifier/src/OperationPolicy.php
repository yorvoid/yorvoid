<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * Every operation the verifier runs, and which of them need a signature (INT-008): an operation that
 * isn't listed isn't run, and one that runs unsigned is listed as such, explicitly.
 *
 * The verifier's own operations, adding and removing its keys, are always signed and always there.
 *
 * @api
 */
final readonly class OperationPolicy
{
    public const string ADD_KEY = 'yorvoid.verifier.add_key';

    public const string REMOVE_KEY = 'yorvoid.verifier.remove_key';

    /** @var array<string, Operation> By action */
    public array $operations;

    /**
     * @param list<Operation> $operations The application's operations
     */
    public function __construct(array $operations)
    {
        $all = [
            self::ADD_KEY => new Operation(self::ADD_KEY, ['credential_id' => ParameterType::String, 'public_key' => ParameterType::String, 'user_handle' => ParameterType::String, 'label' => ParameterType::String]),
            self::REMOVE_KEY => new Operation(self::REMOVE_KEY, ['credential_id' => ParameterType::String]),
        ];

        foreach ($operations as $operation) {
            if (str_starts_with($operation->action, 'yorvoid.verifier.')) {
                throw new ConfigurationError(\sprintf('The operation %s is the verifier\'s own: name yours after your application, e.g. "acme.site.remove".', $operation->action));
            }

            if (isset($all[$operation->action])) {
                throw new ConfigurationError(\sprintf('The operation %s is listed twice.', $operation->action));
            }

            $all[$operation->action] = $operation;
        }

        $this->operations = $all;
    }

    /**
     * @throws ConfigurationError When the operation isn't listed
     */
    public function get(string $action): Operation
    {
        return $this->operations[$action] ?? throw new ConfigurationError(\sprintf('The verifier doesn\'t know the operation "%s": list it in its OperationPolicy.', $action));
    }
}
