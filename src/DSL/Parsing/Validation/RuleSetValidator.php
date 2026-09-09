<?php

namespace Warrant\DSL\Parsing\Validation;

use InvalidArgumentException;
use OutOfBoundsException;
use Warrant\DSL\ConditionResolver;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Facades\Warrant;
use Warrant\Rules\WarrantRule;
use Warrant\Rules\WarrantRuleSet;

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
 * compiler, not this validator. That is also why a reference may target the
 * owning schema: whether it recurses is a question about rules this validator
 * cannot see.
 *
 * One check is not about names but about *scope*: a `@column` reference may only
 * name a table the rule can actually see from where it is written. That set grows
 * as the walk descends into a `check(...)` predicate, mirroring the
 * {@see \Warrant\DSL\Compiling\AliasScope} the compiler builds, so a reference
 * to an unrelated table is an error here rather than a SQL error at execution.
 */
final class RuleSetValidator
{
    /**
     * @param string $schemaKey The owning schema's key — the name its own rows go
     *   by in a `@column` reference.
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
                $this->validateExpression($rule->conditions, $this->schema, $this->rootInScopeNames());
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

    /**
     * The names a `@column` may use at the top of these rules: the owning schema's
     * own key, standing for the row being checked.
     *
     * A capability schema is the exception — it has no model and so no table, so
     * its own key names nothing and a `@column` in its rules has nothing in scope
     * at all. The vocabulary contract does not expose a model, so this asks the
     * richer {@see ConditionResolver} when it has one and otherwise assumes rows.
     *
     * @return list<string>
     */
    private function rootInScopeNames(): array
    {
        $modelless = $this->schema instanceof ConditionResolver
            && $this->schema::modelClass() === '';

        return $modelless ? [] : [$this->schemaKey];
    }

    /**
     * Walk one boolean expression, checking every name in it.
     *
     * The same walk serves a rule's own expression and a `check(...)` predicate,
     * which differ only in what they are *about*: a predicate's leaves belong to
     * the schema its handle names, and its frame may have no row. Both travel as
     * parameters, so descending into a predicate is the walk calling itself with a
     * different vocabulary rather than a second walk with its own rules.
     *
     * @param SchemaVocabulary $vocabulary Whose conditions and abilities the leaves
     *   of $node name.
     * @param list<string> $inScopeNames The tables in scope where $node is written;
     *   see {@see assertColumnRefsInScope}.
     * @param CrossSchemaConditionNode|null $predicateOf The `check(...)` whose
     *   predicate this is, or null for a rule's own expression.
     */
    private function validateExpression(
        IBooleanExpressionNode $node,
        SchemaVocabulary $vocabulary,
        array $inScopeNames,
        ?CrossSchemaConditionNode $predicateOf = null,
    ): void {
        match (true) {
            $node instanceof ConditionNode => $this->assertConditionValid($node, $vocabulary, $inScopeNames, $predicateOf),
            $node instanceof CrossSchemaCanNode => $this->assertCrossSchemaCanValid($node, $vocabulary, $inScopeNames),
            $node instanceof CrossSchemaConditionNode => $this->assertCrossSchemaConditionValid($node, $inScopeNames),
            $node instanceof NotNode => $this->validateExpression($node->operand, $vocabulary, $inScopeNames, $predicateOf),
            $node instanceof AndNode, $node instanceof OrNode => (function () use ($node, $vocabulary, $inScopeNames, $predicateOf): void {
                $this->validateExpression($node->leftSide, $vocabulary, $inScopeNames, $predicateOf);
                $this->validateExpression($node->rightSide, $vocabulary, $inScopeNames, $predicateOf);
            })(),
            /* A rule may be written around a constant; a predicate may not, because
               a `check(...)` that decides itself asks the target nothing. */
            default => $predicateOf === null ? null : throw new InvalidArgumentException(sprintf(
                'A check(...) predicate for schema [%s] may not contain a constant; it has to ask that '
                    .'schema something.',
                $predicateOf->schemaKey,
            )),
        };
    }

