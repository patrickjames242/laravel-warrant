<?php

namespace Warrant\Schema\Relations;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Implements {@see IProvidesRowRelations} over the schema's `#[RowRelation]`
 * methods, read once per class.
 */
trait ReflectsRowRelations
{
    /**
     * @var array<class-string, array<string, RowRelationDefinition>>
     */
    private static array $rowRelationMethods = [];

    public function rowRelations(): array
    {
        return array_values(self::rowRelationMethods());
    }

    public function rowRelation(string $key): ?RowRelationDefinition
    {
        return self::rowRelationMethods()[$key] ?? null;
    }

    /**
     * A relation names rows rather than deciding anything, so it answers with the
     * query it constrained: never a bool, and never null for unknown.
     */
    public function invokeRowRelation(RowRelationDefinition $relation, RowRelationContext $c): QueryBuilder
    {
        $result = $this->{$relation->methodName}($c);

        if ($result instanceof EloquentBuilder) {
            $result = $result->toBase();
        }

        if (! $result instanceof QueryBuilder) {
            throw new InvalidArgumentException(sprintf(
                'Row relation [%s] on schema [%s] must return the query it constrained; it returned a [%s].',
                $relation->key,
                static::class,
                get_debug_type($result),
            ));
        }

        return $result;
    }

    /**
     * @return array<string, RowRelationDefinition>
     */
    private static function rowRelationMethods(): array
    {
        return self::$rowRelationMethods[static::class] ??= self::readRowRelationMethods();
    }

    /**
     * @return array<string, RowRelationDefinition>
     */
    private static function readRowRelationMethods(): array
    {
        $relations = [];

        foreach ((new ReflectionClass(static::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(RowRelation::class);

            if ($method->isStatic() || $attributes === []) {
                continue;
            }

            self::assertTakesOnlyTheContext($method);

            $declared = $attributes[0]->newInstance();
            $key = $declared->key ?? Str::snake($method->getName());

            if (isset($relations[$key])) {
                throw new InvalidArgumentException(sprintf(
                    'Schema [%s] declares row relation [%s] more than once.',
                    static::class,
                    $key,
                ));
            }

            $relations[$key] = new RowRelationDefinition($key, $method->getName(), $declared->schema);
        }

        return $relations;
    }

    private static function assertTakesOnlyTheContext(ReflectionMethod $method): void
    {
        $parameters = $method->getParameters();
        $firstType = ($parameters[0] ?? null)?->getType();

        if (count($parameters) === 1 && $firstType instanceof ReflectionNamedType && $firstType->getName() === RowRelationContext::class) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Row relation [%s::%s] must take exactly one parameter, a [%s].',
            static::class,
            $method->getName(),
            RowRelationContext::class,
        ));
    }
}
