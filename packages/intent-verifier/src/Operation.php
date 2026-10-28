<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * An operation the verifier knows (INT-008): its name, its parameters and their types, and whether it
 * needs a signature. The verifier runs only operations it knows, with exactly their parameters (T-INT-3).
 *
 * @api
 */
final readonly class Operation
{
    /** Dot-separated lowercase segments, as YorvoID's operations, e.g. "quire.site.remove" */
    public const string NAME = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){2,}$/D';

    /**
     * @param array<string, ParameterType> $parameters Name => type; every one is required
     * @param bool                         $signed     Whether it needs a signature; false only for operations listed as safe to run unsigned
     */
    public function __construct(
        public string $action,
        public array $parameters = [],
        public bool $signed = true,
    ) {
        if (1 !== preg_match(self::NAME, $action) || \strlen($action) > 191) {
            throw new ConfigurationError(\sprintf('The operation "%s" is invalid: use at most 191 characters, in at least three dot-separated lowercase segments.', $action));
        }

        foreach (array_keys($parameters) as $name) {
            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/D', $name)) {
                throw new ConfigurationError(\sprintf('The parameter "%s" of %s is invalid: use lowercase letters, digits and underscores.', $name, $action));
            }
        }
    }

    /**
     * Whether the parameters are exactly this operation's, each of its type.
     *
     * @param array<mixed> $parameters
     */
    public function accepts(array $parameters): bool
    {
        if (!self::sameNames($parameters, $this->parameters)) {
            return false;
        }

        foreach ($this->parameters as $name => $type) {
            if (!$type->accepts($parameters[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private static function sameNames(array $a, array $b): bool
    {
        $names = array_keys($a);
        $expected = array_keys($b);
        sort($names);
        sort($expected);

        return $names === $expected;
    }
}
