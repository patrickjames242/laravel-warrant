<?php

namespace Warrant\DSL\Parsing\Validation;

use InvalidArgumentException;
use OutOfBoundsException;
use Warrant\DSL\Compiling\AliasScope;
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
 * as many arguments as it requires.
 *
 * ## What this is for
 *
 * Early feedback, not safety. A name that resolves to nothing is rejected by
 * {@see \Warrant\DSL\Compiling\RuleSetCompiler} at the lookup that needs it, so
 * nothing depends on this class having run: skip it and a broken rule still fails
 * loudly, just later and against a live user and query.
 *
 * What it adds is reach in the other direction. It answers from rule text alone —
 * no database, no user, no query — which is what a language server, a CI check or
 * {@see WarrantRuleSet::validate()} needs, and it sees every rule in a set rather
 * than only the paths a particular check happens to compile.
 *
 * Its blind spot is the mirror of that. A condition may answer with an expression
 * instead of SQL, and that expression exists only once the condition has run, so
 * no amount of reading rule text will find a mistake inside one. The compiler is
 * the only place such a tree can be checked, which is why correctness lives there
 * and this class is free to be a pass an author can forget to run.
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
                $this->validateExpression($rule->conditions, $this->schema, $this->rootScope());
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
     * The scope at the top of these rules: the owning schema's own key, standing
     * for the row being checked, bound to no qualifier because no query is in
     * hand here.
     *
     * A capability schema is the exception — it has no model and so no table, so
     * its own key names nothing and a `@column` in its rules has nothing in scope
     * at all. The vocabulary contract does not expose a model, so this asks the
     * richer {@see ConditionResolver} when it has one and otherwise assumes rows.
     */
    private function rootScope(): AliasScope
    {
        $rowless = ! $this->schema::hasRows();

        return $rowless ? AliasScope::none() : AliasScope::root($this->schemaKey, null);
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
     * @param AliasScope $scope The frames in scope where $node is written; see
     *   {@see assertColumnRefsInScope}. Every qualifier in it is null — nothing is
     *   selected until a compile, and only the names matter here.
     * @param CrossSchemaConditionNode|null $predicateOf The `check(...)` whose
     *   predicate this is, or null for a rule's own expression.
     */
    private function validateExpression(
        IBooleanExpressionNode $node,
        SchemaVocabulary $vocabulary,
        AliasScope $scope,
        ?CrossSchemaConditionNode $predicateOf = null,
    ): void {
        match (true) {
            $node instanceof ConditionNode => $this->assertConditionValid($node, $vocabulary, $scope, $predicateOf),
            $node instanceof CrossSchemaCanNode => $this->assertCrossSchemaCanValid($node, $vocabulary, $scope),
            $node instanceof CrossSchemaConditionNode => $this->assertCrossSchemaConditionValid($node, $scope),
            $node instanceof NotNode => $this->validateExpression($node->operand, $vocabulary, $scope, $predicateOf),
            $node instanceof AndNode, $node instanceof OrNode => (function () use ($node, $vocabulary, $scope, $predicateOf): void {
                $this->validateExpression($node->leftSide, $vocabulary, $scope, $predicateOf);
                $this->validateExpression($node->rightSide, $vocabulary, $scope, $predicateOf);
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
        AliasScope $scope,
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

        if ($node->isRowBound && ! $targetClass::hasRows()) {
            throw new InvalidArgumentException(sprintf(
                'A can(...) reference targets a specific row of schema [%s], but [%s] has no rows and cannot be row-targeted; drop the row selector.',
                $node->schemaKey,
                $node->schemaKey,
            ));
        }

        $this->assertTargetCanNameARow('can', $node->schemaKey, $targetClass, $node->isRowBound);
        $this->assertKeyArityIsSatisfied('can', $node->schemaKey, $targetClass, $node->isRowBound, $node->boundKey);

        // Every argument of a specified row target must resolve to a value. A
        // literal `null` (or a `:name`/`?` binding that resolved to null) can
        // never match a row, so it is a mistake rather than a valid selector;
        // reject it here, in any position. A `@context` reference is a symbolic
        // ContextRef, not null — its value is filled per check, so its
        // nullability stays a compile-time concern, not a static one.
        if ($node->isRowBound && in_array(null, $node->boundKey, true)) {
            throw new InvalidArgumentException(sprintf(
                'A can(...) reference to schema [%s] specifies a row target that is null; supply a row id or a @context reference, or drop the row selector.',
                $node->schemaKey,
            ));
        }

        $this->assertAliasHasARow('can', $node->schemaKey, $node->isRowBound, $node->alias);

        /* The handle's own arguments are written in the enclosing rule, so they see
           the enclosing scope. The target's *rules* are not validated here at all —
           they are validated against their own schema, with their own scope. */
        $this->assertColumnRefsInScope([...$node->boundKey, ...array_values($node->contextMap)], $scope);
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
    private function assertCrossSchemaConditionValid(CrossSchemaConditionNode $node, AliasScope $scope): void
    {
        try {
            $targetClass = Warrant::registry()->resolveSchemaClassOrFail($node->schemaKey);
        } catch (OutOfBoundsException $e) {
            throw new InvalidArgumentException(
                sprintf('A check(...) reference targets unknown schema [%s].', $node->schemaKey),
                previous: $e,
            );
        }

        if ($node->isRowBound && ! $targetClass::hasRows()) {
            throw new InvalidArgumentException(sprintf(
                'A check(...) reference targets a specific row of schema [%s], but [%s] has no rows and cannot be row-targeted; drop the row selector.',
                $node->schemaKey,
                $node->schemaKey,
            ));
        }

        $this->assertTargetCanNameARow('check', $node->schemaKey, $targetClass, $node->isRowBound);
        $this->assertKeyArityIsSatisfied('check', $node->schemaKey, $targetClass, $node->isRowBound, $node->boundKey);

        // Every argument of a specified row target must resolve to a value; a
        // literal `null` (or a binding that resolved to null) can never match a
        // row, in any position. A `@context` reference is a symbolic ContextRef,
        // not null — filled per check — so its nullability stays a compile-time
        // concern, not a static one. (Same as can.)
        if ($node->isRowBound && in_array(null, $node->boundKey, true)) {
            throw new InvalidArgumentException(sprintf(
                'A check(...) reference to schema [%s] specifies a row target that is null; supply a row id or a @context reference, or drop the row selector.',
                $node->schemaKey,
            ));
        }

        $this->assertAliasHasARow('check', $node->schemaKey, $node->isRowBound, $node->alias);

        $this->assertColumnRefsInScope([...$node->boundKey, ...array_values($node->contextMap)], $scope);

        /* Unlike a can(...), the predicate is written right here, in the enclosing
           rule — so it keeps the enclosing scope and gains the target's frame on
           top, under its alias when it has one, exactly as the compiler's
           AliasScope does. Aliasing the target therefore leaves its schema key
           still meaning the enclosing frame, which is how a predicate over two
           frames of one table tells them apart.

           A target with no model has no table, so its name is not bound at all
           rather than bound to nothing: there is no frame for a later compile to
           put in scope, which makes a `@column` naming it a mistake rather than a
           row out of reach. */
        $this->validateExpression(
            $node->predicate,
            new $targetClass,
            ! $targetClass::hasRows()
                ? $scope->enteringRowlessPredicate()
                : $scope->enteringPredicate($node->schemaKey, $node->alias, null),
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
     * A row-bound handle needs a target whose rows can be named.
     *
     * A virtual table may declare neither a key column nor a `matchKey()`, which
     * leaves its rows filterable but not addressable. Reported here so the rule
     * says what is wrong with it, rather than failing later on a key column that
     * was never there. The compiler makes the same check, for the handles that
     * never pass through the parser.
     *
     * @param class-string<\Warrant\Schema\WarrantSchema> $targetClass
     */
    private function assertTargetCanNameARow(
        string $builtin,
        string $schemaKey,
        string $targetClass,
        bool $isRowBound,
    ): void {
        if (! $isRowBound || ! $targetClass::hasRows() || $targetClass::hasRowKey()) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'A %s(...) reference targets a specific row of schema [%s], but [%s] has no way to name one; '
                .'declare `const key` for the column its rows are identified by, or a matchKey() of its '
                .'own, or drop the row selector.',
            $builtin,
            $schemaKey,
            $schemaKey,
        ));
    }

    /**
     * A row-bound handle must supply every argument the target schema's row key
     * requires — the parameters of its
     * {@see \Warrant\Schema\WarrantSchema::matchKey()} that have no default.
     *
     * Reported here so a handle written as rule text names its mistake before
     * anything is compiled. The key's own dispatch makes the same check, for the
     * handles that never pass through the parser.
     *
     * Extra arguments are allowed, exactly as they are for a condition: they are
     * ignored by the call and stay reachable on `$c->arguments`.
     *
     * @param class-string<\Warrant\Schema\WarrantSchema> $targetClass
     * @param array<int, mixed> $boundKey
     */
    private function assertKeyArityIsSatisfied(
        string $builtin,
        string $schemaKey,
        string $targetClass,
        bool $isRowBound,
        array $boundKey,
    ): void {
        if (! $isRowBound) {
            return;
        }

        $required = $targetClass::keyDefinition()->requiredArgumentCount;

        if (count($boundKey) >= $required) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'A %s(...) reference to schema [%s] supplies %d row-key argument(s), but that schema\'s row '
                .'key requires at least %d.',
            $builtin,
            $schemaKey,
            count($boundKey),
            $required,
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
     * @param AliasScope $scope The frames in scope where $node is written.
     */
    private function assertConditionValid(
        ConditionNode $node,
        SchemaVocabulary $vocabulary,
        AliasScope $scope,
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
        $this->assertColumnRefsInScope($node->parameters, $scope);

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
     * The scope is the compiler's own {@see \Warrant\DSL\Compiling\AliasScope},
     * carrying names bound to no qualifier, so one implementation answers what is
     * in scope for both walks and a reference that cannot work fails here rather
     * than emitting SQL about a table the query never joined. Referencing
     * your own rows is the ordinary case and always allowed (unlike
     * `can(...)`/`check(...)`, whose whole point is to leave the schema). The column
     * name itself is not checked — there is no column introspection.
     * Non-{@see ColumnRef} values are ignored.
     *
     * @param array<int, mixed> $arguments
     * @param AliasScope $scope The frames in scope where these arguments are
     *   written.
     */
    private function assertColumnRefsInScope(array $arguments, AliasScope $scope): void
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof ColumnRef) {
                /* The scope itself decides, and throws the message: an unbound name
                   is the mistake, and a name bound to nothing is a frame no compile
                   selected here, which is not. A null alias asks about this frame's
                   own rows and is answered without a name at all. */
                $scope->resolve($argument->alias);
            }
        }
    }
}
