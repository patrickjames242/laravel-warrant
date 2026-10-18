<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A boolean expression the text should have here and does not, as in `if they
 * can view`: what an analysis puts in its place, having reported it, so the rule
 * around it is still read and kept.
 *
 * Only a tolerant parse makes one, and nothing is ever compiled from it: the
 * validator passes over it, since the mistake is reported already, and the
 * compiler refuses it.
 */
final readonly class MissingExpressionNode implements IBooleanExpressionNode
{
    public function __construct(public SyntaxLocation $location) {}
}
