<?php

namespace Warrant\DSL\Parsing\ASTNodes;

/**
 * A piece of rule syntax, placed where it came from: every node holds the
 * {@see \Warrant\DSL\Parsing\Positions\SyntaxLocation} it came from as a
 * public `$location`, and lists the nodes it holds through {@see children()}.
 */
interface Node
{
    /**
     * The name a node holds where the text should name something and does not,
     * as the ability in `can( for docs)`: what an analysis reads in place of the
     * name, having reported it. A name the text writes is never empty, so this
     * stands for none.
     */
    public const MISSING_NAME = '';

    /**
     * Every node directly inside this one, in the order it holds them.
     *
     * @return list<Node>
     */
    public function children(): array;
}
