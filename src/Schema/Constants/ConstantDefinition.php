<?php

namespace Warrant\Schema\Constants;

/**
 * A constant rule text may name: a `#[Constant]`, or an ability, which is a
 * constant named by its value and holding it.
 */
final readonly class ConstantDefinition
{
    /**
     * @param string $name The name rule text uses.
     * @param mixed $value The constant's value. Null when $valueIsKnown is false.
     * @param bool $valueIsKnown Whether $value is the constant's value. Only the
     *   language server reads a constant without knowing its value: one computed
     *   in a way its source cannot be evaluated, or one that cannot be written as
     *   JSON, such as an enum case. A value that is not known is never judged.
     */
    public function __construct(
        public string $name,
        public mixed $value,
        public bool $valueIsKnown = true,
    ) {}
}
