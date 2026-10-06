<?php

namespace Warrant\DSL\Expanding;

use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use RuntimeException;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Parsing\WarrantSyntaxException;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Facades\Warrant;
use Warrant\Rules\WarrantRuleTemplate;
use Warrant\Schema\ConditionDefinition;
use Warrant\Schema\RuleTemplateDefinition;

/**
 * The expansion phase, between parsing and compiling: turns the rule set an
 * author wrote into the {@see ExpandedRuleSet} everything downstream reads.
 *
 * Parsing keeps the author's shorthand. Expansion spells it out:
 *
 *  - every ability block's header is applied to the clauses under it;
 *  - every {@see IncludeInvocationNode} is replaced by the rules its template
 *    expands to, where the include was written — position is meaning;
 *  - every derived condition a rule names is replaced by the expression its
 *    method answers with, wrapped in a {@see DerivedConditionNode}.
 *
 * Expansion is a pure function of the rule set and the schemas it names. A
 * template or derived condition method is called with the arguments as written —
 * a `@context` or `@column` reference arrives as the reference, not its value, and
 * can only be passed on — and answers with text or an expression about no row. It
 * reads no user, no row, no query and no check context, so a rule set is expanded
 * once and the result serves every check, filter, diagnosis and reachability
 * question asked of it.
 *
 * Names inside a `check(<predicate> for <schema>)` belong to that schema, so the
 * predicate is expanded against its vocabulary. A `can(<ability> for <schema>)`
 * is left as it is: the rules it reaches are resolved per user, and are expanded
 * when that schema's own guard is asked for them.
 *
 * A template may include another, and a derived condition may name another,
 * either of them itself with different arguments, so expansion recurses, bounded
 * by an {@see ExpansionTrail}.
 */
final class RuleSetExpander
{
    /**
     * Expand $set into rules alone: ability blocks opened up, every include
     * replaced by what its template expands to, and every derived condition by
     * the expression it answers with.
     */
    public function expand(RuleSetNode $set, SchemaVocabulary $schema): ExpandedRuleSet
    {
        return new ExpandedRuleSet($set->schemaKey, $this->expandEntries(
            $set->entries,
            $schema,
            $set->schemaKey,
            ExpansionTrail::root(),
        ));
    }

    /**
     * @param list<IRuleEntryNode> $entries
     * @return list<WarrantRuleNode>
     */
    private function expandEntries(
        array $entries,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): array
    {
        $expanded = [];

        foreach ($entries as $entry) {
            $rules = match (true) {
                $entry instanceof AbilityBlockNode => $this->expandAbilityBlock($entry, $schema, $schemaKey, $trail),
                $entry instanceof IncludeInvocationNode => $this->expandInclude($entry, $schema, $schemaKey, $trail),
                default => [$this->expandRule($entry, $schema, $schemaKey, $trail)],
            };

            foreach ($rules as $rule) {
                $expanded[] = $rule;
            }
        }

        return $expanded;
    }

    /**
     * The rules an ability block stands for: its header applied to every headless
     * entry in its body, and those entries expanded in turn.
     *
     * A block is grouping and nothing more, so it spends nothing of the trail.
     *
     * @return list<WarrantRuleNode>
     */
    private function expandAbilityBlock(
        AbilityBlockNode $block,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): array {
        $entries = array_map(
            static fn (WarrantRuleNode|IncludeInvocationNode $entry): WarrantRuleNode|IncludeInvocationNode
                => $entry->withAbilities($block->abilities),
            $block->entries,
        );

        return $this->expandEntries($entries, $schema, $schemaKey, $trail);
    }

