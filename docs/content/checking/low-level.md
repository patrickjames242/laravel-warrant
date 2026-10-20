---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Low-level access
description: compileGate, the decision enum, and reaching the predicate before it becomes SQL.
sidebar:
  order: 9
---

The high-level helpers cover almost everything. When they do not, the layer under
them is available, and it is the layer to build tooling on.

## The three query builders

```php
$guard = Warrant::forSchema(Document::class, $user);

$guard->filterQuery(
    $query,
    'view',
    AbilityMatchMode::ALL,
    $context = [],
);

$guard->selectAbilitiesInQuery(
    $query,
    selectedAbilitiesKey: 'abilities',
    onlyAbilities: null,
    context: [],
);

$guard->getAbilitiesWithoutTarget(
    abilities: null,
    matchMode: AbilityMatchMode::ANY,
    context: [],
);
```

The model scopes delegate to the first two. Call them directly when you hold a raw
query builder, or when the schema has no model.

## The compiled predicate, before SQL

`filterQuery()` is a consumer of one step below it: compiling the gate into a where
clause. That clause is often not a clause at all. An unconditional `cannot`, an
ability nobody granted, an unconditional `can`, or anything gated on a row
condition with no row all settle the question without reference to a row.

```php
$gate = $guard->compileGate(
    $query,
    'view',
    AbilityMatchMode::ALL,
    $context = [],
    $targetModel = null,
);

$gate->decision();          // a Decision
$gate->toQuery();           // the predicate as SQL
$gate->spliceInto($query);  // attach it to a host query, returning the host
```

`decision()` returns a `Warrant\DSL\Compiling\Decision`:

| Case | Meaning |
| --- | --- |
| `True` | the rules granted without consulting a row |
| `False` | the rules denied |
| `Unknown` | the compile reached a question it could not answer |
| `NeedsQuery` | not settled here; ask it in SQL |

```php
$gate->grants();       // true for Decision::True alone
$gate->isConstant();   // false for Decision::NeedsQuery alone
```

`grants()` being true only for `True` is what lets a caller wanting a plain yes or
no ignore the difference between `False` and `Unknown`, since both deny.

Read `decision()` first and you can skip the query entirely. Call `spliceInto()` on
the same result when you do need SQL, and nothing is compiled twice:

```php
$gate = $guard->compileGate($query, 'view');

if ($gate->isConstant()) {
    return $gate->grants() ? $everything : collect();
}

return $gate->spliceInto($query)->get();
```

## Why the helpers differ here

`filterQuery()` always needs SQL, so it spells a constant out as `1 = 1`, `1 = 0`,
or `null`. A row filter has to say something.

The boolean checks do not. `can()`, `canAny()`, `cannot()`, and the `authorize`
pair read the constant and return without querying.

## Reading the rules in effect

```php
$guard->expandedRuleSet();   // what your provider returned, plus schema rules
```

Memoized per guard, validated once. This is the entry point for debugging and for
anything that wants to introspect policy rather than evaluate it. The rules are the
[expanded](/sql/rule-to-query/#before-compiling-expansion) set every check
compiles: ability blocks opened, includes replaced by their templates' rules,
derived conditions by their expressions, and binding values put in place. Each
rule still says where it was written, through its `location`:

```php
$expanded = $guard->expandedRuleSet();   // an ExpandedSyntax

foreach ($expanded->rules as $rule) {
    $rule->canAbilities();
    $rule->cannotAbilities();
    $rule->conditions;            // null for an unconditional rule
    $rule->messageFor('update');
}
```

## Rendering it back to text

Writing back to the language goes through a validated `WarrantSyntax`, the root a
parse returns. Wrap the nodes you hold in one:

```php
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

$syntax = (new WarrantSyntax($guard->expandedRuleSet()->rules))->validateWithoutSchemas();

$syntax->toSyntax();        // inline literals
$syntax->toBoundSyntax();   // parameterized: ->syntax, plus ->bindings by name
```

`toSyntax()` throws on anything with no inline form, such as an array parameter or
a closure denial message. `toBoundSyntax()` always works. A derived condition is
written as the call the rule made, and a parsed tree's ability blocks and
`@include`s are written back as they were written.

## What to build on this

Good uses: an admin UI that shows the effective policy for a user, a CI check that
diffs rule sets between deploys, a debug endpoint dumping `expandedRuleSet()` for a
support ticket, a report of which abilities are reachable for each role.

Less good: re-implementing a check. The high-level helpers exist because there are
more edges than there look, particularly around constants, hydrated models, and
existence.
