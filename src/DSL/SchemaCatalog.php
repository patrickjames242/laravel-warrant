<?php

namespace Warrant\DSL;

/**
 * The schemas rule text may name, found by key: what a `for <schema>` header, a
 * `can(... for <schema>)` or a `check(... for <schema>)` refers to.
 *
 * Validation and expansion look schemas up only through this, so they run
 * wherever a catalog can be had: against the application's registry at runtime
 * ({@see \Warrant\Registry\RegisteredSchemas}), or against an index of the
 * project's schemas in tooling that does not boot the application.
 */
interface SchemaCatalog
{
    /**
     * The vocabulary of the schema registered under $schemaKey, or null when no
     * schema is.
     */
    public function find(string $schemaKey): ?SchemaVocabulary;
}
