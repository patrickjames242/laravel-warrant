<?php

namespace Warrant\DSL\Parsing\ASTNodes;

/**
 * How many of the rows a handle reaches a reference asks about: `any of`, true
 * when one of them passes, or `all of`, true when there is at least one and
 * every one of them passes.
 */
enum Quantifier
{
    case Any;
    case All;
}