    /**
     * Validate a cross-schema `can(<ability> for <schema>[(<row>)] [as <alias>])`
     * reference: the target schema must be registered, the ability must be
     * declared by it, a row-bound reference requires a model-backed target (a
     * capability schema has no row to target), and an alias requires a row to
     * name.
     *
     * The target may be this schema itself. Two frames over one table are told
     * apart by naming the hop's rows (`as d2`), and recursion is bounded by
     * {@see \Warrant\DSL\Compiling\CallStack}, which rejects re-entering an
     * ability already in progress and caps depth for everything else — so a
     * self-reference has to name a *different* ability to compile at all.
     */
    private function assertCrossSchemaCanValid(
        CrossSchemaCanNode $node,
        SchemaVocabulary $vocabulary,
        array $inScopeNames,
    ): void {
        if ($node->schemaKey === null) {
            $this->assertOwnAbilityValid($node, $vocabulary);

            return;
        }

        try {
            $targetClass = Warrant::registry()->resolveSchemaClassOrFail($node->schemaKey);
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

        $this->assertAliasHasARow('can', $node->schemaKey, $node->isRowBound, $node->alias);

        /* The handle's own arguments are written in the enclosing rule, so they see
           the enclosing scope. The target's *rules* are not validated here at all —
           they are validated against their own schema, with their own scope. */
        $this->assertColumnRefsInScope([$node->boundRow, ...array_values($node->contextMap)], $inScopeNames);
    }

    /**
     * Validate a cross-schema
     * `check(<predicate> for <schema>[(<row>)] [as <alias>])` reference: the
     * target schema must be registered, a row-bound reference requires a
     * model-backed target with a non-null row, and an alias requires a row to
     * name. The predicate is a boolean expression read against the *target*
     * schema's vocabulary — so a `can(...)` in it names one of that schema's
     * abilities, and a nested `check(...)` starts from that schema's frame. An
     * unbound handle constrains the predicate's leaves not at all: a row condition
     * among them has no row and so no answer, which the compiler says with an
     * unknown rather than an error (see {@see assertConditionValid}).
     *
     * As with `can(...)` the target may be this schema itself.
     */
    private function assertCrossSchemaConditionValid(CrossSchemaConditionNode $node, array $inScopeNames): void
    {
        try {
            $targetClass = Warrant::registry()->resolveSchemaClassOrFail($node->schemaKey);
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

        $this->assertAliasHasARow('check', $node->schemaKey, $node->isRowBound, $node->alias);

        $this->assertColumnRefsInScope([$node->boundRow, ...array_values($node->contextMap)], $inScopeNames);

        /* Unlike a can(...), the predicate is written right here, in the enclosing
           rule — so it keeps the enclosing scope and gains the target's frame on
           top, under its alias when it has one, exactly as the compiler's
           AliasScope does. Aliasing the target therefore leaves its schema key
           still meaning the enclosing frame, which is how a predicate over two
           frames of one table tells them apart. A target with no model has no
           table to add. */
        $this->validateExpression(
            $node->predicate,
            new $targetClass,
            $targetClass::model === ''
                ? $inScopeNames
                : [...$inScopeNames, $node->alias ?? $node->schemaKey],
            $node,
        );
    }

    /**
     * Validate a `can(<ability>)` with no `for` clause: the ability has to be one
     * the frame's own schema declares, and nothing may be passed across a boundary
     * that is not being crossed.
     *
     * The registry is not consulted at all. There is no schema key to look up —
     * the reference stays on whichever schema the expression is about, whose
     * vocabulary arrives as $vocabulary. Inside a `check(...)` predicate that is
     * the schema the handle named, not the one the rule is written on.
     */
    private function assertOwnAbilityValid(CrossSchemaCanNode $node, SchemaVocabulary $vocabulary): void
    {
        if ($vocabulary->getAbilityDefinition($node->ability) === null) {
            throw new InvalidArgumentException(sprintf(
                'Ability [%s] is not declared by the schema.',
                $node->ability,
            ));
        }

        if ($node->contextMap !== []) {
            throw new InvalidArgumentException(sprintf(
                'A can(%s) reference carries the context it is already in, so it takes no `with` map; '
                    .'name a schema with `for` if you meant to cross to one.',
                $node->ability,
            ));
        }

        if ($node->alias !== null) {
            throw new InvalidArgumentException(sprintf(
                'A can(%s) reference selects no rows of its own, so there is nothing for [as %s] to name; '
                    .'name a schema with `for` if you meant to cross to one.',
                $node->ability,
                $node->alias,
            ));
        }
    }

    /**
     * An `as <alias>` names the rows a reference selects. An unbound handle selects
     * none — it emits no `from` at all — so an alias there names nothing, and is
     * far more likely a forgotten row selector than a deliberate no-op.
     */
    private function assertAliasHasARow(
        string $builtin,
        string $schemaKey,
        bool $isRowBound,
        ?string $alias,
    ): void {
        if ($alias === null || $isRowBound) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'A %s(...) reference to schema [%s] is aliased [as %s] but selects no row, so the alias names '
                .'nothing; add a row selector like %s(@context id) as %s, or drop the alias.',
            $builtin,
            $schemaKey,
            $alias,
            $schemaKey,
            $alias,
        ));
    }

