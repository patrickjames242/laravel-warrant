<?php

namespace Warrant\DSL\Compiling\Concerns;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use Warrant\DSL\Compiling\AliasScope;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\QueryFactory;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IHandleNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\RelationHandleNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaHandleNode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Relations\IProvidesRowRelations;
use Warrant\Schema\Relations\RowRelationContext;
use Warrant\Schema\Relations\RowRelationDefinition;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;
use Warrant\Schema\Types\Interfaces\IRowSchema;

/**
 * What a `can(... for ...)` and a `check(...)` share: finding the target schema,
 * checking the handle can work, reading its row selector and `with` map, and
 * naming and narrowing the target's rows in the subquery.
 *
 * @internal
 */
trait ResolvesHopTargets
{
    /**
     * The schema a hop reaches, from the registry, by {@see hopTargetKey()}. A hop
     * reaches its target's abilities or conditions, so the target must be a schema
     * checks are made against.
     */
    private function hopTarget(IHandleNode $handle, string $bKey): IAbilitySchema
    {
        $schema = $this->manager->registry()->resolveOrFail($bKey)->schema;

        if (! $schema instanceof IAbilitySchema) {
            throw new InvalidArgumentException(sprintf(
                'A reference to schema [%s] needs a schema checks are made against, but [%s] is not an %s.',
                $bKey,
                $schema::class,
                IAbilitySchema::class,
            ));
        }

        return $schema;
    }

    /**
     * The key of the schema a handle reaches: the key it names, or the key the
     * target of this schema's row relation is registered under.
     */
    private function hopTargetKey(IHandleNode $handle): string
    {
        if ($handle instanceof SchemaHandleNode) {
            return $handle->schemaKey->name;
        }

        return $this->manager->registry()->resolveSchemaKeyOrFail($this->ownRowRelation($handle)->targetSchemaClass);
    }

    /**
     * How a handle is written, for a message that has no schema key to name yet.
     */
    private static function handleLabel(IHandleNode $handle): string
    {
        return $handle instanceof RelationHandleNode ? 'its '.$handle->relation->name : $handle->schemaKey->name;
    }

    /**
     * The row relation an `its` handle names on the schema being compiled.
     */
    private function ownRowRelation(RelationHandleNode $handle): RowRelationDefinition
    {
        $relation = $this->schema instanceof IProvidesRowRelations
            ? $this->schema->rowRelation($handle->relation->name)
            : null;

        return $relation ?? throw new InvalidArgumentException(sprintf(
            'Schema [%s] declares no row relation [%s].',
            $this->schema::class,
            $handle->relation->name,
        ));
    }

    /**
     * The rows an `its` handle reaches: a subquery over the target's rows under
     * $bQualifier, narrowed by the relation's method to those related to the row
     * in scope. Null when no row is in scope, which answers unknown.
     *
     * The method may only add where clauses, as a condition may, and must add at
     * least one: a relation that adds none reaches every row of the target, so an
     * `exists` over it answers for any row at all rather than the related ones.
     *
     * @param array<string, mixed> $bContext
     */
    private function relatedRows(
        RelationHandleNode $handle,
        IRowSchema $bSchema,
        CompilationContext $ctx,
        string $bQualifier,
        array $bContext,
    ): ?Builder {
        $ownQualifier = $this->aliases($ctx)->current;

        if (! $ctx->targeted || $ownQualifier === null) {
            return null;
        }

        $relation = $this->ownRowRelation($handle);
        $bSubquery = $bSchema->fromRows($ctx->queries->newQuery(), $bQualifier);

        $this->schema->invokeRowRelation($relation, new RowRelationContext(
            $ctx->user,
            $bSchema->conditionQuery($bSubquery, $bQualifier),
            $ownQualifier,
            $bQualifier,
            $bContext,
        ));

        $this->assertOnlyWhereClauses($bSubquery, sprintf('Row relation [%s]', $relation->key));

        if ($bSubquery->wheres === []) {
            throw new InvalidArgumentException(sprintf(
                'Row relation [%s] on schema [%s] added no where clause; a relation must narrow the related '
                    .'rows to the ones related to this row, as in '
                    .'$c->relatedQuery->where($c->relatedColumn(\'id\'), $c->ownColumn(\'%s_id\')).',
                $relation->key,
                $this->schema::class,
                $relation->key,
            ));
        }

        return $bSubquery;
    }

