<?php

namespace Warrant;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
class TargetedCondition
{
    public function __construct(public ?string $key = null)
    {
        if ($this->key === '') {
            throw new InvalidArgumentException('TargetedCondition key cannot be empty.');
        }
    }
}
