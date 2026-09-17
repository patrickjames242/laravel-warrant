<?php

namespace Warrant\Schema;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
class RuleTemplate
{
    public function __construct(public ?string $key = null)
    {
        if ($this->key === '') {
            throw new InvalidArgumentException('RuleTemplate key cannot be empty.');
        }
    }
}