    /**
     * Narrow a hop's subquery to the row $arguments name, through the target's
     * row matcher. Null when the arguments name no row, which answers unknown.
     *
     * The matcher is handed the context a row condition would be, built over the
     * subquery, so it may use a model scope or follow the row's name through
     * {@see RowConditionContext::column()}.
     *
     * @param array<int, mixed> $arguments
     * @param array<string, mixed> $context
     */
    private function narrowToRow(
        IRowSchema $schema,
        Authenticatable $user,
        Builder $subquery,
        array $arguments,
        array $context,
        ?Model $targetModel,
        string $rowName,
    ): ?Builder {
        return $schema->invokeRowMatcher(new RowConditionContext(
            $user,
            $schema->conditionQuery($subquery, $rowName),
            $rowName,
            $schema->keyColumns(),
            $arguments,
            $context,
            $targetModel,
        ));
    }

    /**
     * The identifier a hop's rows carry in the emitted SQL, or null when the hop
     * selects no rows to name.
     *
     * The name the reference asked for — its alias, else the target's table —
     * suffixed only when that identifier already stands for a frame in this
     * query, which a hop into a table already in scope does. Chosen once per hop,
     * because the subquery's `from`, its correlated key and every `@column` that
     * resolves to the frame all have to agree on it.
     */
    private function hopQualifier(
        CrossSchemaCanNode|CrossSchemaConditionNode $node,
        IAbilitySchema $schema,
        AliasScope $scope,
    ): ?string {
        $table = $schema instanceof IRowSchema ? $schema->rowName() : null;

        if (! $node->handle->isRowBound() || $table === null) {
            return null;
        }

        return $scope->freeQualifier($node->alias?->name ?? $table);
    }

    /**
     * Assert a hop's handle is one the target could answer at all — the three ways
     * a handle can be structurally impossible rather than merely unanswerable.
     *
     * Each is a claim about the handle as written, decidable without resolving a
     * single argument, so none of them can be answered with an unknown the way a
     * missing row or an absent `@context` key is:
     *
     *  - a row-bound hop into a schema with no rows, which has nothing to select
     *    and no source to select it from;
     *  - a row-bound hop whose selector is a literal `null`, which names no row
     *    (a `@context` selector is a symbol until compile time, so a null *value*
     *    from one is a different thing, and folds);
     *  - an alias on a handle that selects no rows, leaving the name standing for
     *    nothing.
     *
     * Validation makes the same three checks over each text as written. They are
     * made again here for rules that reached the compile without it.
     */
    private function assertHandleIsWellFormed(
        CrossSchemaCanNode|CrossSchemaConditionNode $node,
        IAbilitySchema $schema,
        string $bKey,
    ): void {
        $builtin = $node instanceof CrossSchemaCanNode ? 'can' : 'check';

        if ($node->handle->isRowBound() && ! $schema instanceof IRowSchema) {
            throw new InvalidArgumentException(sprintf(
                'A %s(...) reference targets a specific row of schema [%s], but [%s] has no rows and '
                    .'cannot be row-targeted; drop the row selector.',
                $builtin,
                $bKey,
                $bKey,
            ));
        }

        $literalNulls = array_filter(
            $node->handle->rowSelector(),
            static fn (INode $argument): bool => $argument instanceof LiteralNode && $argument->value === null,
        );

        if ($node->handle->isRowBound() && $literalNulls !== []) {
            throw new InvalidArgumentException(sprintf(
                'A %s(...) reference to schema [%s] specifies a row target that is null; supply a row id '
                    .'or a @context reference, or drop the row selector.',
                $builtin,
                $bKey,
            ));
        }

        if (! $node->handle->isRowBound() && $node->alias !== null) {
            throw new InvalidArgumentException(sprintf(
                'A %s(...) reference to schema [%s] is aliased [as %s] but selects no row, so the alias '
                    .'names nothing; add a row selector like %s(@context id) as %s, or drop the alias.',
                $builtin,
                $bKey,
                $node->alias->name,
                $bKey,
                $node->alias->name,
            ));
        }
    }

    /**
     * The `with` map's keys, in the order written. A key written twice would give
     * the target two values for one name, so it is rejected, with the message
     * validation gives the same mistake in rule text.
     *
     * @param list<WithEntryNode> $contextMap
     * @return list<string>
     */
    private function contextMapKeys(array $contextMap): array
    {
        $keys = [];

        foreach ($contextMap as $entry) {
            if (in_array($entry->key->name, $keys, true)) {
                throw new InvalidArgumentException(sprintf("Duplicate key '%s' in the 'with' map.", $entry->key->name));
            }

            $keys[] = $entry->key->name;
        }

        return $keys;
    }

