<?php

namespace Warrant\LanguageServer\Vocabulary;

use OutOfBoundsException;
use Warrant\DSL\ISchemaCatalog;
use Warrant\DSL\Resolution\ConstructLookup;
use Warrant\Schema\Vocabulary;

/**
 * Every schema the language server knows of, by key: the catalog rule text is
 * validated against, and what completion lists.
 */
final readonly class VocabularyIndex implements ISchemaCatalog
{
    /**
     * @param array<string, IndexedSchema> $schemas By key.
     */
    public function __construct(public array $schemas = [])
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

    /**
     * The language server does not index global schemas, so it knows of none.
     */
    public function globalClasses(): array
    {
        return [];
    }

    public function findGlobal(string $class): Vocabulary
    {
        throw new OutOfBoundsException(sprintf('The index holds no global schema [%s].', $class));
    }

    public function constructs(): ConstructLookup
    {
        return new ConstructLookup($this);
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
        return $this->schemas === [];
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
     * locations and descriptions filling in what $preferred does not know.
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

        return new self($schemas);
    }
}
