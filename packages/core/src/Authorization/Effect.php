<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Principal\PrincipalId;

/**
 * What a change gives and what it takes, from anyone (M2-13), each part in the order it's weighed; or
 * a security attribute set on a principal, which the application's rules read.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise while delegation (M3) shapes it: it may change in a minor
 *               release ([compatibility](docs/compatibility.md)).
 */
final readonly class Effect
{
    /**
     * @param list<EffectPart>     $gives
     * @param list<EffectPart>     $takes
     * @param AttributeChange|null $attribute A security attribute it sets or removes
     */
    public function __construct(
        public array $gives = [],
        public array $takes = [],
        public ?AttributeChange $attribute = null,
    ) {
    }

    /**
     * A change that gives and takes nothing, e.g. a group created empty or a role relabeled.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * A security attribute set, or removed, on a principal.
     */
    public static function attribute(PrincipalId $principal, string $name, bool $removes): self
    {
        return new self(attribute: new AttributeChange($principal, $name, $removes));
    }

    /**
     * Whether it gives or takes anything, or sets an attribute.
     */
    public function changes(): bool
    {
        return [] !== $this->gives || [] !== $this->takes || null !== $this->attribute;
    }

    /**
     * Whether it can give: something given, or an attribute set or removed.
     */
    public function gives(): bool
    {
        return [] !== $this->gives || null !== $this->attribute;
    }
}
