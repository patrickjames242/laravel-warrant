<?php

namespace Warrant\RuleSyntaxTree;

use InvalidArgumentException;
use OutOfBoundsException;
use Warrant\Facades\Warrant;

/**
 * Validates every condition and ability name in a {@see WarrantRuleSet} against
 * the schema it targets. Runs before compilation so unknown names fail loudly
 * rather than silently producing an empty predicate.
 *
 * Own-schema checks depend only on the schema's {@see SchemaVocabulary} — name
 * existence, no SQL. A cross-schema `can(...)` reference is additionally resolved
 * against the registry (by schema key) to confirm the target schema and ability
 * exist; the referenced schema's *rules* are never consulted here (they are
 * per-user and resolver-owned), so cycle detection is deliberately left to the
 * compiler, not this validator.
 */
final class RuleSetValidator
{
    /**
     * @param string $schemaKey The owning schema's key, used to reject a
     *   `can(...)` that references its own schema (cross-schema references only).
     */
    public function __construct(
        private readonly SchemaVocabulary $schema,
        private readonly string $schemaKey,
    ) {
    }

    /**
     * Validate every condition and ability name in the rule set against the
     * schema. Throws {@see InvalidArgumentException} on the first unknown name.
     */
    public function validate(WarrantRuleSet $ruleSet): void
    {
        $abilityNames = $this->schema->abilityNames();

        foreach ($ruleSet->rules as $rule) {
            foreach ([...$rule->canAbilities, ...$rule->cannotAbilities] as $ability) {
                if ($ability !== '*' && ! in_array($ability, $abilityNames, true)) {
                    throw new InvalidArgumentException(
                        sprintf('Ability [%s] is not declared by the schema.', $ability)
                    );
                }
            }

            // A denial message is only ever surfaced for a matching `cannot`; on a
            // rule with no `cannot` clause it can never fire, so reject it loudly
            // rather than silently doing nothing.
            if ($rule->message !== null && $rule->cannotAbilities === []) {
                throw new InvalidArgumentException(
                    'A denial message requires a `they cannot ...` clause; it can never be surfaced by a rule that only grants.'
                );
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
            $node instanceof CrossSchemaCanNode => $this->assertCrossSchemaCanValid($node),
            $node instanceof NotNode => $this->validateConditionNames($node->operand),
            $node instanceof AndNode, $node instanceof OrNode => (function () use ($node): void {
                $this->validateConditionNames($node->leftSide);
                $this->validateConditionNames($node->rightSide);
            })(),
            default => null,
        };
    }

    /**
     * Validate a cross-schema `can(<ability> for <schema>[(<row>)])` reference:
     * it must target another schema (never its own), that schema must be
     * registered, the ability must be declared by it, and a row-bound reference
     * requires a model-backed target (a capability schema has no row to target).
     */
    private function assertCrossSchemaCanValid(CrossSchemaCanNode $node): void
    {
        if ($node->schemaKey === $this->schemaKey) {
            throw new InvalidArgumentException(sprintf(
                'A can(...) reference cannot target its own schema [%s]; it may only reference other schemas.',
                $node->schemaKey,
            ));
        }

        try {
            $targetClass = Warrant::getSchemaForKey($node->schemaKey);
        } catch (OutOfBoundsException $e) {
            throw new InvalidArgumentException(
                sprintf('A can(...) reference targets unknown schema [%s].', $node->schemaKey),
                previous: $e,
            );
        }

        if (! in_array($node->ability, $targetClass::abilityNames(), true)) {
            throw new InvalidArgumentException(sprintf(
                'Ability [%s] is not declared by schema [%s].',
                $node->ability,
                $node->schemaKey,
            ));
        }

        if ($node->isRowBound && $targetClass::model === '') {
            throw new InvalidArgumentException(sprintf(
                'A can(...) reference targets a specific row of schema [%s], but [%s] has no model and cannot be row-targeted; drop the row selector.',
                $node->schemaKey,
                $node->schemaKey,
            ));
        }
    }

    private function assertConditionExists(ConditionNode $node): void
    {
        if (! $this->schema->conditionExists($node->conditionKey)) {
            throw new InvalidArgumentException(
                sprintf('Condition [%s] is not declared by the schema.', $node->conditionKey)
            );
        }

        /* Context keys need no declaration: a rule may reference any `@context`
           key. An absent key simply makes its condition false at compile time
           (see RuleSetCompiler); required keys are enforced separately, at check
           time, via #[RequiredContext] and per-ability requires. */
    }
}
