<?php

namespace Warrant\Schema\Constants;

use Attribute;
use InvalidArgumentException;

/**
 * Marks a schema class constant as a constant rule text may name. Its value is
 * whatever the PHP constant holds; where a rule uses it decides what that value
 * must be.
 *
 * Rule text names it by its PHP name in lowercase snake_case, or by $name when
 * one is given: see {@see ConstantName}.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class Constant
{
    /**
     * @param string|null $name The name rule text uses, written exactly as rule
     *   text writes it. Null to take it from the PHP name.
     */
    public function __construct(public ?string $name = null)
    {
        if ($this->name === '') {
            throw new InvalidArgumentException('Constant name cannot be empty.');
        }
    }
}
