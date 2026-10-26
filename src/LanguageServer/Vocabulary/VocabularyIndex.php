<?php

namespace Warrant\LanguageServer\Vocabulary;

use OutOfBoundsException;
use Warrant\DSL\ISchemaCatalog;
use Warrant\DSL\Resolution\ConstructLookup;
use Warrant\DSL\Resolution\IFoundConstruct;
use Warrant\DSL\Resolution\ISchemaId;
use Warrant\DSL\Resolution\KeyedSchemaId;
use Warrant\Schema\Vocabulary;

/**
 * Every schema the language server knows of: the keyed ones by key, and the
 * global ones in the order a name is looked up in them. It is the catalog rule
 * text is validated against, and what completion lists.
 *
 * Only a class known to be a global schema is held as one: the reading from
 * source holds a class whose `extends` chain reaches GlobalSchema, and the
 * reading from the application one that implements IGlobalSchema.
 */
final readonly class VocabularyIndex implements ISchemaCatalog
{
    /**
     * @param array<string, IndexedSchema> $schemas By key.
     * @param list<IndexedSchema> $globals The global schemas, in order.
     */
    public function __construct(public array $schemas = [], public array $globals = [])
    {
    }

    /**
     * What rule text may say about the schema registered under $schemaKey, or null
     * when the index has no such schema.
     *
     * @param string $schemaKey A schema key.
     */
    public function find(string $schemaKey): ?Vocabulary
    {
        return $this->schema($schemaKey)?->vocabulary;
    }

    public function globalClasses(): array
    {
        return array_map(static fn (IndexedSchema $schema): string => $schema->className, $this->globals);
    }

    public function findGlobal(string $class): Vocabulary
    {
        return $this->globalSchema($class)?->vocabulary
            ?? throw new OutOfBoundsException(sprintf('The index holds no global schema [%s].', $class));
    }

    /**
     * Everything the index knows of the global schema $class, or null when it
     * holds no such global.
     *
     * @param string $class A class name, with or without its leading backslash.
     */
    public function globalSchema(string $class): ?IndexedSchema
    {
        foreach ($this->globals as $schema) {
            if (strcasecmp($schema->className, ltrim($class, '\\')) === 0) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * The schemas a name written in $writtenIn is looked up in, in order: a keyed
     * schema, when the index holds it, then the global schemas; or, for text a
     * global schema answered with, the global schemas alone.
     *
     * @return list<IndexedSchema>
     */
    public function searchedFrom(ISchemaId $writtenIn): array
    {
        $own = $writtenIn instanceof KeyedSchemaId ? $this->schema($writtenIn->key) : null;

        return $own === null ? $this->globals : [$own, ...$this->globals];
    }

    /**
     * Everything the index knows of the schema $found was found in: a keyed
     * schema by its key, or a global by its class.
     */
    public function declaring(IFoundConstruct $found): ?IndexedSchema
    {
        $declaredIn = $found->declaredIn();

        return $declaredIn instanceof KeyedSchemaId
            ? $this->schema($declaredIn->key)
            : $this->globalSchema($found->schemaClass());
    }

    /**
     * A lookup that goes on when two global schemas share a name, the global
     * listed first answering: the editor reports each such name where it is
     * declared, and still checks the rest of the text.
     */
    public function constructs(): ConstructLookup
    {
        return new ConstructLookup($this, refuseDuplicateGlobalNames: false);
    }

    /**
     * Everything the index knows of the schema registered under $schemaKey, where
     * its names are declared included, or null when it has no such schema.
     *
     * @param string $schemaKey A schema key.
     */
    public function schema(string $schemaKey): ?IndexedSchema
    {
        return $this->schemas[$schemaKey] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->schemas === [] && $this->globals === [];
    }

    /**
     * The key of the schema declared by $className, or null when it is not a
     * schema this index knows.
     *
     * @param string $className A class name, with or without its leading
     *   backslash.
     */
    public function keyOfClass(string $className): ?string
    {
        $className = ltrim($className, '\\');

        foreach ($this->schemas as $key => $schema) {
            if (strcasecmp($schema->className, $className) === 0) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The schemas of $preferred, and every schema of $fallback it does not have.
     * A schema in both keeps $preferred's names and definitions, with $fallback's
     * locations and descriptions filling in what $preferred does not know. The
     * global schemas are $preferred's list when it has one, filled in the same
     * way, since the application reads the config exactly.
     *
     * @param VocabularyIndex $preferred The more exact reading, from the running
     *   application.
     * @param VocabularyIndex $fallback The reading from source.
     */
    public static function merge(self $preferred, self $fallback): self
    {
        $schemas = $fallback->schemas;

        foreach ($preferred->schemas as $key => $schema) {
            $schemas[$key] = isset($fallback->schemas[$key]) ? $schema->filledFrom($fallback->schemas[$key]) : $schema;
        }

        $globals = $preferred->globals === []
            ? $fallback->globals
            : array_map(
                static fn (IndexedSchema $global): IndexedSchema => ($from = $fallback->globalSchema($global->className)) === null
                    ? $global
                    : $global->filledFrom($from),
                $preferred->globals,
            );

        return new self($schemas, $globals);
    }
}
