<?php

namespace Warrant\RuleSyntaxTree;

use InvalidArgumentException;
use OutOfBoundsException;
use Warrant\Facades\Warrant;

/**
 * Validates every condition and ability name in a {@see WarrantRuleSet} against
 * the schema it targets — including that each condition is called with at least
 * as many arguments as it requires. Runs before compilation so unknown names or
 * arity mistakes fail loudly rather than silently producing an empty predicate.
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
        foreach ($ruleSet->rules as $rule) {
            foreach ([...$rule->canAbilities, ...$rule->cannotAbilities()] as $ability) {
                if ($ability !== '*' && $this->schema->getAbilityDefinition($ability) === null) {
                    throw new InvalidArgumentException(
                        sprintf('Ability [%s] is not declared by the schema.', $ability)
                    );
                }
            }

            $this->assertNoDuplicateCannotAbility($rule);

            if ($rule->conditions !== null) {
                $this->validateConditionNames($rule->conditions);
            }
        }
    }

    /**
     * An ability may appear in at most one `cannot` clause of a rule. A duplicate
     * would give that ability two denial messages, of which only the first could
     * ever surface (see {@see WarrantRule::messageFor()}), so it is almost always
     * a mistake — reject it rather than silently dropping the later message.
     */
    private function assertNoDuplicateCannotAbility(WarrantRule $rule): void
    {
        $seen = [];

        foreach ($rule->cannotClauses as $clause) {
            foreach ($clause->abilities as $ability) {
                if (isset($seen[$ability])) {
                    throw new InvalidArgumentException(sprintf(
                        'Ability [%s] appears in more than one `they cannot ...` clause of the same rule; list it once.',
                        $ability,
                    ));
                }

                $seen[$ability] = true;
            }
        }
    }

    private function validateConditionNames(IBooleanExpressionNode $node): void
    {
        match (true) {
            $node instanceof ConditionNode => $this->assertConditionExists($node),
            $node instanceof CrossSchemaCanNode => $this->assertCrossSchemaCanValid($node),
            $node instanceof CrossSchemaConditionNode => $this->assertCrossSchemaConditionValid($node),
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

        if ((new $targetClass)->getAbilityDefinition($node->ability) === null) {
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

        // A specified row target must resolve to a value. A literal `null` (or a
        // `:name`/`?` binding that resolved to null) can never match a row, so it
        // is a mistake rather than a valid selector; reject it here. A `@context`
        // reference is a symbolic ContextRef, not null — its value is filled per
        // check, so its nullability stays a compile-time concern, not a static one.
        if ($node->isRowBound && $node->boundRow === null) {
            throw new InvalidArgumentException(sprintf(
                'A can(...) reference to schema [%s] specifies a row target that is null; supply a row id or a @context reference, or drop the row selector.',
                $node->schemaKey,
            ));
        }

        $this->assertColumnRefsResolve([$node->boundRow, ...array_values($node->contextMap)]);
    }

    /**
     * Validate a cross-schema `check(<predicate> for <schema>[(<row>)])` reference:
     * it must target another schema (never its own), that schema must be
     * registered, and a row-bound reference requires a model-backed target with a
     * non-null row. The predicate is a boolean expression whose every leaf must be
     * a condition declared by the *target* schema; on an unbound handle no leaf may
     * be a row condition (it would have no row to run against).
     */
    private function assertCrossSchemaConditionValid(CrossSchemaConditionNode $node): void
    {
        if ($node->schemaKey === $this->schemaKey) {
            throw new InvalidArgumentException(sprintf(
                'A check(...) reference cannot target its own schema [%s]; it may only reference other schemas.',
                $node->schemaKey,
            ));
        }

        try {
            $targetClass = Warrant::getSchemaForKey($node->schemaKey);
        } catch (OutOfBoundsException $e) {
            throw new InvalidArgumentException(
                sprintf('A check(...) reference targets unknown schema [%s].', $node->schemaKey),
                previous: $e,
            );
        }

        if ($node->isRowBound && $targetClass::model === '') {
            throw new InvalidArgumentException(sprintf(
                'A check(...) reference targets a specific row of schema [%s], but [%s] has no model and cannot be row-targeted; drop the row selector.',
                $node->schemaKey,
                $node->schemaKey,
            ));
        }

        // A specified row target must resolve to a value; a literal `null` (or a
        // binding that resolved to null) can never match a row. A `@context`
        // reference is a symbolic ContextRef, not null — filled per check — so its
        // nullability stays a compile-time concern, not a static one. (Same as can.)
        if ($node->isRowBound && $node->boundRow === null) {
            throw new InvalidArgumentException(sprintf(
                'A check(...) reference to schema [%s] specifies a row target that is null; supply a row id or a @context reference, or drop the row selector.',
                $node->schemaKey,
            ));
        }

        $this->assertColumnRefsResolve([$node->boundRow, ...array_values($node->contextMap)]);

        $this->assertCheckPredicateValid($node->predicate, $node, new $targetClass);
    }

    /**
     * Walk a `check(...)` predicate, asserting every leaf is a condition of the
     * target schema and rejecting any other node kind (a nested `can(...)` or
     * `check(...)`, or a constant boolean) — the predicate may only ask domain
     * questions of the target.
     */
    private function assertCheckPredicateValid(
        IBooleanExpressionNode $node,
        CrossSchemaConditionNode $reference,
        ConditionResolver $target,
    ): void {
        match (true) {
            $node instanceof ConditionNode => $this->assertCheckLeafValid($node, $reference, $target),
            $node instanceof NotNode => $this->assertCheckPredicateValid($node->operand, $reference, $target),
            $node instanceof AndNode, $node instanceof OrNode => (function () use ($node, $reference, $target): void {
                $this->assertCheckPredicateValid($node->leftSide, $reference, $target);
                $this->assertCheckPredicateValid($node->rightSide, $reference, $target);
            })(),
            default => throw new InvalidArgumentException(sprintf(
                'A check(...) predicate for schema [%s] may only reference that schema\'s conditions; it may not contain can(...) or a nested check(...).',
                $reference->schemaKey,
            )),
        };
    }

    /**
     * Validate one condition leaf of a `check(...)` predicate: it must be declared
     * by the target schema, and on an unbound handle it may not be a row condition.
     */
    private function assertCheckLeafValid(
        ConditionNode $node,
        CrossSchemaConditionNode $reference,
        ConditionResolver $target,
    ): void {
        $definition = $target->getConditionDefinition($node->conditionKey);

        if ($definition === null) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] is not declared by schema [%s].',
                $node->conditionKey,
                $reference->schemaKey,
            ));
        }

        if (! $reference->isRowBound && $definition->isRow) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] is a row condition and needs a specific row, but the check(...) handle is unbound; add a row selector like %s(@context id).',
                $node->conditionKey,
                $reference->schemaKey,
                $reference->schemaKey,
            ));
        }

        $this->assertEnoughArguments($node, $definition->requiredArgumentCount);
        $this->assertColumnRefsResolve($node->parameters);
    }

    private function assertConditionExists(ConditionNode $node): void
    {
        $definition = $this->schema->getConditionDefinition($node->conditionKey);

        if ($definition === null) {
            throw new InvalidArgumentException(
                sprintf('Condition [%s] is not declared by the schema.', $node->conditionKey)
            );
        }

        $this->assertEnoughArguments($node, $definition->requiredArgumentCount);
        $this->assertColumnRefsResolve($node->parameters);

        /* Context keys need no declaration: a rule may reference any `@context`
           key. An absent key simply makes its condition false at compile time
           (see RuleSetCompiler); required keys are enforced separately, at check
           time, via #[RequiredContext] and per-ability requires. */
    }

    /**
     * A condition may declare its DSL arguments as method parameters after the
     * leading context object; those without a default are required. Supplying
     * fewer arguments than required is a rule-level mistake, caught here before
     * compilation. (More arguments than parameters is allowed — the extras remain
     * reachable via the condition's `$c->arguments`.)
     */
    private function assertEnoughArguments(ConditionNode $node, int $required): void
    {
        $supplied = count($node->parameters);

        if ($supplied < $required) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] requires at least %d argument(s), but the rule supplied %d.',
                $node->conditionKey,
                $required,
                $supplied,
            ));
        }
    }

    /**
     * Eagerly validate every `@column <schema>.<column>` reference among a set of
     * argument values: its schema key must be registered and model-backed (so it
     * has a real table). This mirrors the compiler's resolution
     * ({@see RuleSetCompiler::resolveColumnRef}) so a bad reference fails loudly at
     * validation time, before compilation. Non-{@see ColumnRef} values are ignored.
     * The column name itself is not checked — there is no column introspection —
     * and a reference to the owning schema is allowed (unlike can(...)/check(...),
     * referencing your own table's column is the primary use case).
     *
     * @param array<int, mixed> $values
     */
    private function assertColumnRefsResolve(array $values): void
    {
        foreach ($values as $value) {
            if (! $value instanceof ColumnRef) {
                continue;
            }

            try {
                $targetClass = Warrant::getSchemaForKey($value->schemaKey);
            } catch (OutOfBoundsException $e) {
                throw new InvalidArgumentException(
                    sprintf('A @column reference targets unknown schema [%s].', $value->schemaKey),
                    previous: $e,
                );
            }

            if ($targetClass::model === '') {
                throw new InvalidArgumentException(sprintf(
                    'A @column reference targets schema [%s], which has no model and therefore no table; '
                        .'@column can only reference a model-backed schema.',
                    $value->schemaKey,
                ));
            }
        }
    }
}
