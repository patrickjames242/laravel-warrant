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
use Warrant\ContextKey;
use Warrant\GlobalCondition;
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
     * Returns every check-time context key declared by the schema (from
     * `#[ContextKey]` constants). These are the keys a rule may reference with
     * `@context <key>` and that callers supply in the `context:` bag.
     *
     * @return array<int, string>
     */
    public static function declaredContextKeys(): array
    {
        return array_map(
            fn(array $definition): string => $definition['key'],
            static::contextKeyDefinitions(),
        );
    }

    /**
     * Returns the subset of context keys marked `#[ContextKey(required: true)]`.
     * A check whose effective context omits any of these is rejected up front.
     *
     * @return array<int, string>
     */
    public static function requiredContextKeys(): array
    {
        return array_values(array_map(
            fn(array $definition): string => $definition['key'],
            array_filter(
                static::contextKeyDefinitions(),
                fn(array $definition): bool => $definition['required'],
            ),
        ));
    }

    /**
     * @return array<int, array{key: string, required: bool}>
     */
    protected static function contextKeyDefinitions(): array
    {
        $reflection = new ReflectionClass(static::class);

        return collect($reflection->getReflectionConstants())
            ->map(function (ReflectionClassConstant $constant): ?array {
                $attributes = $constant->getAttributes(ContextKey::class);

                if ($attributes === []) {
                    return null;
                }

                return [
                    'key' => $constant->getValue(),
                    'required' => $attributes[0]->newInstance()->required,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: string}>
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
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