    /**
     * @return list<WarrantRuleNode>
     */
    private function expandInclude(
        IncludeInvocationNode $include,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): array {
        $deeper = $trail->enteringInclude($include);

        $definition = self::resolveTemplate($schema, $schemaKey, $include);

        $body = $schema->{$definition->methodName}(...$include->arguments);

        if (! is_string($body) && ! $body instanceof WarrantRuleTemplate) {
            throw new RuntimeException(sprintf(
                'Rule template [%s::%s] must answer with a string or a %s, got %s.',
                $schema::class,
                $definition->methodName,
                WarrantRuleTemplate::class,
                get_debug_type($body),
            ));
        }

        /* A plain string is a body with nothing to fill. */
        if (is_string($body)) {
            $body = new WarrantRuleTemplate($body);
        }

        $headless = WarrantParser::parseTemplateBody($body->syntax, $body->bindings);

        /* The body is headless, as an ability block's is: the include names the
           abilities its clauses take, so they are applied here. */
        $entries = array_map(
            static fn (WarrantRuleNode|IncludeInvocationNode $entry): WarrantRuleNode|IncludeInvocationNode
                => $entry->withAbilities($include->abilities),
            $headless,
        );

        /* The body may hold includes of its own, taking the same abilities. They
           are expanded here rather than left for the caller so that what comes
           back is rules and only rules, however deep the templates went. */
        return $this->expandEntries($entries, $schema, $schemaKey, $deeper);
    }

    /**
     * $rule with the derived conditions in its condition expanded. A rule with
     * nothing to expand comes back as the same instance.
     */
    private function expandRule(
        WarrantRuleNode $rule,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): WarrantRuleNode {
        if ($rule->conditions === null) {
            return $rule;
        }

        $conditions = $this->expandExpression($rule->conditions, $schema, $schemaKey, $trail);

        return $conditions === $rule->conditions
            ? $rule
            : new WarrantRuleNode($conditions, $rule->canClauses, $rule->cannotClauses);
    }

    /**
     * $node with every derived condition in it expanded, the names read against
     * $schema. A subtree with nothing to expand comes back as the same instance.
     */
    private function expandExpression(
        IBooleanExpressionNode $node,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): IBooleanExpressionNode {
        if ($node instanceof AndNode || $node instanceof OrNode) {
            $left = $this->expandExpression($node->leftSide, $schema, $schemaKey, $trail);
            $right = $this->expandExpression($node->rightSide, $schema, $schemaKey, $trail);

            return $left === $node->leftSide && $right === $node->rightSide
                ? $node
                : new ($node::class)($left, $right);
        }

        if ($node instanceof NotNode) {
            $operand = $this->expandExpression($node->operand, $schema, $schemaKey, $trail);

            return $operand === $node->operand ? $node : new NotNode($operand);
        }

        if ($node instanceof CrossSchemaConditionNode) {
            return $this->expandCheckPredicate($node, $trail);
        }

        if ($node instanceof ConditionNode) {
            $definition = $schema->getConditionDefinition($node->conditionKey);

            /* A name the schema does not declare is left for validation and the
               compiler to report, in the words they use for any other. */
            return $definition !== null && $definition->isDerived()
                ? $this->expandDerivedCondition($node, $definition, $schema, $schemaKey, $trail)
                : $node;
        }

        return $node;
    }

    /**
     * A `check(...)` with its predicate expanded against the schema it names,
     * whose conditions those are.
     */
    private function expandCheckPredicate(CrossSchemaConditionNode $node, ExpansionTrail $trail): CrossSchemaConditionNode
    {
        /* A schema nobody registered has no vocabulary to expand against. Leaving
           the reference as it is lets the validator and the compiler reject it with
           the message they give for any unknown schema. */
        try {
            $targetClass = Warrant::registry()->resolveSchemaClassOrFail($node->schemaKey);
        } catch (OutOfBoundsException) {
            return $node;
        }

        $predicate = $this->expandExpression($node->predicate, new $targetClass, $node->schemaKey, $trail);

        return $predicate === $node->predicate ? $node : new CrossSchemaConditionNode(
            $node->schemaKey,
            $predicate,
            $node->isRowBound,
            $node->boundKey,
            $node->contextMap,
            $node->alias,
        );
    }

