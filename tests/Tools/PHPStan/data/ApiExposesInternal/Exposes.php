<?php

declare(strict_types=1);

namespace YorvoID\Tests\Tools\PHPStan\data\ApiExposesInternal;

/**
 * @api
 */
class Exposes
{
    public ?Hidden $field = null;

    /** @var list<Hidden> */
    protected array $listed = [];

    public function __construct(private Hidden $hidden)
    {
    }

    public function give(): Hidden
    {
        return $this->hidden;
    }

    /**
     * @return list<Hidden>
     */
    public function all(): array
    {
        return [$this->hidden];
    }

    public function take(Shown|Hidden $value): void
    {
    }

    protected function extend(Hidden $hidden): void
    {
    }

    private function own(Hidden $hidden): void
    {
    }
}
