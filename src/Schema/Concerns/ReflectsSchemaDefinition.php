<?php

namespace Warrant\Schema\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionNamedType;
use Warrant\Ability;
use Warrant\GlobalCondition;
use Warrant\RequiredContext;
use Warrant\RowCondition;
use Warrant\Schema\AbilityDefinition;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;

/**
 * Reflection over a schema's declared vocabulary: the abilities (from `#[Ability]`
 * constants) and the conditions (from `#[RowCondition]` / `#[GlobalCondition]`
 * methods) that a rule string is allowed to reference.
 */
trait ReflectsSchemaDefinition
{
    /**
     * Returns the schema key for this schema — the namespace prefix used in
     * rules and lookups.
     *
     * The value is derived from the table name of the model referenced by `static::model`.
     * That makes the prefix deterministic and keeps rules aligned with the
     * managed entity in storage.
     *
     * Example:
     * ```php
     * CourseSectionSchema::schemaKey();
     * ```
     *
     * Expected output:
     * ```php
     * 'course_sections'
     * ```
     */
    public static function schemaKey(): string
    {
        if (static::schemaKey !== null) {
            return static::schemaKey;
        }

        $modelClass = static::model;

        /** @var Model $model */
        $model = new $modelClass;

        return $model->getTable();
    }

    /**
     * Returns all row condition keys declared by the schema.
     *
     * A row condition key is discovered from each public method marked with
     * `#[RowCondition(...)]`.
     *
     * @return array<int, string>
     */
    public static function rowConditionKeys(): array
    {
        return collect(static::conditionDefinitions())
            ->filter(fn(array $definition): bool => $definition['is_row'])
            ->pluck('key')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Returns all global condition keys declared by the schema.
     *
     * A global condition key is discovered from each public method marked with
     * `#[GlobalCondition(...)]`.
     *
     * @return array<int, string>
     */
    public static function globalConditionKeys(): array
    {
        return collect(static::conditionDefinitions())
            ->filter(fn(array $definition): bool => $definition['is_global'])
            ->pluck('key')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Returns all condition keys declared by the schema (row and global).
     *
     * @return array<int, string>
     */
    public static function conditionKeys(): array
    {
        return collect([
            ...static::rowConditionKeys(),
            ...static::globalConditionKeys(),
        ])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The names of the schema's `#[Ability]` abilities, as a plain string list —
     * {@see abilityDefinitions} (the catalog, as objects) projected to names.
     *
     * The whole rule/SQL world speaks these names: the rule-set validator, the rule
     * compiler, the `$abilityLookup` that {@see normalizeAbilities} checks, per-row
     * `selectUserAbilities`, and reachability analysis. This projection exists next
     * to the catalog so callers don't re-derive `map(name)` themselves.
     *
     * @return array<int, string>
     */
    public static function abilityNames(): array
    {
        return collect(static::abilityDefinitions())
            ->map(fn (AbilityDefinition $ability): string => $ability->name)
            ->values()
            ->all();
    }

    /**
     * Split the given abilities by whether their per-ability required context
     * (declared via `#[Ability(requiredContext: [...])]`) is satisfied by the keys
     * present in the effective context. `satisfied` keeps its input order;
     * `missing` maps each unsatisfied ability to the context keys it lacks.
     *
     * Callers that *named* an ability throw on `missing`; callers that merely
     * *enumerate* abilities keep only `satisfied`.
     *
     * @param  array<int, string>  $abilities
     * @param  array<string, mixed>  $context
     * @return array{satisfied: array<int, string>, missing: array<string, array<int, string>>}
     */
    public static function partitionAbilitiesByContext(array $abilities, array $context): array
    {
        $definitionsByName = collect(static::abilityDefinitions())->keyBy('name');
        $present = array_keys($context);

        $satisfied = [];
        $missing = [];

        foreach ($abilities as $ability) {
            $needed = array_values(array_diff(
                $definitionsByName->get($ability)?->requiredContext ?? [],
                $present,
            ));

            if ($needed === []) {
                $satisfied[] = $ability;
            } else {
                $missing[$ability] = $needed;
            }
        }

        return ['satisfied' => $satisfied, 'missing' => $missing];
    }

    /**
     * Schemas with no model only answer no-target checks. Guard the targeted
     * paths so they fail with a clear message instead of `new ('')` fataling as
     * "Class \"\" not found".
     */
    protected static function assertSupportsTargetedChecks(): void
    {
        if (static::model === '') {
            throw new InvalidArgumentException(
                sprintf(
                    'Schema [%s] is a schema with no model and does not support targeted checks; use a no-target check instead.',
                    static::class
                )
            );
        }
    }

    protected static function conditionKeyFromMethodName(string $methodName): ?string
    {
        if ($methodName === '') {
            return null;
        }

        return Str::snake($methodName);
    }

    /**
     * @return array<int, array{key: string, method: ReflectionMethod, is_row: bool, is_global: bool}>
     */
    protected static function conditionDefinitions(): array
    {
        $reflection = new ReflectionClass(static::class);

        return collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(function (ReflectionMethod $method): ?array {
                if ($method->isStatic()) {
                    return null;
                }

                $rowAttributes = $method->getAttributes(RowCondition::class);
                $globalAttributes = $method->getAttributes(GlobalCondition::class);

                if ($rowAttributes === [] && $globalAttributes === []) {
                    return null;
                }

                if (count($rowAttributes) > 1 || count($globalAttributes) > 1) {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] must not declare duplicate condition attributes.',
                        static::class,
                        $method->getName()
                    ));
                }

                if ($rowAttributes !== [] && $globalAttributes !== []) {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] cannot declare both #[RowCondition] and #[GlobalCondition].',
                        static::class,
                        $method->getName()
                    ));
                }

