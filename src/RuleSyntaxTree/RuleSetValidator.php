<?php

namespace Warrant\RuleSyntaxTree;

use InvalidArgumentException;

/**
 * Validates every condition and ability name in a {@see WarrantRuleSet} against
 * the schema it targets. Runs before compilation so unknown names fail loudly
 * rather than silently producing an empty predicate.
 *
 * Depends only on the schema's {@see SchemaVocabulary} — name existence, no SQL.
 */
final class RuleSetValidator
{
    public function __construct(private readonly SchemaVocabulary $schema)
    {
    }

    /**
     * Validate every condition and ability name in the rule set against the
     * schema. Throws {@see InvalidArgumentException} on the first unknown name.
     */
    public function validate(WarrantRuleSet $ruleSet): void
    {
        $declaredAbilities = $this->schema->declaredAbilities();

        foreach ($ruleSet->rules as $rule) {
            foreach ([...$rule->canAbilities, ...$rule->cannotAbilities] as $ability) {
                if ($ability !== '*' && ! in_array($ability, $declaredAbilities, true)) {
                    throw new InvalidArgumentException(
                        sprintf('Ability [%s] is not declared by the schema.', $ability)
                    );
                }
            }

            if ($rule->conditions !== null) {
                $this->validateConditionNames($rule->conditions);
            }
        }
    }

    private function validateConditionNames(IBooleanExpressionNode $node): void
    {
        match (true) {
            $node instanceof ConditionNode => $this->assertConditionExists($node),
            $node instanceof NotNode => $this->validateConditionNames($node->operand),
            $node instanceof AndNode, $node instanceof OrNode => (function () use ($node): void {
                $this->validateConditionNames($node->leftSide);
                $this->validateConditionNames($node->rightSide);
            })(),
            default => null,
        };
    }

    private function assertConditionExists(ConditionNode $node): void
    {
        if (! $this->schema->conditionExists($node->conditionKey)) {
            throw new InvalidArgumentException(
                sprintf('Condition [%s] is not declared by the schema.', $node->conditionKey)
            );
        }

        $declaredContextKeys = $this->schema::declaredContextKeys();

        foreach ($node->parameters as $parameter) {
            if ($parameter instanceof ContextRef
                && ! in_array($parameter->key, $declaredContextKeys, true)) {
                throw new InvalidArgumentException(
                    sprintf('Context key [%s] is not declared by the schema.', $parameter->key)
                );
            }
        }
    }
}
