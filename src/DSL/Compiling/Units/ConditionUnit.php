<?php

namespace Warrant\DSL\Compiling\Units;

use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;

/**
 * A bare expression tree, compiled in isolation without the deny-overrides
 * formula — "is this condition true for the target row?" and nothing more.
 *
 * There is no rule set here because there are no rules involved: the two callers
 * are the singular-target denial diagnostic (did one `cannot` rule's condition
 * fire?) and a cross-schema `check(...)`, which compiles a predicate against the
 * referenced schema's own resolver.
 *
 * A null condition — an unconditional `cannot` — always matches.
 */
final readonly class ConditionUnit implements CompilationUnit
{
    public function __construct(
        public ?IBooleanExpressionNode $condition,
    ) {
    }
}
