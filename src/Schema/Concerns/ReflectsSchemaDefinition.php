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
use Warrant\ComputedAbility;
use Warrant\GlobalCondition;
use Warrant\RequiredContext;
use Warrant\Schema\AbilityDefinition;
use Warrant\Schema\ComputedAbilityContext;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\TargetedConditionContext;
use Warrant\TargetedCondition;

/**
 * Reflection over a schema's declared vocabulary: the abilities (from `#[Ability]`
 * constants) and the conditions (from `#[TargetedCondition]` / `#[GlobalCondition]`
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
     * Returns all targeted condition keys declared by the schema.
     *
     * A targeted condition key is discovered from each public method marked with
     * `#[TargetedCondition(...)]`.
     *
     * @return array<int, string>
     */
    public static function targetedConditionKeys(): array
    {
        return collect(static::conditionDefinitions())
            ->filter(fn(array $definition): bool => $definition['has_target'])
            ->pluck('key')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Returns all no-target condition keys declared by the schema.
     *
     * A no-target condition key is discovered from each public method marked with
     * `#[GlobalCondition(...)]`.
     *
     * @return array<int, string>
     */
    public static function noTargetConditionKeys(): array
    {
        return collect(static::conditionDefinitions())
            ->filter(fn(array $definition): bool => $definition['no_target'])
            ->pluck('key')
            ->filter()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Returns all condition keys declared by the schema (targeted and no-target).
     *
     * @return array<int, string>
     */
    public static function conditionKeys(): array
    {
        return collect([
            ...static::targetedConditionKeys(),
            ...static::noTargetConditionKeys(),
        ])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The names of the schema's non-computed (`#[Ability]`, rule/SQL-backed)
     * abilities, as a plain string list — the compiled subset of
     * {@see abilityDefinitions} (the full catalog of both kinds, as objects),
     * projected to names.
     *
     * The whole rule/SQL world speaks these names: the rule-set validator, the rule
     * compiler, the `$abilityLookup` that {@see normalizeAbilities} checks, per-row
     * `selectUserAbilities`, and reachability analysis. A computed ability has no
     * rule, no SQL predicate, and no per-row value, so it is excluded here — which is
     * why this projection exists next to the catalog rather than every caller
     * re-deriving `reject(computed)->map(name)` itself.
     *
     * @return array<int, string>
     */
    public static function nonComputedAbilityNames(): array
    {
        return collect(static::abilityDefinitions())
            ->reject(fn (AbilityDefinition $ability): bool => $ability->computed)
            ->map(fn (AbilityDefinition $ability): string => $ability->name)
            ->values()
            ->all();
    }

    public static function isComputedAbility(string $ability): bool
    {
        return collect(static::abilityDefinitions())
            ->contains(fn (AbilityDefinition $definition): bool => $definition->computed && $definition->name === $ability);
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
     * @return array<int, array{key: string, method: ReflectionMethod, has_target: bool, no_target: bool}>
     */
    protected static function conditionDefinitions(): array
    {
        $reflection = new ReflectionClass(static::class);

        return collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(function (ReflectionMethod $method): ?array {
                if ($method->isStatic()) {
                    return null;
                }

                $targetedAttributes = $method->getAttributes(TargetedCondition::class);
                $globalAttributes = $method->getAttributes(GlobalCondition::class);

                if ($targetedAttributes === [] && $globalAttributes === []) {
                    return null;
                }

                if (count($targetedAttributes) > 1 || count($globalAttributes) > 1) {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] must not declare duplicate condition attributes.',
                        static::class,
                        $method->getName()
                    ));
                }

                if ($targetedAttributes !== [] && $globalAttributes !== []) {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] cannot declare both #[TargetedCondition] and #[GlobalCondition].',
                        static::class,
                        $method->getName()
                    ));
                }

                $hasTarget = $targetedAttributes !== [];
                $attributeInstance = $hasTarget
                    ? $targetedAttributes[0]->newInstance()
                    : $globalAttributes[0]->newInstance();
                $conditionKey = $attributeInstance->key ?? static::conditionKeyFromMethodName($method->getName());

                if (!is_string($conditionKey) || $conditionKey === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Condition method [%s::%s] must resolve to a non-empty condition key.',
                        static::class,
                        $method->getName()
                    ));
                }

                /* The attribute chooses the context: a targeted condition receives
                   a TargetedConditionContext (carrying the target SQL id), a global
                   one a GlobalConditionContext. Require the single parameter to be
                   typed accordingly so a mismatch fails loudly at boot. */
                $expectedContext = $hasTarget
                    ? TargetedConditionContext::class
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
                    'has_target' => $hasTarget,
                    'no_target' => !$hasTarget,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, method: ReflectionMethod, has_target: bool, no_target: bool}|null
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
     * objects — from `#[Ability]` constants (compiled/SQL) and `#[ComputedAbility]`
     * methods (imperative). The single source of truth every other ability
     * accessor projects from. Throws if a name is declared more than once.
     *
     * @return array<int, AbilityDefinition>
     */
    public static function abilityDefinitions(): array
    {
        $reflection = new ReflectionClass(static::class);

        $fromConstants = collect($reflection->getReflectionConstants())
            ->map(function (ReflectionClassConstant $constant): ?AbilityDefinition {
                $attributes = $constant->getAttributes(Ability::class);

                if ($attributes === []) {
                    return null;
                }

                return new AbilityDefinition(
                    name: $constant->getValue(),
                    requiredContext: $attributes[0]->newInstance()->requiredContext,
                );
            });

        $fromMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn (ReflectionMethod $method): ?AbilityDefinition => static::computedAbilityDefinition($method));

        $definitions = $fromConstants->concat($fromMethods)->filter()->values();

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

    /**
     * Reflect a single method into a computed {@see AbilityDefinition}, or null
     * when it carries no `#[ComputedAbility]`. Mirrors the condition-method
     * validation: exactly one `ComputedAbilityContext` parameter, throwing at
     * reflection time on any mismatch.
     */
    private static function computedAbilityDefinition(ReflectionMethod $method): ?AbilityDefinition
    {
        if ($method->isStatic()) {
            return null;
        }

        $attributes = $method->getAttributes(ComputedAbility::class);

        if ($attributes === []) {
            return null;
        }

        if (count($attributes) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Method [%s::%s] must not declare duplicate #[ComputedAbility] attributes.',
                static::class,
                $method->getName(),
            ));
        }

        $attribute = $attributes[0]->newInstance();
        $name = $attribute->name ?? static::conditionKeyFromMethodName($method->getName());

        if (!is_string($name) || $name === '') {
            throw new InvalidArgumentException(sprintf(
                'Computed ability method [%s::%s] must resolve to a non-empty name.',
                static::class,
                $method->getName(),
            ));
        }

        $bindings = static::computedAbilityParameters($method);

        $autoRequired = collect($bindings)
            ->filter(fn (array $binding): bool => $binding['kind'] === 'context' && !$binding['optional'])
            ->pluck('key')
            ->all();

        $requiredContext = array_values(array_unique([
            ...$attribute->requiredContext,
            ...$autoRequired,
        ]));

        return new AbilityDefinition(
            name: $name,
            requiredContext: $requiredContext,
            computed: true,
            method: $method->getName(),
            parameterBindings: $bindings,
        );
    }

    /**
     * Reflect a computed ability method's signature into an ordered call plan.
     *
     * The first parameter is the subject: a `ComputedAbilityContext` (when typed
     * as such, or untyped and named `$context`) receives the full bag + user,
     * otherwise the parameter receives the user. Every parameter after the first
     * is a context value injected by the snake_case of its name; a parameter with
     * a default value is optional context (its default stands in when the key is
     * absent), everything else is required.
     *
     * @return array<int, array{kind: string, key?: string, optional?: bool, default?: mixed}>
     */
    private static function computedAbilityParameters(ReflectionMethod $method): array
    {
        $parameters = $method->getParameters();

        if ($parameters === []) {
            throw new InvalidArgumentException(sprintf(
                'Computed ability method [%s::%s] must accept at least one parameter (the user or a %s).',
                static::class,
                $method->getName(),
                ComputedAbilityContext::class,
            ));
        }

        $bindings = [];

        foreach ($parameters as $index => $parameter) {
            if ($index === 0) {
                $type = $parameter->getType();
                $isContextObject =
                    ($type instanceof ReflectionNamedType && $type->getName() === ComputedAbilityContext::class)
                    || ($type === null && $parameter->getName() === 'context');

                $bindings[] = ['kind' => $isContextObject ? 'context_object' : 'user'];

                continue;
            }

            $optional = $parameter->isDefaultValueAvailable();

            $bindings[] = [
                'kind' => 'context',
                'key' => Str::snake($parameter->getName()),
                'optional' => $optional,
                'default' => $optional ? $parameter->getDefaultValue() : null,
            ];
        }

        return $bindings;
    }
}
