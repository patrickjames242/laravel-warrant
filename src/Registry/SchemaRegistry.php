<?php

namespace Warrant\Registry;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OutOfBoundsException;
use Warrant\HasWarrantSchema;
use Warrant\Registry\Concerns\VerifiesSchemaModelPairs;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantManager;

/**
 * The schema index: the one mapping Warrant cannot derive, plus the resolvers that
 * normalize any accepted reference to a schema class, a schema key, or a model.
 *
 * A reference is any of: a model class, a model instance, a schema key, a schema
 * class, a schema instance, or null. Each coordinate is exposed as a pair — the
 * {@see resolveModelOrNull} style returns null when the reference is null or
 * unregistered; the {@see resolveModelOrFail} style throws instead (null included,
 * unless its $passThroughNull flag is set, which lets a null reference pass back
 * as null while a non-null still throws).
 *
 * ## What is indexed, and what is derived
 *
 * Only one direction needs an index. A bare schema key carries no information about
 * which class it names, so the `warrant.schemas` config supplies that mapping and is
 * the sole source of truth for schema keys. Everything else is derived from the
 * reference itself:
 *
 *  - schema class -> model:  the schema's {@see WarrantSchema::model} constant;
 *  - model class -> schema:  the model's {@see HasWarrantSchema::warrantSchema()};
 *  - schema/model -> key:    a reverse lookup in the index, built lazily.
 *
 * That makes the {@see HasWarrantSchema} trait authoritative for the model->schema
 * direction, which it was deliberately *not* before. The two declarations must
 * therefore agree; {@see VerifiesSchemaModelPairs} owns that cross-check and the
 * reading of a model's declaration.
 *
 * ## Nothing is loaded until it is used
 *
 * The constructor stores the index and touches nothing else. Reading a class constant
 * or calling `is_a()` on a class-string autoloads that class — and loading a schema
 * loads and boots its Eloquent model — so every check that needs a loaded class is
 * deferred to the first resolution of that schema and memoized. Registering hundreds
 * of schemas therefore costs one array of strings.
 *
 * Built from the `warrant.schemas` config and reached through {@see WarrantManager::registry()}.
 */
final class SchemaRegistry
{
    use VerifiesSchemaModelPairs;

    /**
     * @var array<string, class-string<WarrantSchema>>
     */
    private array $schemasByKey;

    /**
     * The reverse index, built on the first class->key lookup. Null until then:
     * a request that never needs a key never pays for it.
     *
     * @var array<class-string<WarrantSchema>, string>|null
     */
    private ?array $keysBySchema = null;

    /**
     * @param  array<string, class-string<WarrantSchema>>  $schemasByKey  Schema
     *   classes keyed by schema key. Values are not validated here: confirming a
     *   value is a WarrantSchema would autoload it, defeating the point of the
     *   index. That check happens in {@see VerifiesSchemaModelPairs} on first
     *   resolution.
     */
    public function __construct(array $schemasByKey)
    {
        /* Duplicate keys cannot be detected — a PHP array literal silently keeps
           the last of them. Duplicate *classes* can be, and are rejected: a schema
           with two keys has no single key to write back into rule syntax. */
        $duplicates = array_diff_assoc($schemasByKey, array_unique($schemasByKey));

        if ($duplicates !== []) {
            $schemaClass = reset($duplicates);
            $keys = array_keys($schemasByKey, $schemaClass, true);

            throw new InvalidArgumentException(sprintf(
                'Schema [%s] is registered under more than one schema key [%s]; a schema must have exactly one key.',
                $schemaClass,
                implode(', ', $keys),
            ));
        }

        $this->schemasByKey = $schemasByKey;
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
     * A schema reference must still be registered: an unregistered schema has no
     * schema key, so it can neither be written back into rule syntax nor named in
     * the {@see RuleResolutionContext} handed to the rule resolver.
     *
     * @return class-string<WarrantSchema>|null
     */
    public function resolveSchemaClassOrNull(Model|WarrantSchema|string|null $ref): ?string
    {
        /* Each arm yields [the schema, the end of the pair we were handed]. The
           second is what decides the direction the cross-check runs in, so a model
           reference is checked as a model and never as its schema. */
        [$schemaClass, $reference] = match (true) {
            $ref === null => [null, null],
            $ref instanceof WarrantSchema => [$ref::class, $ref::class],
            $ref instanceof Model => [$this->schemaDeclaredBy($ref::class), $ref::class],
            /* Try the index first: is_a() on a class-string asks the autoloader,
               and a schema key is not a class name, so testing it as one would
               spend an autoloader miss on every lookup. */
            isset($this->schemasByKey[$ref]) => [$this->schemasByKey[$ref], $this->schemasByKey[$ref]],
            is_a($ref, WarrantSchema::class, true) => [$ref, $ref],
            is_a($ref, Model::class, true) => [$this->schemaDeclaredBy($ref), $ref],
            default => [null, null],
        };

        if ($schemaClass === null || !isset($this->keysBySchema()[$schemaClass])) {
            return null;
        }

        $this->assertSchemaAndModelNameEachOther($reference, $this->keysBySchema()[$reference] ?? null);

        return $schemaClass;
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
     * A bare string is already a schema key and is returned unchanged, without
     * consulting the index. That is deliberate: rule syntax must parse and write
     * without a registry — see {@see \Warrant\RuleSyntaxTree\WarrantRule::__construct()}
     * — so a key stays an opaque token until something actually needs the schema
     * behind it, at which point {@see resolveSchemaClassOrFail} rejects it.
     * Only a model reference needs resolving here, and an unregistered model
     * yields null.
     */
    public function resolveSchemaKeyOrNull(Model|WarrantSchema|string|null $ref): ?string
    {
        /* class_exists() is what separates an unregistered key from a class-string,
           and it does ask the autoloader. That is one miss on a path that only runs
           for a key nothing has registered — worth it to keep parsing registry-free. */
        if (is_string($ref) && !isset($this->schemasByKey[$ref]) && !class_exists($ref)) {
            return $ref;
        }

        $schemaClass = $this->resolveSchemaClassOrNull($ref);

        return $schemaClass === null ? null : $this->keysBySchema()[$schemaClass];
    }

    /**
     * Like {@see resolveSchemaKeyOrNull} but throws when the reference does not
     * resolve to a registered schema.
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
     * The schema classes registered with Warrant. Loads none of them.
     *
     * @return array<int, class-string<WarrantSchema>>
     */
    public function registeredSchemas(): array
    {
        return array_values($this->schemasByKey);
    }

    /**
     * The reverse index, built once on first use.
     *
     * @return array<class-string<WarrantSchema>, string>
     */
    private function keysBySchema(): array
    {
        return $this->keysBySchema ??= array_flip($this->schemasByKey);
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
