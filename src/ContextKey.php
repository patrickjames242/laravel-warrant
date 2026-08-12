<?php

namespace Warrant;

use Attribute;

/**
 * Declares a check-time context key on a schema. The constant's *value* is the
 * key string a rule references with `@context <key>` and that callers supply in
 * the `context:` bag; the constant's name is irrelevant to Warrant (mirroring
 * `#[Ability]`).
 *
 * Keys are **required by default**: any check on the schema throws unless the
 * key is present in the effective context (explicitly passed or supplied by
 * `defaultContext()`). Pass `required: false` for an optional frame — but only
 * for one that never gates a `cannot`, since a missing optional key silently
 * lifts a deny rather than failing loud.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class ContextKey
{
    public function __construct(public bool $required = true) {}
}
