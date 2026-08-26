<?php

namespace Warrant;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OutOfBoundsException;
use Warrant\Schema\WarrantSchema;

/**
 * Registry of the Warrant schemas: the maps between model classes, schema keys,
 * and schema classes, plus the resolvers that normalize any accepted reference
 * to one of those coordinates.
 *
 * A reference is any of: a model class, a model instance, a schema key, a schema
 * class, a schema instance, or null. Each coordinate is exposed as a pair — the
 * {@see resolveModelOrNull} style returns null when the reference is null or
 * unregistered; the {@see resolveModelOrFail} style throws instead (null included,
 * unless its $passThroughNull flag is set, which lets a null reference pass back
 * as null while a non-null still throws).
 *
 * Source of truth: the model<->schema relationship comes *solely* from the schema's
 * {@see WarrantSchema::model} constant. A model may use the {@see \Warrant\HasWarrantSchema}
 * trait to name its own schema, but the registry never consults that — it indexes
 * models only by the model each schema claims. If a schema does not claim a model,
 * no model reference resolves to it here.
 *
 * Built from the `warrant.schemas` config and reached through {@see WarrantManager::registry()}.
 */
final class SchemaRegistry
{
    /**
     * @var array<class-string<Model>, class-string<WarrantSchema>>
     */
    private array $modelsToSchemas = [];

    /**
     * @var array<string, class-string<WarrantSchema>>
     */
    private array $schemaKeysToSchemas = [];

    /**
     * @param  array<int, class-string<WarrantSchema>>  $schemaClasses
     */
    public function __construct(array $schemaClasses)
    {
        foreach ($schemaClasses as $schemaClass) {
            $model = $schemaClass::model;

            $schemaKey = $schemaClass::schemaKey();

            if (isset($this->schemaKeysToSchemas[$schemaKey])) {
                throw new InvalidArgumentException('Duplicate schema for schema key '.$schemaKey);
            }

            /* The schema is the sole source of truth for its model: we index by
               the model the schema claims, never by what a model's HasWarrantSchema
               trait declares. Capability schemas claim no model and are not indexed. */
            if ($model !== '') {
                if (isset($this->modelsToSchemas[$model])) {
                    throw new InvalidArgumentException('Duplicate schema for model '.$model);
                }

                $this->modelsToSchemas[$model] = $schemaClass;
            }

            $this->schemaKeysToSchemas[$schemaKey] = $schemaClass;
        }
    }

    /**
     * The backing model class for the schema the reference resolves to, or null
     * when the reference is null, unregistered, or resolves to a capability schema
     * (one with no backing model).
     *
     * @return class-string<Model>|null
     */
    public function resolveModelOrNull(Model|WarrantSchema|string|null $ref): ?string
    {
        $schemaClass = $this->resolveSchemaClassOrNull($ref);

        if ($schemaClass === null || $schemaClass::model === '') {
            return null;
        }

        return $schemaClass::model;
    }

    /**
     * @param  bool  $passThroughNull  When true a null reference returns null
     *   instead of throwing; a non-null reference still throws if unresolvable.
     * @return ($passThroughNull is true ? class-string<Model>|null : class-string<Model>)
     */
    public function resolveModelOrFail(Model|WarrantSchema|string|null $ref, bool $passThroughNull = false): ?string
    {
        $model = $this->resolveModelOrNull($ref);

        if ($model === null && !($passThroughNull && $ref === null)) {
            throw new OutOfBoundsException($this->unresolvableMessage('model', $ref));
        }

        return $model;
    }

    /**
     * The schema class the reference resolves to, or null when the reference is
     * null or unregistered.
     *
     * @return class-string<WarrantSchema>|null
     */
    public function resolveSchemaClassOrNull(Model|WarrantSchema|string|null $ref): ?string
    {
        if ($ref === null) {
            return null;
        }

        if ($ref instanceof WarrantSchema) {
            return $ref::class;
        }

        if ($ref instanceof Model) {
            return $this->modelsToSchemas[$ref::class] ?? null;
        }

        if (is_a($ref, WarrantSchema::class, true)) {
            return $ref;
        }

        if (is_a($ref, Model::class, true)) {
            return $this->modelsToSchemas[$ref] ?? null;
        }

        /* A plain string is treated as a literal schema key. */
        return $this->schemaKeysToSchemas[$ref] ?? null;
    }

    /**
     * @param  bool  $passThroughNull  When true a null reference returns null
     *   instead of throwing; a non-null reference still throws if unregistered.
     * @return ($passThroughNull is true ? class-string<WarrantSchema>|null : class-string<WarrantSchema>)
     */
    public function resolveSchemaClassOrFail(Model|WarrantSchema|string|null $ref, bool $passThroughNull = false): ?string
    {
        $schemaClass = $this->resolveSchemaClassOrNull($ref);

        if ($schemaClass === null && !($passThroughNull && $ref === null)) {
            throw new OutOfBoundsException($this->unresolvableMessage('schema', $ref));
        }

        return $schemaClass;
    }

    /**
     * The schema key the reference resolves to, or null when the reference is null.
     *
     * A schema (class or instance) yields its own key and a bare string is already
     * a schema key, so both resolve without consulting the registry — a bare key
     * need not be registered. Only a model reference requires a registry lookup,
     * and an unregistered model yields null here.
     */
    public function resolveSchemaKeyOrNull(Model|WarrantSchema|string|null $ref): ?string
    {
        if ($ref === null) {
            return null;
        }

        if ($ref instanceof WarrantSchema) {
            return $ref::schemaKey();
        }

        if ($ref instanceof Model) {
            $schemaClass = $this->modelsToSchemas[$ref::class] ?? null;

            return $schemaClass === null ? null : $schemaClass::schemaKey();
        }

        if (is_a($ref, WarrantSchema::class, true)) {
            return $ref::schemaKey();
        }

        if (is_a($ref, Model::class, true)) {
            $schemaClass = $this->modelsToSchemas[$ref] ?? null;

            return $schemaClass === null ? null : $schemaClass::schemaKey();
        }

        /* A plain string is already a schema key. */
        return $ref;
    }

    /**
     * Like {@see resolveSchemaKeyOrNull} but throws for a model reference whose
     * schema is not registered (and, unless $passThroughNull, for a null reference).
     * A bare schema-key string always resolves to itself.
     *
     * @param  bool  $passThroughNull  When true a null reference returns null
     *   instead of throwing; a non-null reference still throws if unresolvable.
     * @return ($passThroughNull is true ? string|null : string)
     */
    public function resolveSchemaKeyOrFail(Model|WarrantSchema|string|null $ref, bool $passThroughNull = false): ?string
    {
        $schemaKey = $this->resolveSchemaKeyOrNull($ref);

        if ($schemaKey === null && !($passThroughNull && $ref === null)) {
            throw new OutOfBoundsException($this->unresolvableMessage('schema', $ref));
        }

        return $schemaKey;
    }

    /**
     * The schema classes registered with Warrant.
     *
     * @return array<int, class-string<WarrantSchema>>
     */
    public function registeredSchemas(): array
    {
        return array_values($this->schemaKeysToSchemas);
    }

    private function unresolvableMessage(string $coordinate, Model|WarrantSchema|string|null $ref): string
    {
        $reference = match (true) {
            $ref === null => 'null',
            $ref instanceof Model, $ref instanceof WarrantSchema => $ref::class,
            default => $ref,
        };

        return sprintf('No Warrant %s registered for reference [%s].', $coordinate, $reference);
    }
}
