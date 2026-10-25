<?php

namespace Warrant\Schema\Conditions;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
class RowCondition
{
    public function __construct(public ?string $key = null)
    {
        if ($this->key === '') {
            throw new InvalidArgumentException('RowCondition key cannot be empty.');
        }
    }
}
