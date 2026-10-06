---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: How a decision is made
description: The path from a question to an answer, step by step.
sidebar:
  order: 5
---

One check, start to finish. The path is the same whether the answer is a boolean,
a filtered list, or a per-row ability column.

```php
Warrant::can('update', $document, ['workspace_id' => 'ws-1']);
```

**1. Resolve the schema.** The target names it. A model instance resolves through
`warrantSchema()`, a class string or schema key through the registry. An
unregistered reference throws here.

**2. Get a guard.** A guard is fixed to one user and one schema, and it is
memoized for the request. Every later check on the same pair reuses it.

**3. Resolve the rule set, once.** The guard calls your resolver, checks that the
returned set targets this schema, merges the schema's
[implicit rules](/schemas/schema-policy/), and validates every ability and
condition name against the schema. This happens at most once per user and schema
per request, no matter how many checks follow.

```php
$guard = Warrant::forSchema(Document::class, $user);

$guard->resolvedRuleSet();   // the merged, validated set, for debugging
```

**4. Build the effective context.** Whatever the caller passed is merged over
`defaultContext()`, with explicit values winning. Any key marked
`#[RequiredContext]`, or required by the specific ability, must be present now or
the check throws.

**5. Gather the rules for the ability.** Every rule mentioning `update` or `*`,
and no others.

**6. Compile one predicate.** The grants `OR` together; each denial contributes its
negation to an `AND`. Negation is pushed to the leaves by De Morgan, so a `not`
lands on a single condition rather than a group.

**7. Evaluate what can be evaluated in PHP.** A global condition returns a `bool`.
A row condition handed the actual loaded row may too. Those are constants, and
constants fold: `false AND x` becomes `false`, `true OR x` becomes `true`, and the
rest is never emitted.

**8. Decide whether SQL is needed at all.** If the predicate folded to a constant,
there may be nothing to ask:

```php
$gate = $guard->compileGate($query, 'update');

$gate->decision();   // True | False | Unknown | NeedsQuery
```

`True` with a hydrated model answers immediately, because a hydrated model has
already established that the row exists. `False` always short-circuits, since no
row could pass. Anything unproven, such as a bare key or an unsaved model, still
costs one lookup.

**9. Otherwise run it.** As an `EXISTS` for a boolean check, as a `WHERE` for a
filter, or as a correlated subquery per ability for the ability column.

## When the answer is no

`can` stops at `false`. `authorize` goes one step further and asks why, by walking
the rules in resolver order and finding the first message-bearing `cannot` whose
condition actually matched. Diagnosis runs the same condition SQL as the check, so
it can never blame a rule that did not fire.

If nothing forbade the user and nothing granted them either, there is no rule to
blame, and the schema's `ungrantedDenialMessage()` gets the question instead. The
precedence is in [denial messages](/rules/denial-messages/).

## The part that is not a decision

[Reachability](/concepts/reachability/) does not follow this path at all. It reads
the rule set without a row: it evaluates no row or global condition, takes no
context, and runs no SQL.
It answers whether a grant is conceivable, not whether it holds.
