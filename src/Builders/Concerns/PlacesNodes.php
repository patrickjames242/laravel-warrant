<?php

namespace Warrant\Builders\Concerns;

use Warrant\DSL\Parsing\ASTNodes\Node;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxSource;

/**
 * How a builder makes the nodes it describes. A builder holds the values it was
 * given and makes no node until it is resolved, so every node it makes is
 * placed in the source of whatever resolves it: the syntax it is handed over in.
 */
trait PlacesNodes
{
    protected static function location(SyntaxSource $source): SyntaxLocation
    {
        return SyntaxLocation::builtIn($source);
    }

    protected static function nameNode(string $name, SyntaxSource $source): NameNode
    {
        return new NameNode($name, self::location($source));
    }

    /**
     * $value as an argument: itself when it is already a node, such as a
     * {@see \Warrant\Builders\Ref}, else a {@see LiteralNode} of it.
     */
    protected static function argumentNode(mixed $value, SyntaxSource $source): Node
    {
        return $value instanceof Node ? $value : new LiteralNode($value, self::location($source));
    }
}
