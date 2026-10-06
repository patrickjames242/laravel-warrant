<?php

namespace Warrant\DSL\Expanding;

use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;

/**
 * The answer *unknown*, from a derived condition that returned null: the question
 * has no answer here.
 *
 * It compiles to the same third truth value as a row condition asked with no row
 * in scope. An unknown negates to itself, so it neither grants nor lifts a deny.
 * The rule language has no way to write one, so it only ever comes out of
 * expansion.
 */
final readonly class UnknownNode implements IBooleanExpressionNode
{
}
