<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A symbolic reference to a check-time context value, written `@context <key>`
 * in a rule. Unlike inline literals and `:name` / `?` bindings — which are
 * concrete values once the syntax is resolved — a context ref stays symbolic in
 * the compiled AST (inside {@see ConditionNode::$parameters}) and is filled per
 * check from the context bag by {@see RuleSetCompiler}.
 */
readonly class ContextRef implements INode
{
    public function __construct(public NameNode $key, public SyntaxLocation $location) {}
}
