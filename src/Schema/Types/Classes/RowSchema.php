<?php

namespace Warrant\Schema\Types\Classes;

use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Schema\Conditions\Row\ReflectsRowConditions;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Rows\ReflectsRowMatcher;
use Warrant\Schema\Types\Interfaces\IRowSchema;

/**
 * What every schema with rows shares: row conditions, and finding one row from a
 * handle's arguments. Where the rows come from is left to {@see ModelSchema} and
 * {@see QuerySchema}.
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

    protected function verifyDeclarations(): void
    {
        parent::verifyDeclarations();

        $this->rowMatcher();
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