    /**
     * Call a derived condition with the arguments the rule gave it, and wrap the
     * expanded expression it answers with.
     */
    private function expandDerivedCondition(
        ConditionNode $node,
        ConditionDefinition $definition,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): DerivedConditionNode {
        $deeper = $trail->enteringDerivedCondition($schemaKey, $node->conditionKey);

        if (count($node->parameters) < $definition->requiredArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] requires at least %d argument(s), but the rule supplied %d.',
                $node->conditionKey,
                $schema::class,
                $definition->requiredArgumentCount,
                count($node->parameters),
            ));
        }

        $answer = $schema->{$definition->methodName}(...$node->parameters);

        return new DerivedConditionNode(
            $node->conditionKey,
            $node->parameters,
            $this->expandExpression(
                $this->derivedExpression($answer, $node->conditionKey, $schema, $definition),
                $schema,
                $schemaKey,
                $deeper,
            ),
        );
    }

    /**
     * What a derived condition answered with, as an expression node.
     *
     * A string is rule text, parsed as a condition expression. A
     * {@see WarrantConditionBuilder} is unwrapped to the tree it composed. A bool
     * decides the outcome outright, and null answers unknown.
     */
    private function derivedExpression(
        mixed $answer,
        string $conditionKey,
        SchemaVocabulary $schema,
        ConditionDefinition $definition,
    ): IBooleanExpressionNode {
        if ($answer instanceof WarrantConditionBuilder) {
            /* A builder with no terms is the structural twin of a condition that
               added no where clause: it would mean "match everything", which is
               almost always a forgotten branch rather than an intent. */
            return $answer->buildConditions() ?? throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] returned a condition builder with no terms, which would '
                    .'silently match every row; add at least one term, return true/false to decide '
                    .'the outcome outright, or return null to answer unknown.',
                $conditionKey,
                $schema::class,
            ));
        }

        return match (true) {
            $answer instanceof IBooleanExpressionNode => $answer,
            is_string($answer) => $this->parseDerivedText($answer, $schema, $definition),
            is_bool($answer) => new BooleanNode($answer),
            $answer === null => new UnknownNode,
            default => throw new RuntimeException(sprintf(
                'Derived condition [%s::%s] must answer with an expression, a %s, rule text, a bool or null; '
                    .'got %s.',
                $schema::class,
                $definition->methodName,
                WarrantConditionBuilder::class,
                get_debug_type($answer),
            )),
        };
    }

    /**
     * The rule text a derived condition answered with, parsed as a condition
     * expression.
     *
     * Text that does not parse, or parses to something other than an expression,
     * is the condition's mistake, but the parser can only point at a position in
     * the text. Naming the method that answered with it is what makes it findable.
     */
    private function parseDerivedText(string $text, SchemaVocabulary $schema, ConditionDefinition $definition): IBooleanExpressionNode
    {
        try {
            return WarrantSyntax::parse($text)->conditionExpression();
        } catch (WarrantSyntaxException|LogicException $e) {
            throw new RuntimeException(sprintf(
                "Derived condition [%s::%s] answered with rule text that is not a condition expression:\n\n%s",
                $schema::class,
                $definition->methodName,
                $e->getMessage(),
            ), previous: $e);
        }
    }

    /**
     * The template an include names, rejecting one the schema does not declare and
     * one the include gives too few arguments.
     */
    private static function resolveTemplate(
        SchemaVocabulary $schema,
        string $schemaKey,
        IncludeInvocationNode $include,
    ): RuleTemplateDefinition {
        $definition = $schema->getRuleTemplateDefinition($include->templateKey);

        if ($definition === null) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] declares no rule template [%s], named by an @include.',
                $schemaKey,
                $include->templateKey,
            ));
        }

        if (count($include->arguments) < $definition->requiredArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'Rule template [%s] requires %d argument(s), but the @include supplies %d.',
                $include->templateKey,
                $definition->requiredArgumentCount,
                count($include->arguments),
            ));
        }

        return $definition;
    }
}
