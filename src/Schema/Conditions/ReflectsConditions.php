<?php

namespace Warrant\Schema\Conditions;

use Warrant\Schema\Conditions\Derived\IProvidesDerivedConditions;
use Warrant\Schema\Conditions\Global\IProvidesGlobalConditions;
use Warrant\Schema\Conditions\Row\IProvidesRowConditions;

/**
 * Implements {@see IProvidesConditions} over every kind of condition the schema
 * provides, as its type says: global and derived conditions for every schema, and
 * row conditions for a schema with rows.
 *
 * Each kind's trait uses this one, so a schema with conditions of any kind can be
 * asked for all of them. The list, and the same conditions by key, are kept per
 * class, so a lookup by key is an array lookup.
 */
trait ReflectsConditions
{
    use ReflectsConditionMethods;

    /**
     * @var array<class-string, list<ConditionDefinition>>
     */
    private static array $conditions = [];

    /**
     * @var array<class-string, array<string, ConditionDefinition>>
     */
    private static array $conditionsByKey = [];

    /**
     * @return list<ConditionDefinition>
     */
    public function conditions(): array
    {
        return self::$conditions[static::class] ??= [
            ...($this instanceof IProvidesGlobalConditions ? static::conditionMethodsOfKind(ConditionKind::Global) : []),
            ...($this instanceof IProvidesDerivedConditions ? static::conditionMethodsOfKind(ConditionKind::Derived) : []),
            ...($this instanceof IProvidesRowConditions ? static::conditionMethodsOfKind(ConditionKind::Row) : []),
        ];
    }

    public function condition(string $key): ?ConditionDefinition
    {
        self::$conditionsByKey[static::class] ??= array_column(
            array_map(static fn (ConditionDefinition $condition): array => [$condition->key, $condition], $this->conditions()),
            1,
            0,
        );

        return self::$conditionsByKey[static::class][$key] ?? null;
    }
}
