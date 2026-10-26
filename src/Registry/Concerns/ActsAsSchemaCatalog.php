<?php

namespace Warrant\Registry\Concerns;

use InvalidArgumentException;
use Warrant\DSL\ISchemaCatalog;
use Warrant\DSL\Resolution\ConstructLookup;
use Warrant\Registry\SchemaRegistry;
use Warrant\Schema\Types\Interfaces\IGlobalSchema;
use Warrant\Schema\Vocabulary;

/**
 * Implements {@see ISchemaCatalog} for the {@see SchemaRegistry}: each schema's
 * vocabulary read from the schema the registry builds, once, when it is first
 * asked for, and one {@see ConstructLookup} kept for every caller.
 *
 * @internal
 */
trait ActsAsSchemaCatalog
{
    /**
     * Each registered schema's vocabulary read so far, by key: null for a key no
     * schema is registered under.
     *
     * @var array<string, Vocabulary|null>
     */
    private array $vocabularies = [];

    /**
     * Each global schema's vocabulary read so far, by class.
     *
     * @var array<class-string<IGlobalSchema>, Vocabulary>
     */
    private array $globalVocabularies = [];

    private ?ConstructLookup $constructs = null;

    private bool $globalClassesVerified = false;

    /**
     * The global schemas' classes, in the order a name is looked up in them.
     *
     * The first reading checks that each implements {@see IGlobalSchema}, which
     * loads every global class but builds none, so the list holds only global
     * schemas, as the catalog promises.
     *
     * @return list<class-string<IGlobalSchema>>
     */
    public function globalClasses(): array
    {
        if (! $this->globalClassesVerified) {
            foreach ($this->globalClasses as $class) {
                if (! is_a($class, IGlobalSchema::class, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Schema [%s] is listed under `global`, but does not implement %s.',
                        $class,
                        IGlobalSchema::class,
                    ));
                }
            }

            $this->globalClassesVerified = true;
        }

        return $this->globalClasses;
    }

    /**
     * The vocabulary of the schema registered under $schemaKey, read once, or
     * null when no schema is. Reading it builds the schema.
     */
    public function find(string $schemaKey): ?Vocabulary
    {
        if (! array_key_exists($schemaKey, $this->vocabularies)) {
            $resolved = $this->resolveOrNull($schemaKey);

            $this->vocabularies[$schemaKey] = $resolved === null ? null : Vocabulary::of($resolved->schema);
        }

        return $this->vocabularies[$schemaKey];
    }

    public function findGlobal(string $class): Vocabulary
    {
        return $this->globalVocabularies[$class] ??= Vocabulary::of($this->globalSchema($class));
    }

    public function constructs(): ConstructLookup
    {
        return $this->constructs ??= new ConstructLookup($this);
    }
}
