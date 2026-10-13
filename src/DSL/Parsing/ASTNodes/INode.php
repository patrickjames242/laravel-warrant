<?php

namespace Warrant\DSL\Parsing\ASTNodes;

interface INode
{
    /**
     * The name a node holds where the text should name something and does not,
     * as the ability in `can( for docs)`: what an analysis reads in place of the
     * name, having reported it. A name the text writes is never empty, so this
     * stands for none.
     */
    public const MISSING_NAME = '';
}
