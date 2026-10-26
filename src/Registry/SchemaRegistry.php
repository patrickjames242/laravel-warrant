<?php

namespace Warrant\Registry;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OutOfBoundsException;
use Warrant\HasWarrantSchema;
use Warrant\Registry\Concerns\VerifiesSchemaModelPairs;
use Warrant\Schema\Types\Interfaces\IGlobalSchema;
use Warrant\Schema\Types\Interfaces\IModelSchema;
use Warrant\Schema\Types\Interfaces\ISchema;
use Warrant\WarrantManager;

/**
 * The schema index: the one mapping Warrant cannot derive, plus the resolvers that
 * normalize any accepted reference to a schema, a schema class, a schema key, or a
 * model.
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
 *  - schema class -> model:  {@see IModelSchema::model()}, for a model schema;
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
 * ## One instance per schema
 *
 * A schema holds no user, so one instance serves every check. {@see resolveOrFail}
 * builds it on first resolution, which is also when constructing it checks what
 * the class declares, and hands it back with its key every time after.
 *
 * ## Global schemas
 *
 * The `warrant.global` config lists the global schemas, in the order a name is
 * looked up in them. A global has no key, because no rule names it: it is
 * found by its class, through {@see globalSchema()}, and never resolves as a
 * reference.
 *
 * Built from the `warrant.schemas` and `warrant.global` config and reached through
 * {@see WarrantManager::registry()}.
 */
final class SchemaRegistry
{
    use VerifiesSchemaModelPairs;

    /**
     * @var array<string, class-string<ISchema>>
     */
    private array $schemasByKey;

    /**
     * Each schema resolved so far, by schema class.
     *
     * @var array<class-string<ISchema>, ResolvedSchema>
     */
    private array $resolved = [];

    /**
     * The reverse index, built on the first class->key lookup. Null until then:
     * a request that never needs a key never pays for it.
     *
     * @var array<class-string<ISchema>, string>|null
     */
    private ?array $keysBySchema = null;

    /**
     * @var list<class-string<IGlobalSchema>>
     */
    private array $globalClasses;

    /**
     * Each global schema built so far, by class.
     *
     * @var array<class-string<IGlobalSchema>, IGlobalSchema>
     */
    private array $globals = [];

    /**
     * @param  array<string, class-string<ISchema>>  $schemasByKey  Schema
     *   classes keyed by schema key. Values are not validated here: confirming a
     *   value is an ISchema would autoload it, defeating the point of the
     *   index. That check happens in {@see VerifiesSchemaModelPairs} on first
     *   resolution.
     * @param  list<class-string<IGlobalSchema>>  $globalClasses  The global
     *   schemas, in the order a name is looked up in them. Each is checked when
     *   it is first built, for the same reason.
     */
    public function __construct(array $schemasByKey, array $globalClasses = [])
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

        /* A global is found by its class, so a class listed twice, or as both a
           global and a keyed schema, would be two answers to one lookup. */
        $repeated = [
            ...array_diff_assoc($globalClasses, array_unique($globalClasses)),
            ...array_intersect($globalClasses, $schemasByKey),
        ];

