<?php

namespace Warrant\DSL;

use Warrant\Schema\Vocabulary;

/**
 * The schemas rule text may name, found by key: what a `for <schema>` header, a
 * `can(... for <schema>)` or a `check(... for <schema>)` refers to.
 *
 * Validation looks schemas up only through this, so it runs wherever a catalog
 * can be had: against the application's registry at runtime
 * ({@see \Warrant\Registry\RegisteredSchemas}), or against an index of the
 * project's schemas in tooling that does not boot the application.
 */
interface ISchemaCatalog
{
    /**
     * The vocabulary of the schema registered under $schemaKey, or null when no
     * schema is.
     */
    public function find(string $schemaKey): ?Vocabulary;
}
