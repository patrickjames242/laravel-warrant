<?php

namespace Warrant\DSL\Expanding\Concerns;

use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Expanding\ExpansionTrail;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * How {@see Expander} expands a when block: its bodies are expanded into
 * rules, and the block's condition is put on every rule the `when` body
 * expands to, and its negation on every rule the `otherwise` body expands to.
 *
 * `otherwise` is a literal `not`, so when the condition is unknown neither body
 * has any effect: `not` of an unknown is unknown, and an unknown never grants
 * and never lifts a deny.
 *
 * @internal
 */
trait ExpandsWhenBlocks
{
    /**
     * The rules a when block stands for.
     *
     * The condition is expanded once, and that one instance goes on every rule
     * of the `when` body, as one `not` around it goes on every rule of the
     * `otherwise` body, so the rules a block stands for can be told to share
     * it. The `and` and `not` the block implies were written nowhere, so they
     * are placed in the block's source with no text of their own; the condition
     * keeps its place at the `when`.
     *
     * A block is grouping and nothing more, so it spends nothing of the trail.
     *
     * @return list<RuleNode>
     */
    private function expandWhenBlock(
        WhenBlockNode $block,
        ISchema $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): array {
        $implied = SyntaxLocation::builtIn($block->location->source);
        $condition = $this->expandExpression($block->condition, $schema, $schemaKey, $trail);

        $rules = self::guarded(
            $this->expandEntries($block->entries, $schema, $schemaKey, $trail),
            $condition,
            $implied,
        );

        if ($block->otherwiseEntries !== null) {
            array_push($rules, ...self::guarded(
                $this->expandEntries($block->otherwiseEntries, $schema, $schemaKey, $trail),
                new NotNode($condition, $implied),
                $implied,
            ));
        }

        return $rules;
    }

    /**
     * $rules, each holding only where $guard holds too: `$guard and (its own
     * condition)`, or $guard alone for a rule with none.
     *
     * @param list<RuleNode> $rules
     * @return list<RuleNode>
     */
    private static function guarded(array $rules, IBooleanExpressionNode $guard, SyntaxLocation $implied): array
    {
        return array_map(
            static fn (RuleNode $rule): RuleNode => new RuleNode(
                $rule->conditions === null ? $guard : new AndNode($guard, $rule->conditions, $implied),
                $rule->canClauses,
                $rule->cannotClauses,
                $rule->location,
            ),
            $rules,
        );
    }
}
