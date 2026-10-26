<?php

namespace Warrant\Schema\Rows;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionNamedType;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\ConditionKind;
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\RuleTemplates\RuleTemplate;

/**
 * Implements {@see \Warrant\Schema\Rows\IProvidesRows::rowMatcher()} and
 * {@see \Warrant\Schema\Rows\IProvidesRows::invokeRowMatcher()}.
 *
 * The matcher is the schema's `matchKey()` when it declares one, read as a row
 * condition is: the parameters after the leading context object are its
 * arguments, and those without defaults are required. Otherwise it is
 * `defaultMatchKey()`, taking one argument per key column.
 *
 * `matchKey()` carries no attribute and no rule can name it. A rule reaches it
 * only through a handle's arguments, as in `documents(@context id)`.
 */
trait ReflectsRowMatcher
{
    /**
     * @var array<class-string, ConditionDefinition>
     */
    private static array $declaredRowMatchers = [];

    public function rowMatcher(): ConditionDefinition
    {
        if (! method_exists($this, 'matchKey')) {
            return new ConditionDefinition('matchKey', 'defaultMatchKey', ConditionKind::Row, count($this->keyColumns()));
        }

        return self::$declaredRowMatchers[static::class] ??= self::readMatchKey();
    }

    /**
     * A matcher narrows the query to one row or answers unknown, and nothing else:
     * its predicate lands inside an `exists` about one row, so a bool deciding the
     * outcome outright would answer a different question than the one asked.
     *
     * Extra arguments are ignored by the call and stay reachable on
     * `$c->arguments`, but too few is a mistake in whatever named the row, so it
     * is refused here, wherever the row was named from.
     */
    public function invokeRowMatcher(RowConditionContext $c): ?QueryBuilder
    {
        $matcher = $this->rowMatcher();

        if (count($c->arguments) < $matcher->requiredArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'The row key for schema [%s] requires at least %d argument(s), but %d were supplied.',
                static::class,
                $matcher->requiredArgumentCount,
                count($c->arguments),
            ));
        }

        $result = $this->{$matcher->methodName}($c, ...$c->arguments);

        if ($result instanceof EloquentBuilder) {
            $result = $result->toBase();
        }

        if ($result !== null && ! $result instanceof QueryBuilder) {
            throw new InvalidArgumentException(sprintf(
                'The row matcher for schema [%s] must return the query it constrained, or null to answer '
                    .'unknown; it returned a [%s].',
                static::class,
                get_debug_type($result),
            ));
        }

        return $result;
    }

    private static function readMatchKey(): ConditionDefinition
    {
        $method = new ReflectionMethod(static::class, 'matchKey');

        foreach ([RowCondition::class, GlobalCondition::class, DerivedCondition::class, RuleTemplate::class] as $attribute) {
            if ($method->getAttributes($attribute) !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Schema [%s] declares a vocabulary attribute on matchKey(), which finds the schema\'s rows '
                        .'and is not part of its rule vocabulary; remove the attribute, or move the logic to a '
                        .'condition or template method of its own.',
                    static::class,
                ));
            }
        }

        $firstType = ($method->getParameters()[0] ?? null)?->getType();

        if (! $firstType instanceof ReflectionNamedType || $firstType->getName() !== RowConditionContext::class) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] must accept a [%s] as the first parameter of matchKey().',
                static::class,
                RowConditionContext::class,
            ));
        }

        return new ConditionDefinition('matchKey', 'matchKey', ConditionKind::Row, max(0, $method->getNumberOfRequiredParameters() - 1));
    }
}
