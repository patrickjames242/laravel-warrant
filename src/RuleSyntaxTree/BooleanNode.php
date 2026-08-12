<?php

namespace Warrant\RuleSyntaxTree;

/**
 * A constant boolean leaf — `true` (always) or `false` (never).
 *
 * The string DSL never produces one; it exists for the fluent builder, where an
 * empty group (e.g. a folded-over empty list) must resolve to a concrete operand.
 * An empty group is `false`: it contributes nothing to an `or` and vetoes an `and`.
 */
readonly class BooleanNode implements IBooleanExpressionNode
{
    public function __construct(
        public bool $value,
    ) {
    }
}
