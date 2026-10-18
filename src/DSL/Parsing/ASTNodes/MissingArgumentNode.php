<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * An argument the text should have here and does not, as in `a(1, )` or
 * `@context` with no key: what an analysis puts in its place, having reported it,
 * so the arguments around it keep their positions and the condition its count.
 *
 * Only a tolerant parse makes one, and nothing is ever compiled from it: the
 * validator passes over it, since the mistake is reported already, and the
 * compiler refuses it.
 */
final readonly class MissingArgumentNode implements INode
{
    public function __construct(public SyntaxLocation $location) {}
}
