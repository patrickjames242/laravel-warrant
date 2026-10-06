<?php

namespace Warrant\DSL\Parsing\ASTNodes;

/**
 * One entry in a rule body: a {@see WarrantRuleNode}, an {@see IncludeInvocationNode}
 * that expands into rules, or an {@see AbilityBlockNode} that groups both under one
 * ability header. A rule body is what a {@see RuleSetNode}, an ability block, a rule
 * template and an unscoped {@see WarrantSyntax} hold, and these are the only
 * things it can hold.
 *
 * The entries share one ordered list rather than sitting in lists of their own
 * because where an include was written is part of what it means. Expansion
 * splices the template's rules in at that position, so a reader that keeps only
 * "the rules, then the includes" has already lost the answer.
 */
interface IRuleEntryNode extends INode
{
}
