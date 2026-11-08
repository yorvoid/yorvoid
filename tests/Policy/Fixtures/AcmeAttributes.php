<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy\Fixtures;

use YorvoID\Policy\Attribute\AttributeDeclarations;
use YorvoID\Policy\Attribute\AttributeType;
use YorvoID\Policy\Attribute\SecurityAttribute;

final readonly class AcmeAttributes implements AttributeDeclarations
{
    public function attributes(): iterable
    {
        yield new SecurityAttribute('acme.department', 'Department', 'acme');
        yield new SecurityAttribute('acme.clearance', 'Clearance', 'acme', AttributeType::Integer);
        yield new SecurityAttribute('acme.teams', 'Teams', 'acme', AttributeType::Strings);
        yield new SecurityAttribute('acme.contractor', 'Contractor', 'acme', AttributeType::Boolean);
    }
}
