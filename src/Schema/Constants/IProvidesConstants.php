<?php

namespace Warrant\Schema\Constants;

/**
 * A schema that declares `#[Constant]`s rule text may name.
 */
interface IProvidesConstants
{
    /**
     * @return list<ConstantDefinition> The `#[Constant]`s, in declaration order.
     *   A schema's abilities are constants too, but are not among these.
     */
    public function constants(): array;
}