                $isRow = $rowAttributes !== [];
                $attributeInstance = $isRow
                    ? $rowAttributes[0]->newInstance()
                    : $globalAttributes[0]->newInstance();
                $conditionKey = $attributeInstance->key ?? static::conditionKeyFromMethodName($method->getName());

                if (!is_string($conditionKey) || $conditionKey === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] must resolve to a non-empty condition key.',
                        static::class,
                        $method->getName()
                    ));
                }

                /* The attribute chooses the context: a row condition receives a
                   RowConditionContext (carrying the target row's SQL identity), a
                   global one a GlobalConditionContext. Require the single parameter
                   to be typed accordingly so a mismatch fails loudly at boot. */
                $expectedContext = $isRow
                    ? RowConditionContext::class
                    : GlobalConditionContext::class;
                $parameters = $method->getParameters();
                $parameterType = ($parameters[0] ?? null)?->getType();

                if (
                    count($parameters) !== 1
                    || !$parameterType instanceof ReflectionNamedType
                    || $parameterType->getName() !== $expectedContext
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] must accept exactly one [%s] parameter.',
                        static::class,
                        $method->getName(),
                        $expectedContext
                    ));
                }

                return [
                    'key' => $conditionKey,
                    'method' => $method,
                    'is_row' => $isRow,
                    'is_global' => !$isRow,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, method: ReflectionMethod, is_row: bool, is_global: bool}|null
     */
    protected static function conditionDefinitionForKey(string $conditionKey): ?array
    {
        return collect(static::conditionDefinitions())
            ->first(fn(array $definition): bool => $definition['key'] === $conditionKey);
    }

    /**
     * Returns the context keys marked `#[RequiredContext]` — keys that must be
     * present in the effective context of *every* check against the schema. A
     * check whose context omits any of these is rejected up front.
     *
     * Context keys need no declaration to be *used*; this list is only the
     * schema-wide mandatory ones. (Per-ability requirements live on
     * `#[Ability(requiredContext: [...])]`.)
     *
     * @return array<int, string>
     */
    public static function requiredContextKeys(): array
    {
        return collect((new ReflectionClass(static::class))->getReflectionConstants())
            ->filter(fn(ReflectionClassConstant $constant): bool => $constant->getAttributes(RequiredContext::class) !== [])
            ->map(fn(ReflectionClassConstant $constant): string => $constant->getValue())
            ->values()
            ->all();
    }

    /**
     * The abilities declared by the schema, resolved to {@see AbilityDefinition}
     * objects from its `#[Ability]` constants. The single source of truth every
     * other ability accessor projects from. Throws if a name is declared more
     * than once.
     *
     * @return array<int, AbilityDefinition>
     */
    public static function abilityDefinitions(): array
    {
        $reflection = new ReflectionClass(static::class);

        $definitions = collect($reflection->getReflectionConstants())
            ->map(function (ReflectionClassConstant $constant): ?AbilityDefinition {
                $attributes = $constant->getAttributes(Ability::class);

                if ($attributes === []) {
                    return null;
                }

                return new AbilityDefinition(
                    name: $constant->getValue(),
                    requiredContext: $attributes[0]->newInstance()->requiredContext,
                );
            })
            ->filter()
            ->values();

        $duplicate = $definitions->pluck('name')->duplicates()->first();

        if ($duplicate !== null) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] declares ability [%s] more than once.',
                static::class,
                $duplicate,
            ));
        }

        return $definitions->all();
    }
}
