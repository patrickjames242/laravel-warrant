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
     * Returns the complete list of abilities declared by the schema.
     *
     * Abilities are discovered from class constants marked with `#[Ability]`.
     * Constant naming is not used for discovery.
     *
     * @return array<int, string>
     */
    public static function declaredAbilities(): array
    {
        return collect(static::abilityDefinitions())
            ->pluck('value')
            ->values()
            ->all();
    }

    /**
     * Split the given abilities by whether their per-ability required context
     * (declared via `#[Ability(requires: [...])]`) is satisfied by the keys
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
        $requiresByAbility = collect(static::abilityDefinitions())->pluck('requires', 'value');
        $present = array_keys($context);

        $satisfied = [];
        $missing = [];

        foreach ($abilities as $ability) {
            $needed = array_values(array_diff(
                $requiresByAbility->get($ability, []),
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
     * `#[Ability(requires: [...])]`.)
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
     * @return array<int, array{value: string, requires: array<int, string>}>
     */
    protected static function abilityDefinitions(): array
    {
        $reflection = new ReflectionClass(static::class);

        return collect($reflection->getReflectionConstants())
            ->map(function (ReflectionClassConstant $constant): ?array {
                $attributes = $constant->getAttributes(Ability::class);

                if ($attributes === []) {
                    return null;
                }

                return [
                    'value' => $constant->getValue(),
                    'requires' => $attributes[0]->newInstance()->requires,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
