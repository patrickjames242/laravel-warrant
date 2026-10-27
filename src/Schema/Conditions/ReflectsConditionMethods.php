<?php

namespace Warrant\Schema\Conditions;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Derived\ReflectsDerivedConditions;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Global\ReflectsGlobalConditions;
use Warrant\Schema\Conditions\Row\ReflectsRowConditions;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;

/**
 * Reads a schema's condition methods, of every kind, in one pass.
 *
 * The kinds share one set of names and one method can wear only one condition
 * attribute, so they are read together even though each kind is listed by a
 * trait of its own ({@see ReflectsGlobalConditions},
 * {@see ReflectsDerivedConditions}, {@see ReflectsRowConditions}). The result is
 * kept per class, grouped by kind, so a class is reflected once and each kind's
 * list is a lookup.
 */
trait ReflectsConditionMethods
{
    /**
     * Each class's condition methods, by the name of their kind.
     *
     * @var array<class-string, array<string, list<ConditionDefinition>>>
     */
    private static array $conditionMethods = [];

    /**
     * The condition methods of $kind, in declaration order.
     *
     * @return list<ConditionDefinition>
     */
    protected static function conditionMethodsOfKind(ConditionKind $kind): array
    {
        return (self::$conditionMethods[static::class] ??= self::readConditionMethods())[$kind->name] ?? [];
    }

    /**
     * Calls a row or global condition's method with its context object, then the
     * DSL arguments the context carries, and checks what it answered.
     *
     * A condition that constrained the Eloquent wrapper hands it back, since that
     * is what its own calls returned. It wraps the query builder the engine reads,
     * so it is unwrapped rather than widening what callers accept.
     */
    protected function invokeConditionMethod(ConditionDefinition $condition, GlobalConditionContext|RowConditionContext $c): QueryBuilder|bool|null
    {
        $result = $this->{$condition->methodName}($c, ...$c->arguments);

        if ($result instanceof EloquentBuilder) {
            $result = $result->toBase();
        }

        if ($result !== null && ! is_bool($result) && ! $result instanceof QueryBuilder) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] returned a [%s]; a row or global condition answers with the '
                    .'query it constrained, a bool or null. Declare a condition built from other conditions '
                    .'#[DerivedCondition] instead, taking its arguments and no context object.',
                $condition->key,
                static::class,
                get_debug_type($result),
            ));
        }

        return $result;
    }

    /**
     * @return array<string, list<ConditionDefinition>>
     */
    private static function readConditionMethods(): array
    {
        $definitions = [];

        foreach ((new ReflectionClass(static::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $method->isStatic() && ($definition = self::readConditionMethod($method)) !== null) {
                $definitions[] = $definition;
            }
        }

        $keys = array_map(static fn (ConditionDefinition $definition): string => $definition->key, $definitions);
        $duplicate = array_key_first(array_filter(array_count_values($keys), static fn (int $count): bool => $count > 1));

        if ($duplicate !== null) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] declares condition [%s] more than once; conditions of every kind share one set '
                    .'of names.',
                static::class,
                $duplicate,
            ));
        }

        $byKind = [];

        foreach ($definitions as $definition) {
            $byKind[$definition->kind->name][] = $definition;
        }

        return $byKind;
    }

    private static function readConditionMethod(ReflectionMethod $method): ?ConditionDefinition
    {
        $attributesByKind = array_filter([
            ConditionKind::Row->name => $method->getAttributes(RowCondition::class),
            ConditionKind::Global->name => $method->getAttributes(GlobalCondition::class),
            ConditionKind::Derived->name => $method->getAttributes(DerivedCondition::class),
        ]);

        if ($attributesByKind === []) {
            return null;
        }

        if (count($attributesByKind) > 1 || count(reset($attributesByKind)) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Condition method [%s::%s] must declare exactly one of #[RowCondition], #[GlobalCondition] '
                    .'and #[DerivedCondition].',
                static::class,
                $method->getName(),
            ));
        }

        $kind = constant(ConditionKind::class.'::'.array_key_first($attributesByKind));

        $key = reset($attributesByKind)[0]->newInstance()->key ?? Str::snake($method->getName());
        $firstType = ($method->getParameters()[0] ?? null)?->getType();
        $firstTypeName = $firstType instanceof ReflectionNamedType ? $firstType->getName() : null;

        /* A derived condition is expanded before anything compiles, so there is no
           user, row, query or check context to hand it: its parameters are its DSL
           arguments alone, and all of them count. */
        if ($kind === ConditionKind::Derived) {
            if (in_array($firstTypeName, [RowConditionContext::class, GlobalConditionContext::class], true)) {
                throw new InvalidArgumentException(sprintf(
                    'Derived condition [%s::%s] takes no context object; its parameters are its DSL arguments '
                        .'alone. Move anything that reads the user, the row or the check context into a row or '
                        .'global condition, and compose it into the expression.',
                    static::class,
                    $method->getName(),
                ));
            }

            return new ConditionDefinition(
                $key,
                $method->getName(),
                $kind,
                $method->getNumberOfRequiredParameters(),
                $method->isVariadic() ? null : $method->getNumberOfParameters(),
            );
        }

        /* A row or global condition is handed its context object first, so a
           mismatched type fails here rather than as a TypeError mid-compile. Any
           further parameters are its DSL arguments, bound by position. */
        $expectedContext = $kind === ConditionKind::Row ? RowConditionContext::class : GlobalConditionContext::class;

        if ($firstTypeName !== $expectedContext) {
            throw new InvalidArgumentException(sprintf(
                'Condition method [%s::%s] must accept a [%s] as its first parameter.',
                static::class,
                $method->getName(),
                $expectedContext,
            ));
        }

        return new ConditionDefinition(
            $key,
            $method->getName(),
            $kind,
            max(0, $method->getNumberOfRequiredParameters() - 1),
            $method->isVariadic() ? null : $method->getNumberOfParameters() - 1,
        );
    }
}
