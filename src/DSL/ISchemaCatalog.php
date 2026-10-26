<?php

namespace Warrant\DSL;

use Warrant\DSL\Resolution\ConstructLookup;
use Warrant\Schema\Types\Interfaces\IGlobalSchema;
use Warrant\Schema\Vocabulary;

/**
 * The schemas rule text may name, found by key: what a `for <schema>` header, a
 * `can(... for <schema>)` or a `check(... for <schema>)` refers to, and the
 * global schemas whose names any rule may use, found by class, in order.
 *
 * Validation looks schemas up only through this, so it runs wherever a catalog
 * can be had: against the application's registry at runtime
 * ({@see \Warrant\Registry\SchemaRegistry}), or against an index of the
 * project's schemas in tooling that does not boot the application.
 */
interface ISchemaCatalog
{
    /**
     * The vocabulary of the schema registered under $schemaKey, or null when no
     * schema is.
     */
    public function find(string $schemaKey): ?Vocabulary;

    /**
     * The global schemas' classes, in the order a name is looked up in them.
     *
     * @return list<class-string<IGlobalSchema>>
     */
    public function globalClasses(): array;

    /**
     * The vocabulary of the global schema $class, one of {@see globalClasses()}.
     *
     * @param class-string<IGlobalSchema> $class
     */
    public function findGlobal(string $class): Vocabulary;

    /**
     * What names in rule text mean, looked up in this catalog's schemas.
     */
    public function constructs(): ConstructLookup;
}
