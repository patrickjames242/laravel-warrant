<?php

namespace Warrant;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
class GlobalCondition
{
    public function __construct(public ?string $key = null)
    {
        if ($this->key === '') {
            throw new InvalidArgumentException('GlobalCondition key cannot be empty.');
        }
    }
}