        if ($repeated !== []) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] is listed more than once across `schemas` and `global`; a schema is listed once.',
                reset($repeated),
            ));
        }

        $this->schemasByKey = $schemasByKey;
        $this->globalClasses = array_values($globalClasses);
    }

    /**
     * The global schemas' classes, in the order a name is looked up in them.
     * Reading them loads no class.
     *
     * @return list<class-string<IGlobalSchema>>
     */
    public function globalClasses(): array
    {
        return $this->globalClasses;
    }

    /**
     * The global schema $class, built on first use and the same instance after.
     *
     * @param  class-string<IGlobalSchema>  $class  One of {@see globalClasses()}.
     */
    public function globalSchema(string $class): IGlobalSchema
    {
        if (isset($this->globals[$class])) {
            return $this->globals[$class];
        }

        if (! in_array($class, $this->globalClasses, true)) {
            throw new OutOfBoundsException(sprintf('Schema [%s] is not listed under `global`.', $class));
        }

        if (! is_a($class, IGlobalSchema::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] is listed under `global`, but does not implement %s.',
                $class,
                IGlobalSchema::class,
            ));
        }

        return $this->globals[$class] = new $class;
    }

    /**
     * The schema the reference resolves to, with the key it is registered under, or
     * null when the reference is null or unregistered. The schema is built on its
     * first resolution and the same instance answers every one after.
     */
    public function resolveOrNull(Model|ISchema|string|null $ref): ?ResolvedSchema
    {
        $schemaClass = $this->resolveSchemaClassOrNull($ref);

        if ($schemaClass === null) {
            return null;
        }

        return $this->resolved[$schemaClass] ??= new ResolvedSchema(
            $this->keysBySchema()[$schemaClass],
            $ref instanceof ISchema && $ref::class === $schemaClass ? $ref : new $schemaClass,
        );
    }

    /**
     * @param  bool  $passThroughNull  When true a null reference returns null
     *   instead of throwing; a non-null reference still throws if unresolvable.
     * @return ($passThroughNull is true ? ResolvedSchema|null : ResolvedSchema)
     */
    public function resolveOrFail(Model|ISchema|string|null $ref, bool $passThroughNull = false): ?ResolvedSchema
    {
        $resolved = $this->resolveOrNull($ref);

        if ($resolved === null && !($passThroughNull && $ref === null)) {
            throw new OutOfBoundsException($this->unresolvableMessage('schema', $ref));
        }

        return $resolved;
    }

    /**
     * The model class for the schema the reference resolves to, or null when the
     * reference is null, unregistered, or resolves to a schema that is not a model
     * schema.
     *
     * @return class-string<Model>|null
     */
    public function resolveModelOrNull(Model|ISchema|string|null $ref): ?string
    {
        $schemaClass = $this->resolveSchemaClassOrNull($ref);

        return $schemaClass !== null && is_a($schemaClass, IModelSchema::class, true)
            ? $schemaClass::model()
            : null;
    }

    /**
     * @param  bool  $passThroughNull  When true a null reference returns null
     *   instead of throwing; a non-null reference still throws if unresolvable.
     * @return ($passThroughNull is true ? class-string<Model>|null : class-string<Model>)
     */
    public function resolveModelOrFail(Model|ISchema|string|null $ref, bool $passThroughNull = false): ?string
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
     * the {@see \Warrant\Rules\RuleProviderContext} handed to the rule provider.
     *
     * @return class-string<ISchema>|null
     */
    public function resolveSchemaClassOrNull(Model|ISchema|string|null $ref): ?string
    {
        /* Each arm yields [the schema, the end of the pair we were handed]. The
           second is what decides the direction the cross-check runs in, so a model
           reference is checked as a model and never as its schema. */
        [$schemaClass, $reference] = match (true) {
            $ref === null => [null, null],
            $ref instanceof ISchema => [$ref::class, $ref::class],
            $ref instanceof Model => [$this->schemaDeclaredBy($ref::class), $ref::class],
            /* Try the index first: is_a() on a class-string asks the autoloader,
               and a schema key is not a class name, so testing it as one would
               spend an autoloader miss on every lookup. */
            isset($this->schemasByKey[$ref]) => [$this->schemasByKey[$ref], $this->schemasByKey[$ref]],
            is_a($ref, ISchema::class, true) => [$ref, $ref],
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
     * @return ($passThroughNull is true ? class-string<ISchema>|null : class-string<ISchema>)
     */
    public function resolveSchemaClassOrFail(Model|ISchema|string|null $ref, bool $passThroughNull = false): ?string
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
     * without a registry — see {@see \Warrant\DSL\Parsing\ASTNodes\RuleNode::__construct()}
     * — so a key stays an opaque token until something actually needs the schema
     * behind it, at which point {@see resolveSchemaClassOrFail} rejects it.
     * Only a model reference needs resolving here, and an unregistered model
     * yields null.
     */
    public function resolveSchemaKeyOrNull(Model|ISchema|string|null $ref): ?string
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
    public function resolveSchemaKeyOrFail(Model|ISchema|string|null $ref, bool $passThroughNull = false): ?string
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
     * @return array<int, class-string<ISchema>>
     */
    public function registeredSchemas(): array
    {
        return array_values($this->schemasByKey);
    }

    /**
     * The reverse index, built once on first use.
     *
     * @return array<class-string<ISchema>, string>
     */
    private function keysBySchema(): array
    {
        return $this->keysBySchema ??= array_flip($this->schemasByKey);
    }

    private function unresolvableMessage(string $coordinate, Model|ISchema|string|null $ref): string
    {
        $reference = match (true) {
            $ref === null => 'null',
            $ref instanceof Model, $ref instanceof ISchema => $ref::class,
            default => $ref,
        };

        return sprintf('No Warrant %s registered for reference [%s].', $coordinate, $reference);
    }
}
