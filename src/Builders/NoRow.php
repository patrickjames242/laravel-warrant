<?php

namespace Warrant\Builders;

/**
 * The sentinel default for a cross-schema handle's row selector, marking "no row
 * selector at all".
 *
 * `can(view for folders)` and `can(view for folders(null))` are different rules:
 * the first asks a schema-wide question, the second is a mistake
 * {@see \Warrant\DSL\Parsing\Validation\RuleSetValidator} rejects loudly. A plain
 * `null` default could not tell them apart, so an accidental null id — a missing
 * `$folder?->id` — would silently widen a row check into a schema-wide one. Hence
 * a distinct value, which is also what makes `row: $id ?? new NoRow` expressible.
 *
 * Match it with `instanceof`, never `===`: every evaluation of the default
 * argument makes its own instance.
 *
 * @see \Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode::$isRowBound
 */
final class NoRow
{
}
