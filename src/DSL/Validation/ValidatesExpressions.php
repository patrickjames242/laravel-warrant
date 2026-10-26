<?php

namespace Warrant\DSL\Validation;

use InvalidArgumentException;
use Warrant\DSL\Compiling\AliasScope;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\MissingExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Resolution\ISchemaId;
use Warrant\DSL\Resolution\KeyedSchemaId;
use Warrant\Schema\Vocabulary;

/**
 * The checks a {@see SyntaxValidator} makes of a boolean expression: every
 * condition and ability it names is one its schema declares, each condition is
 * called with at least the arguments it requires, and every cross-schema
 * `can(...)` and `check(...)` names a schema, row and alias that can work.
 *
 * Own-schema checks depend only on the schema's {@see Vocabulary} — name
 * existence, no SQL. A cross-schema reference is additionally resolved against
 * the catalog (by schema key) to confirm the target schema and ability exist;
 * the referenced schema's *rules* are never consulted here (they are per-user
 * and resolver-owned), so cycle detection is deliberately left to the
 * compiler. That is also why a reference may target the owning schema: whether
 * it recurses is a question about rules the validator cannot see.
 *
 * One check is not about names but about *scope*: a `@column` reference may only
 * name a table the rule can actually see from where it is written. That set grows
 * as the walk descends into a `check(...)` predicate, mirroring the
 * {@see \Warrant\DSL\Compiling\AliasScope} the compiler builds, so a reference
 * to an unrelated table is an error here rather than a SQL error at execution.
 *
 * @internal
 */
trait ValidatesExpressions
{
    /**
     * Validate a condition against the schema it belongs to, as a rule's own `if`
     * expression would be. With no schema known there is nothing to look its
     * names up in and no scope to place its `@column` references in, and it is
     * held to every other rule of the language.
     */
    private function validateCondition(IBooleanExpressionNode $expression, ?Vocabulary $schema, ?string $schemaKey): void
    {
        $this->validateExpression(
            $expression,
            $schema,
            $schema === null ? null : $this->rootScope($schema, $schemaKey),
            $schema === null || $schemaKey === null ? null : $this->constructs()->writtenIn($expression->location->source->producer(), $schemaKey),
        );
    }

