<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A symbolic reference to an arbitrary SQL fragment, written `@sql "<sql>"` in a
 * rule (e.g. `@sql "select id from pay_periods where closed = 0"`). Like
 * {@see ColumnRef} it stays symbolic in the compiled AST — inside
 * {@see ConditionNode::$parameters}, a cross-schema handle's row selector, or a
 * `with` map value — rather than being resolved to a value as a binding is.
 *
 * It resolves late, in {@see RuleSetCompiler}, to an
 * {@see \Illuminate\Database\Query\Expression} holding the author's SQL wrapped in
 * a single pair of parentheses (`(<sql>)`), so a bare `select ...` is valid as a
 * scalar subquery and a condition can splice it straight into the query builder
 * without it being re-quoted or bound as a value. Unlike a column ref it needs
 * neither the schema registry nor the query grammar: the body is emitted verbatim.
 * The SQL is entirely the rule author's responsibility (table scoping, injection).
 */
readonly class SqlRef implements Node
{
    /**
     * @param LiteralNode|BindingNode $sql The SQL as a string literal, or the
     *   binding that supplies it until expansion puts the string in its place.
     */
    public function __construct(public LiteralNode|BindingNode $sql, public SyntaxLocation $location) {}

    public function children(): array
    {
        return [$this->sql];
    }
}