    /**
     * A cross-schema `can(...)` embeds B's table as a subquery inside A's query,
     * which executes on a single connection. If B lives on a different connection
     * the emitted SQL would silently reference a table that isn't there, so reject
     * it with a clear message instead.
     */
    private function assertSameConnection(QueryFactory $queries, IAbilitySchema $bSchema, string $bSchemaKey): void
    {
        $parentConnection = $queries->connectionName();
        $bConnection = $bSchema instanceof IRowSchema ? $bSchema->connection()->getName() : null;

        if ($bConnection !== null && $parentConnection !== $bConnection) {
            throw new InvalidArgumentException(sprintf(
                'Cannot compile a reference to schema [%s]: that schema is on database connection [%s] '
                    .'but the query runs on [%s]; a cross-connection reference is not supported.',
                $bSchemaKey,
                $bConnection,
                $parentConnection,
            ));
        }
    }

    /**
     * Read a cross-schema handle's row selector into the key to bind and, when
     * the caller named the row by handing over the row itself, the model to
     * evaluate B's row conditions against.
     *
     * The selector is whatever a binding or `@context` supplied, and nothing has
     * validated it: it lands verbatim as the bound value of
     * `where <b>.<key> = ?`. A database driver only takes scalars, so an object
     * with no defined meaning there reaches PDO and is stringified by whatever
     * `__toString()` it happens to have — for an Eloquent model that is
     * `toJson()`, which compiles to `where "folders"."id" = '{"id":"f-1"}'` and
     * quietly matches no row at all. Rejecting those is the point of this method:
     * a rule that cannot work should say so rather than silently deny.
     *
     * A model of B's own class is the case worth having. It names the row by
     * being it, so its key is bound — and if Eloquent regards it as hydrated it
     * is also handed to B's row conditions, which may then answer in PHP. A model
     * of any *other* class is a mistake worth catching: its key would be compared
     * against the wrong table, which is exactly the silent non-match this method
     * exists to end.
     *
     * Three object types pass through because they already mean something as a
     * binding: an {@see Expression} is `@column` / `@sql` splicing raw SQL rather
     * than binding at all, and Laravel resolves a {@see BackedEnum} through
     * `castBinding()` and a {@see DateTimeInterface} through `prepareBindings()`.
     *
     * A model is read for its key only when it is the handle's *sole* argument,
     * which is the case the default key describes — one value, the primary key.
     * A key of several parts is the schema's own design, so a model among its
     * arguments is passed through as written and is that key's business.
     *
     * @param array<int, mixed> $values The handle's resolved arguments.
     * @param class-string<Model>|null $bModelClass B's model class, or null when B
     *   is not a model schema.
     * @return array{0: array<int, mixed>, 1: ?Model} The arguments to hand the
     *   key, and the model to thread into B's compile (null unless a hydrated one
     *   was supplied as the sole argument).
     */
    private function resolveKeyArguments(array $values, string $bSchemaKey, ?string $bModelClass): array
    {
        if (count($values) !== 1) {
            foreach ($values as $value) {
                $this->assertKeyArgumentIsBindable($value, $bSchemaKey);
            }

            return [$values, null];
        }

        [$value] = $values;

        if ($value instanceof Model) {
            if ($bModelClass === null || ! $value instanceof $bModelClass) {
                throw new InvalidArgumentException(sprintf(
                    'The row selector for schema [%s] is a [%s], which is not that schema\'s model [%s]; '
                        .'pass that schema\'s own model or a row key.',
                    $bSchemaKey,
                    $value::class,
                    $bModelClass ?? 'none',
                ));
            }

            /* Hydrated only, as at the guard: an unsaved or deleted instance
               still names a key, but proves nothing about the row being there,
               so it must not let a condition answer from memory. */
            return [[$value->getKey()], $value->exists ? $value : null];
        }

        $this->assertKeyArgumentIsBindable($value, $bSchemaKey);

        return [[$value], null];
    }

    /**
     * Reject an argument that could never reach SQL as a value.
     *
     * Three object types pass because they already mean something as a binding:
     * an {@see Expression} is `@column` / `@sql` splicing raw SQL rather than
     * binding at all, and Laravel resolves a {@see BackedEnum} through
     * `castBinding()` and a {@see DateTimeInterface} through `prepareBindings()`.
     * A {@see Model} passes too — as one part of a key of several it is whatever
     * that key makes of it.
     */
    private function assertKeyArgumentIsBindable(mixed $value, string $bSchemaKey): void
    {
        if (
            is_object($value)
            && ! $value instanceof Expression
            && ! $value instanceof BackedEnum
            && ! $value instanceof DateTimeInterface
            && ! $value instanceof Model
        ) {
            throw new InvalidArgumentException(sprintf(
                'The row selector for schema [%s] is a [%s], which cannot identify a row; '
                    .'pass a key, that schema\'s model, or a @column/@sql reference.',
                $bSchemaKey,
                $value::class,
            ));
        }
    }
}
