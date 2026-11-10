<?php

namespace Warrant\Schema\Types\Classes;

use Illuminate\Contracts\Database\Query\Builder;
use InvalidArgumentException;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\Row\ReflectsRowConditions;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Relations\ReflectsRowRelations;
use Warrant\Schema\Rows\ReflectsRowMatcher;
use Warrant\Schema\Types\Interfaces\IRowSchema;

/**
 * What every schema with rows shares: row conditions, row relations, and finding
 * one row from a handle's arguments. Where the rows come from is left to
 * {@see ModelSchema} and {@see QuerySchema}.
 *
 * A schema finds its rows by their key columns unless it declares a `matchKey()`
 * of its own. `matchKey()` is not declared here, because PHP won't let an override
 * add required parameters, and a key of several parts has to declare them:
 *
 * ```php
 * public function matchKey(RowConditionContext $c, mixed $teamId, string $date): ?Builder
 * {
 *     return $c->query
 *         ->where($c->column('team_id'), $teamId)
 *         ->whereDate($c->column('day'), $date);
 * }
 * ```
 */
abstract class RowSchema extends AbilitySchema implements IRowSchema
{
    use ReflectsRowConditions;
    use ReflectsRowMatcher;
    use ReflectsRowRelations;

    protected function verifyDeclarations(): void
    {
        parent::verifyDeclarations();

        $this->rowMatcher();
        $this->refuseRelationNamedLikeACondition();
    }

    /**
     * Rule text writes a condition after `if` and a relation after `its`, but a
     * name still means one thing on a schema, so a reader never has to work out
     * which of the two a name stands for.
     */
    private function refuseRelationNamedLikeACondition(): void
    {
        $conditionKeys = array_map(static fn (ConditionDefinition $condition): string => $condition->key, $this->conditions());

        foreach ($this->rowRelations() as $relation) {
            if (in_array($relation->key, $conditionKeys, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Schema [%s] declares [%s] as both a condition and a row relation; give them different names.',
                    static::class,
                    $relation->key,
                ));
            }
        }
    }

    /**
     * The row matcher for a schema that declares no `matchKey()`: each key column
     * equal to the argument in the same position.
     *
     * A null argument names no row, such as an absent `@context` value, so it
     * answers unknown, which neither grants nor lifts a deny. That is the only
     * safe answer, because the `exists` this predicate lands in cannot report
     * unknown once its subquery is built.
     */
    protected function defaultMatchKey(RowConditionContext $c, mixed ...$key): ?Builder
    {
        foreach ($c->keyColumns as $position => $column) {
            if (($key[$position] ?? null) === null) {
                return null;
            }

            $c->query->where($c->column($column), '=', $key[$position]);
        }

        return $c->query;
    }
}
