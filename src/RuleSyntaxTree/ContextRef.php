<?php

namespace Warrant\RuleSyntaxTree;

/**
 * A symbolic reference to a check-time context value, written `@context <key>`
 * in a rule. Unlike inline literals and `:name` / `?` bindings — which are
 * resolved into concrete values at parse time — a context ref stays symbolic in
 * the compiled AST (inside {@see ConditionNode::$parameters}) and is filled per
 * check from the context bag by {@see RuleSetCompiler}.
 */
readonly class ContextRef
{
    public function __construct(public string $key) {}
}
