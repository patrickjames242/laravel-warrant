<?php

namespace Warrant;

use Attribute;

/**
 * Marks a schema constant's value as a check-time context key that is
 * **required on every check** against the schema. Any check whose effective
 * context (explicitly passed, or supplied by `defaultContext()`) omits the key
 * throws up front — so an ambient frame (a tenant id, say) can never be silently
 * skipped, which would lift a context-gated `cannot`.
 *
 * Context keys do NOT need declaring to be *used*: a rule may reference any
 * `@context <key>` and a condition may read `$c->context['<key>']` freely. This
 * attribute is only about making a key mandatory schema-wide. For a key that is
 * mandatory only when a particular ability is checked, use
 * `#[Ability(requiredContext: [...])]` instead.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class RequiredContext {}