    /**
     * Validate one condition leaf: the vocabulary it is being read against has to
     * declare it, it has to be called with at least the arguments it requires, and
     * its `@column` references have to name tables in scope.
     *
     * Whether the leaf is a row condition is deliberately not checked, even under
     * a handle that selects no row. A row condition with no row has an undefined
     * answer, and the compiler gives it one — an unknown, which neither grants nor
     * lifts a deny. Rejecting it instead would make a predicate's legality depend
     * on which of its leaves happen to be row conditions, so
     * `check(is_advisor or is_owner for folders)` would be an error over its second
     * leaf alone. Row-ness is a schema's own implementation detail, invisible in
     * the rule text and free to change, so no rule is written against it.
     *
     * @param list<string> $inScopeNames
     */
    private function assertConditionValid(
        ConditionNode $node,
        SchemaVocabulary $vocabulary,
        array $inScopeNames,
        ?CrossSchemaConditionNode $predicateOf,
    ): void {
        $definition = $vocabulary->getConditionDefinition($node->conditionKey);

        if ($definition === null) {
            throw new InvalidArgumentException($predicateOf === null
                ? sprintf('Condition [%s] is not declared by the schema.', $node->conditionKey)
                : sprintf(
                    'Condition [%s] is not declared by schema [%s].',
                    $node->conditionKey,
                    $predicateOf->schemaKey,
                ));
        }

        $this->assertEnoughArguments($node, $definition->requiredArgumentCount);
        $this->assertColumnRefsInScope($node->parameters, $inScopeNames);

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
     * Eagerly validate every qualified `@column <name>.<column>` reference among a
     * set of argument values: the name must be one of the tables in scope where
     * the reference is written — the owning schema's own key, or a frame a
     * surrounding `check(...)` put in scope.
     *
     * An *unqualified* `@column <column>` names no frame, so there is nothing here
     * to be wrong about: it means whichever rows the enclosing rule is already
     * about, which is settled per compile rather than per rule. Whether those rows
     * are in scope at all is a compile-time question — a no-target compile has
     * none — and the compiler answers it by folding the leaf to unknown, not by
     * erroring, because the same rule works wherever a row *is* in scope.
     *
     * This mirrors what {@see \Warrant\DSL\Compiling\AliasScope} resolves at
     * compile time, message and all, so a reference that cannot work fails here
     * rather than emitting SQL about a table the query never joined. Referencing
     * your own rows is the ordinary case and always allowed (unlike
     * `can(...)`/`check(...)`, whose whole point is to leave the schema). The column
     * name itself is not checked — there is no column introspection.
     * Non-{@see ColumnRef} values are ignored.
     *
     * @param array<int, mixed> $arguments
     * @param list<string> $inScopeNames The tables in scope, in the order they came
     *   into scope.
     */
    private function assertColumnRefsInScope(array $arguments, array $inScopeNames): void
    {
        foreach ($arguments as $argument) {
            if (! $argument instanceof ColumnRef) {
                continue;
            }

            if ($argument->alias === null) {
                continue;
            }

            if (in_array($argument->alias, $inScopeNames, true)) {
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'A @column reference names [%s], which is not in scope here; %s',
                $argument->alias,
                $inScopeNames === []
                    ? 'no table is in scope at this point.'
                    : sprintf('the names in scope are [%s].', implode(', ', $inScopeNames)),
            ));
        }
    }
}