    /**
     * The scope at the top of these rules: the owning schema's own key, standing
     * for the row being checked, bound to no qualifier because no query is in
     * hand here.
     *
     * A schema with no rows is the exception: its own key names nothing, so a
     * `@column` in its rules has nothing in scope at all.
     */
    private function rootScope(Vocabulary $schema, ?string $schemaKey): AliasScope
    {
        $rowless = ! $schema->hasRows();

        return $rowless ? AliasScope::none() : AliasScope::root($schemaKey, null);
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
     * A leaf whose schema is not known has nothing to look its names up in, and
     * a reference written where the scope is not known has nothing to be checked
     * against. Everything else about it is checked all the same.
     *
     * @param Vocabulary|null $vocabulary Whose conditions and abilities the
     *   leaves of $node name; null when that schema is not known.
     * @param AliasScope|null $scope The frames in scope where $node is written; see
     *   {@see assertColumnRefsInScope}. Every qualifier in it is null — nothing is
     *   selected until a compile, and only the names matter here. Null when the
     *   frames are not known.
     * @param ISchemaId|null $writtenIn The schema the text was written in, which
     *   its names are resolved from; null when no schema is known.
     * @param CrossSchemaConditionNode|null $predicateOf The `check(...)` whose
     *   predicate this is, or null for a rule's own expression.
     */
    private function validateExpression(
        IBooleanExpressionNode $node,
        ?Vocabulary $vocabulary,
        ?AliasScope $scope,
        ?ISchemaId $writtenIn,
        ?CrossSchemaConditionNode $predicateOf = null,
    ): void {
        match (true) {
            $node instanceof ConditionNode => $this->assertConditionValid($node, $writtenIn, $scope, $predicateOf),
            $node instanceof CrossSchemaCanNode => $this->assertCrossSchemaCanValid($node, $vocabulary, $scope),
            $node instanceof CrossSchemaConditionNode => $this->assertCrossSchemaConditionValid($node, $scope),
            $node instanceof NotNode => $this->validateExpression($node->operand, $vocabulary, $scope, $writtenIn, $predicateOf),
            $node instanceof AndNode, $node instanceof OrNode => (function () use ($node, $vocabulary, $scope, $writtenIn, $predicateOf): void {
                $this->validateExpression($node->leftSide, $vocabulary, $scope, $writtenIn, $predicateOf);
                $this->validateExpression($node->rightSide, $vocabulary, $scope, $writtenIn, $predicateOf);
            })(),
            // The analysis that read it in place of a missing expression reported it.
            $node instanceof MissingExpressionNode => null,
            /* A rule may be written around a constant; a predicate may not, because
               a `check(...)` that decides itself asks the target nothing. A derived
               condition answering with a constant or unknown is another matter: its
               answer is validated on its own, where no predicate surrounds it. */
            default => $predicateOf === null ? null : $this->fail(
                DiagnosticCode::ConstantInCheckPredicate,
                sprintf(
                    'A check(...) predicate for schema [%s] may not contain a constant; it has to ask that '
                        .'schema something.',
                    $predicateOf->schemaKey->name,
                ),
                $node->location,
            ),
        };
    }

    /**
     * Validate a cross-schema `can(<ability> for <schema>[(<row>)] [as <alias>])`
     * reference: the target schema must be registered, the ability must be
     * declared by it, a row-bound reference requires a target with rows, and an
     * alias requires a row to name.
     *
     * The target may be this schema itself. Two frames over one table are told
     * apart by naming the hop's rows (`as d2`), and recursion is bounded by
     * {@see \Warrant\DSL\Compiling\CallStack}, which rejects re-entering an
     * ability already in progress and caps depth for everything else — so a
     * self-reference has to name a *different* ability to compile at all.
     */
    private function assertCrossSchemaCanValid(
        CrossSchemaCanNode $node,
        ?Vocabulary $vocabulary,
        ?AliasScope $scope,
    ): void {
        if ($node->schemaKey === null) {
            $this->assertOwnAbilityValid($node, $vocabulary);

            return;
        }

        $schemaKey = $node->schemaKey->name;
        $ability = self::abilityToLookUp($node, $this->input);

        // A handle with no name was reported where it was read; there is no schema to look up.
        if ($schemaKey === INode::MISSING_NAME) {
            return;
        }

        $target = $this->input->catalog?->find($schemaKey);

        if ($this->input->catalog !== null && $target === null) {
            $this->fail(DiagnosticCode::UnknownSchema, sprintf('A can(...) reference targets unknown schema [%s].', $schemaKey), $node->schemaKey->location);

            return;
        }

        if ($target !== null && $ability !== null && $target->ability($ability) === null) {
            $this->fail(
                DiagnosticCode::UnknownAbility,
                sprintf('Ability [%s] is not declared by schema [%s].', $ability, $schemaKey),
                $node->ability->location,
            );
        }

        $this->assertRowTargetValid('can', $node, $schemaKey, $target);

        $this->assertAliasHasARow('can', $schemaKey, $node->isRowBound, $node->alias);
        $this->assertWithKeysDistinct($node->contextMap);

        /* The handle's own arguments are written in the enclosing rule, so they see
           the enclosing scope. The target's *rules* are not validated here at all —
           they are validated against their own schema, with their own scope. */
        $this->assertColumnRefsInScope([...$node->boundKey, ...self::withValues($node->contextMap)], $scope);
    }

    /**
     * Validate a cross-schema
     * `check(<predicate> for <schema>[(<row>)] [as <alias>])` reference: the
     * target schema must be registered, a row-bound reference requires a target
     * with rows and a non-null row, and an alias requires a row to name. The predicate is a boolean expression read against the *target*
     * schema's vocabulary — so a `can(...)` in it names one of that schema's
     * abilities, and a nested `check(...)` starts from that schema's frame. An
     * unbound handle constrains the predicate's leaves not at all: a row condition
     * among them has no row and so no answer, which the compiler says with an
     * unknown rather than an error (see {@see assertConditionValid}).
     *
     * As with `can(...)` the target may be this schema itself.
     */
    private function assertCrossSchemaConditionValid(CrossSchemaConditionNode $node, ?AliasScope $scope): void
    {
        $schemaKey = $node->schemaKey->name;

        // A handle with no name was reported where it was read; there is no schema to check the predicate against.
        if ($schemaKey === INode::MISSING_NAME) {
            return;
        }

        $target = $this->input->catalog?->find($schemaKey);

        if ($this->input->catalog !== null && $target === null) {
            $this->fail(DiagnosticCode::UnknownSchema, sprintf('A check(...) reference targets unknown schema [%s].', $schemaKey), $node->schemaKey->location);

            return;
        }

        $this->assertRowTargetValid('check', $node, $schemaKey, $target);
        $this->assertAliasHasARow('check', $schemaKey, $node->isRowBound, $node->alias);
        $this->assertWithKeysDistinct($node->contextMap);

        $this->assertColumnRefsInScope([...$node->boundKey, ...self::withValues($node->contextMap)], $scope);

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
            $target,
            match (true) {
                $scope === null || $target === null => null,
                ! $target->hasRows() => $scope->enteringRowlessPredicate(),
                default => $scope->enteringPredicate($schemaKey, $node->alias?->name, null),
            },
            /* The `for` names the schema the predicate's names are written for,
               whatever text the check sits in. */
            $target === null ? null : new KeyedSchemaId($schemaKey),
            $node,
        );
    }

