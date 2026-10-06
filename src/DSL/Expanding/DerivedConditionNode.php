<?php

namespace Warrant\DSL\Expanding;

use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;

/**
 * A derived condition after expansion: the call as the rule wrote it, and the
 * expression its method answered with, itself already expanded.
 *
 * Splicing the body in bare would compile the same leaves, but under the wrong
 * names. A derived condition's body is written by its schema's author, who cannot
 * see where it is reached from, so it is compiled in a scope of its own: its own
 * schema key stands for the frame the condition was asked about, and the names
 * the calling text had in scope are out of it. The node is where that boundary
 * survives expansion, and the call it records is what a compile trace shows for
 * the layer the rule text cannot.
 *
 * It belongs to the schema whose rule or `check(...)` predicate named it, which
 * is the schema compiling it — so the node carries no schema of its own.
 */
final readonly class DerivedConditionNode implements IBooleanExpressionNode
{
    /**
     * @param array<int, mixed> $parameters The arguments as the rule wrote them,
     *   `@context` / `@column` references unresolved.
     */
    public function __construct(
        public string $conditionKey,
        public array $parameters,
        public IBooleanExpressionNode $body,
    ) {
    }
}
