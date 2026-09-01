<?php

namespace Warrant\Registry\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use ReflectionMethod;
use Warrant\HasWarrantSchema;
use Warrant\Schema\WarrantSchema;

/**
 * Reading and cross-checking the model<->schema pair.
 *
 * The relationship is declared twice: a schema names its model with
 * {@see WarrantSchema::model}, and a model names its schema with
 * {@see HasWarrantSchema::warrantSchema()}. Both declarations are load-bearing,
 * because the registry indexes only schema keys and derives everything else from
 * the reference in hand — so each direction has to be answerable on its own.
 *
 * Two declarations can disagree, and that is what this concern exists for. It also
 * owns the one place that *reads* a model's declaration
 * ({@see schemaDeclaredBy()}), so the read and the checks that make the read
 * trustworthy stay together.
 *
 * Everything here is memoized per class and runs at the moment that class is first
 * resolved — which is the moment it gets loaded anyway — so none of it is paid at
 * boot, which is the whole point of the index.
 */
trait VerifiesSchemaModelPairs
{
    /**
     * References {@see assertSchemaAndModelNameEachOther()} has already checked,
     * keyed by the class handed in — a schema class or a model class.
     *
     * @var array<class-string<WarrantSchema>|class-string<Model>, true>
     */
    private array $verified = [];

    /**
     * Model classes whose warrantSchema() has been confirmed static. Separate from
     * $verified because it is checked before the static call that resolution needs,
     * which is earlier than the cross-check itself.
     *
     * @var array<class-string<Model>, true>
     */
    private array $staticChecked = [];

    /**
     * The schema a model class declares, or null when the model does not use
     * {@see HasWarrantSchema}. A model without the trait simply has no schema to
     * find, which is not an error — the Gate bridge relies on it to leave
     * non-Warrant models to Laravel's own policies.
     *
     * @param  class-string<Model>  $modelClass
     * @return class-string<WarrantSchema>|null
     */
    private function schemaDeclaredBy(string $modelClass): ?string
    {
        /* method_exists, not is_callable: Eloquent defines __callStatic, so
           is_callable() is true for every model regardless. */
        if (!method_exists($modelClass, 'warrantSchema')) {
            return null;
        }

        /* Before the static call below, so a non-static declaration gets this
           explanation rather than PHP's "cannot be called statically". */
        $this->assertWarrantSchemaIsStatic($modelClass);

        return $modelClass::warrantSchema();
    }

    /**
     * Assert that a schema and its model name each other, starting from whichever
     * end of the pair the caller was given.
     *
     * Direction matters, and is taken from the reference rather than inferred:
     *
     *  - given a **schema**, the model it names must name that schema back;
     *  - given a **model**, the schema it names must name that model back.
     *
     * They are not interchangeable. A model subclass inherits `warrantSchema()`, so
     * `PublishedPost extends Post` names `PostSchema` while `PostSchema` names
     * `Post` — consistent read from the schema end, wrong from the model end. Only
     * the model direction catches it, which is why the reference decides.
     *
     * @param  class-string<WarrantSchema>|class-string<Model>  $reference  The end
     *   of the pair the caller was handed.
     * @param  string|null  $registeredAs  The schema key $reference is registered
     *   under, when it came from the index, so this can report a config entry that
     *   is not a schema at all. Null for a reference that is not registered — a
     *   model, for instance.
     */
    private function assertSchemaAndModelNameEachOther(string $reference, ?string $registeredAs = null): void
    {
        if (isset($this->verified[$reference])) {
            return;
        }

        $isSchema = is_a($reference, WarrantSchema::class, true);

        /* Anything in the index claims to be a schema. The claim could not be
           checked when the index was built, because checking it loads the class. */
        if ($registeredAs !== null && !$isSchema) {
            throw new LogicException(sprintf(
                'Schema key [%s] is registered to [%s], which is not a %s.',
                $registeredAs,
                $reference,
                WarrantSchema::class,
            ));
        }

        $isSchema
            ? $this->assertSchemasModelNamesItBack($reference)
            : $this->assertModelsSchemaNamesItBack($reference);

        $this->verified[$reference] = true;
    }

    /**
     * The schema direction: the model named by `$schemaClass::model` must name
     * `$schemaClass` back. A capability schema names no model, so there is no pair
     * to check.
     *
     * @param  class-string<WarrantSchema>  $schemaClass
     */
    private function assertSchemasModelNamesItBack(string $schemaClass): void
    {
        $modelClass = $schemaClass::model;

        if ($modelClass === '') {
            return;
        }

        if (!is_a($modelClass, Model::class, true)) {
            throw new LogicException(sprintf(
                'Schema [%s] names model [%s], which is not an Eloquent model.',
                $schemaClass,
                $modelClass,
            ));
        }

        if (!method_exists($modelClass, 'warrantSchema')) {
            throw new LogicException(sprintf(
                'Schema [%s] names model [%s], but that model does not use the %s trait, '
                    .'so Warrant cannot resolve the model back to its schema.',
                $schemaClass,
                $modelClass,
                HasWarrantSchema::class,
            ));
        }

        $this->assertWarrantSchemaIsStatic($modelClass);

        $declaredSchema = $modelClass::warrantSchema();

        if ($declaredSchema !== $schemaClass) {
            throw new LogicException(sprintf(
                'Schema [%s] names model [%s], but that model names schema [%s]; '
                    .'a schema and its model must name each other.',
                $schemaClass,
                $modelClass,
                $declaredSchema,
            ));
        }
    }

    /**
     * The model direction: the schema named by `$modelClass::warrantSchema()` must
     * name `$modelClass` back. This is the direction that catches a model subclass
     * inheriting its parent's schema.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function assertModelsSchemaNamesItBack(string $modelClass): void
    {
        /* Reached only via schemaDeclaredBy(), which has already confirmed the
           method exists and is static. */
        $schemaClass = $modelClass::warrantSchema();

        if (!is_a($schemaClass, WarrantSchema::class, true)) {
            throw new LogicException(sprintf(
                'Model [%s] must name a %s, but names [%s].',
                $modelClass,
                WarrantSchema::class,
                $schemaClass,
            ));
        }

        if ($schemaClass::model !== $modelClass) {
            throw new LogicException(sprintf(
                'Model [%s] names schema [%s], but that schema names model [%s]; '
                    .'a schema and its model must name each other.',
                $modelClass,
                $schemaClass,
                $schemaClass::model === '' ? 'none' : $schemaClass::model,
            ));
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function assertWarrantSchemaIsStatic(string $modelClass): void
    {
        if (isset($this->staticChecked[$modelClass])) {
            return;
        }

        $this->staticChecked[$modelClass] = true;

        /* A model written against the pre-static trait declares warrantSchema()
           as an instance method, which cannot be called statically. */
        if (!(new ReflectionMethod($modelClass, 'warrantSchema'))->isStatic()) {
            throw new LogicException(sprintf(
                'Model [%s] must declare warrantSchema() as `public static`.',
                $modelClass,
            ));
        }
    }
}