    /**
     * The ability $node asks about, to look up in a schema: its name, or the
     * name a binding's value gives it when binding values are checked. Null
     * when there is none to look up: a name missing from the text, which was
     * reported where it was read, or a binding whose value is unchecked or is
     * reported by the binding checks.
     */
    private static function abilityToLookUp(CrossSchemaCanNode $node, ValidationInput $input): ?string
    {
        $ability = $node->ability;

        return match (true) {
            $ability instanceof NameNode => $ability->name === INode::MISSING_NAME ? null : $ability->name,
            $input->checkBindingValues && $ability->hasValue() && AbilityMistakes::inBoundAbility($ability) === null => $ability->value(),
            default => null,
        };
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
    private function assertOwnAbilityValid(CrossSchemaCanNode $node, ?Vocabulary $vocabulary): void
    {
        $lookedUp = self::abilityToLookUp($node, $this->input);

        if ($vocabulary !== null && $lookedUp !== null && $vocabulary->ability($lookedUp) === null) {
            $this->fail(DiagnosticCode::UnknownAbility, sprintf('Ability [%s] is not declared by the schema.', $lookedUp), $node->ability->location);
        }

        $ability = $node->ability instanceof BindingNode ? ':'.$node->ability->name : $node->ability->name;

        if ($node->contextMap !== []) {
            $this->fail(
                DiagnosticCode::ContextMapWithoutSchema,
                sprintf(
                    'A can(%s) reference carries the context it is already in, so it takes no `with` map; '
                        .'name a schema with `for` if you meant to cross to one.',
                    $ability,
                ),
                $node->contextMap[0]->location,
            );
        }

        if ($node->alias !== null) {
            $this->fail(
                DiagnosticCode::AliasWithoutRow,
                sprintf(
                    'A can(%s) reference selects no rows of its own, so there is nothing for [as %s] to name; '
                        .'name a schema with `for` if you meant to cross to one.',
                    $ability,
                    $node->alias->name,
                ),
                $node->alias->location,
            );
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
        ?NameNode $alias,
    ): void {
        if ($alias === null || $isRowBound) {
            return;
        }

        $this->fail(
            DiagnosticCode::AliasWithoutRow,
            sprintf(
                'A %s(...) reference to schema [%s] is aliased [as %s] but selects no row, so the alias names '
                    .'nothing; add a row selector like %s(@context id) as %s, or drop the alias.',
                $builtin,
                $schemaKey,
                $alias->name,
                $schemaKey,
                $alias->name,
            ),
            $alias->location,
        );
    }

    /**
     * A row-bound handle's row target can work: when the target is known, it has
     * rows, and the handle supplies every argument the target's row matcher
     * requires, none of them the literal `null`. An unbound handle has no row
     * target, so nothing here applies to it.
     *
     * The arguments a row matcher requires are its key's columns, or the
     * parameters of the schema's own `matchKey()` that have no default. Extra
     * arguments are allowed, exactly as they are for a condition: they are
     * ignored by the call and stay reachable on `$c->arguments`. A literal
     * `null` can never match a row, in any position. A `@context` reference is a
     * symbolic ContextRef, not null — its value is filled per check — so its
     * nullability stays a compile-time concern, not a static one.
     *
     * Reported here so a handle written as rule text names its mistake before
     * anything is compiled. The compiler and the row matcher make the same checks,
     * for the handles that never pass through the parser.
     */
    private function assertRowTargetValid(
        string $builtin,
        CrossSchemaCanNode|CrossSchemaConditionNode $node,
        string $schemaKey,
        ?Vocabulary $target,
    ): void {
        if (! $node->isRowBound) {
            return;
        }

        if ($target !== null) {
            $this->assertTargetRowsCanBeNamed($builtin, $node, $schemaKey, $target);
        }

        /* A binding's value is looked up only when values are being checked; it
           becomes a literal at expansion, where the compiler checks it again. */
        foreach ($node->boundKey as $argument) {
            $isNull = match (true) {
                $argument instanceof LiteralNode => $argument->value === null,
                $argument instanceof BindingNode => $this->input->checkBindingValues && $argument->hasValue() && $argument->value() === null,
                default => false,
            };

            if ($isNull) {
                $this->fail(
                    DiagnosticCode::NullRowTarget,
                    sprintf(
                        'A %s(...) reference to schema [%s] specifies a row target that is null; supply a row id or a '
                            .'@context reference, or drop the row selector.',
                        $builtin,
                        $schemaKey,
                    ),
                    $argument->location,
                );
            }
        }
    }

    /**
     * $target has rows, and $node supplies every argument its row matcher
     * requires.
     */
    private function assertTargetRowsCanBeNamed(
        string $builtin,
        CrossSchemaCanNode|CrossSchemaConditionNode $node,
        string $schemaKey,
        Vocabulary $target,
    ): void {
        if (! $target->hasRows()) {
            $this->fail(
                DiagnosticCode::RowTargetOnRowlessSchema,
                sprintf(
                    'A %s(...) reference targets a specific row of schema [%s], but [%s] has no rows and cannot be '
                        .'row-targeted; drop the row selector.',
                    $builtin,
                    $schemaKey,
                    $schemaKey,
                ),
                $node->location,
            );

            return;
        }

        $required = $target->rowMatcher()->requiredArgumentCount;

        if (count($node->boundKey) < $required) {
            $this->fail(
                DiagnosticCode::TooFewArguments,
                sprintf(
                    'A %s(...) reference to schema [%s] supplies %d row-key argument(s), but that schema\'s row '
                        .'key requires at least %d.',
                    $builtin,
                    $schemaKey,
                    count($node->boundKey),
                    $required,
                ),
                $node->location,
            );
        }
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
     * @param AliasScope|null $scope The frames in scope where $node is written;
     *   null when they are not known.
     */
    private function assertConditionValid(
        ConditionNode $node,
        ?ISchemaId $writtenIn,
        ?AliasScope $scope,
        ?CrossSchemaConditionNode $predicateOf,
    ): void {
        if ($writtenIn !== null) {
            $this->assertConditionDeclared($node, $writtenIn, $predicateOf);
        }

        $this->assertColumnRefsInScope($node->parameters, $scope);

        /* Context keys need no declaration: a rule may reference any `@context`
           key. An absent key simply makes its condition false at compile time
           (see RuleSetCompiler); required keys are enforced separately, at check
           time, via #[RequiredContext] and per-ability requires. */
    }

    /**
     * The condition $node names resolves from $writtenIn, to the schema the text
     * was written in or a global, and $node gives it at least the arguments it
     * requires.
     */
    private function assertConditionDeclared(ConditionNode $node, ISchemaId $writtenIn, ?CrossSchemaConditionNode $predicateOf): void
    {
        $definition = $this->constructs()->condition($node->conditionKey->name, $writtenIn)?->definition;

        if ($definition === null) {
            $this->fail(
                DiagnosticCode::UnknownCondition,
                $predicateOf === null
                    ? sprintf('Condition [%s] is not declared by the schema.', $node->conditionKey->name)
                    : sprintf('Condition [%s] is not declared by schema [%s].', $node->conditionKey->name, $predicateOf->schemaKey->name),
                $node->conditionKey->location,
            );

            return;
        }

        $this->assertEnoughArguments($node, $definition->requiredArgumentCount);
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
            $this->fail(
                DiagnosticCode::TooFewArguments,
                sprintf('Condition [%s] requires at least %d argument(s), but the rule supplied %d.', $node->conditionKey->name, $required, $supplied),
                $node->location,
            );
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
     * @param list<INode> $arguments
     * @param AliasScope|null $scope The frames in scope where these arguments are
     *   written; null when they are not known, and nothing is checked.
     */
    private function assertColumnRefsInScope(array $arguments, ?AliasScope $scope): void
    {
        if ($scope === null) {
            return;
        }

        foreach ($arguments as $argument) {
            if ($argument instanceof ColumnRef && ! $this->isCallerArgument($argument)) {
                /* The compiler's own scope decides, and throws the message: an
                   unbound name is the mistake, and a name bound to nothing is a
                   frame no compile selected here, which is not. A null alias asks
                   about this frame's own rows and is answered without a name at all. */
                try {
                    $scope->resolveColumn($argument);
                } catch (InvalidArgumentException $e) {
                    $this->fail(DiagnosticCode::ColumnNotInScope, $e->getMessage(), $argument->location);
                }
            }
        }
    }

    /**
     * Whether $columnRef is one the condition or `@include` the syntax answers
     * passed in, as the producer it is validated under says. Its value was put in place in
     * PHP before it reached here, so it sits in these rules as the reference
     * itself. It is the calling rule's, checked in that rule's scope where the
     * rule wrote it, so it is not checked again in the scope it was passed into.
     */
    private function isCallerArgument(ColumnRef $columnRef): bool
    {
        return in_array($columnRef, $this->input->producer?->callerArguments() ?? [], true);
    }

    /**
     * A `with` map gives each context key once. A key given again is reported
     * where it is written again, and the first value is the one that counts.
     *
     * @param list<WithEntryNode> $entries
     */
    private function assertWithKeysDistinct(array $entries): void
    {
        $seen = [];

        foreach ($entries as $entry) {
            $key = $entry->key->name;

            if ($key === INode::MISSING_NAME) {
                continue;
            }

            if (isset($seen[$key])) {
                $this->fail(
                    DiagnosticCode::DuplicateContextKey,
                    sprintf("Duplicate key '%s' in the 'with' map.", $key),
                    $entry->key->location,
                );
            }

            $seen[$key] = true;
        }
    }

    /**
     * The values of a `with` map's entries, in the order written.
     *
     * @param list<WithEntryNode> $entries
     * @return list<INode>
     */
    private static function withValues(array $entries): array
    {
        return array_map(static fn (WithEntryNode $entry): INode => $entry->value, $entries);
    }
}
