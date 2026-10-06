<?php

namespace Warrant\Schema;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
class DerivedCondition
{
    public function __construct(public ?string $key = null)
    {
        if ($this->key === '') {
            throw new InvalidArgumentException('DerivedCondition key cannot be empty.');
        }
    }
}
